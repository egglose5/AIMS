using System.Net;
using System.Text.RegularExpressions;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record ScoutDiscoveryRunResult(
    int Searches,
    int Added,
    int KnownShowSuppressed,
    int PreviouslyStagedSuppressed,
    int SameRunDuplicateSuppressed,
    int HardExcluded,
    int ParsedLinks,
    int CandidateLinks,
    string Message);

public interface IScoutDiscoveryService
{
    Task<ScoutDiscoveryRunResult> DiscoverAsync(
        long? vendorProfileId,
        int targetYear,
        int targetMonth,
        string? region,
        int maxNewLeads = 300,
        CancellationToken cancellationToken = default);
}

/// <summary>
/// Scout S1.27: Phase-1 hardening/closure; persistent batch capacity and source-contribution diagnostics.
/// Discovery maximizes coverage; S2 performs deep research and scoring.
/// </summary>
public sealed class ScoutDiscoveryService : IScoutDiscoveryService
{
    private readonly ApplicationDbContext _db;
    private readonly IHttpClientFactory _httpClientFactory;

    private static readonly Regex TagRegex = new("<[^>]+>", RegexOptions.Compiled);
    private static readonly Regex WhitespaceRegex = new(@"\s+", RegexOptions.Compiled);

    public ScoutDiscoveryService(ApplicationDbContext db, IHttpClientFactory httpClientFactory)
    {
        _db = db;
        _httpClientFactory = httpClientFactory;
    }

    public async Task<ScoutDiscoveryRunResult> DiscoverAsync(
        long? vendorProfileId,
        int targetYear,
        int targetMonth,
        string? region,
        int maxNewLeads = 300,
        CancellationToken cancellationToken = default)
    {
        targetYear = Math.Clamp(targetYear, DateTime.UtcNow.Year, DateTime.UtcNow.Year + 5);
        targetMonth = Math.Clamp(targetMonth, 0, 12);
        maxNewLeads = Math.Clamp(maxNewLeads, 50, 500);
        region = string.IsNullOrWhiteSpace(region) ? "Midwest" : region.Trim();

        var vendor = vendorProfileId is null
            ? null
            : await _db.ShowVendorProfiles.AsNoTracking()
                .FirstOrDefaultAsync(x => x.Id == vendorProfileId, cancellationToken);

        var existingLeads = await _db.ShowDiscoveryLeads.AsNoTracking()
            .Where(x => x.TargetYear == targetYear &&
                        x.Status != "SCOUT_ARCHIVED_TEST")
            .Select(x => new { x.Url, x.Title, x.Status })
            .ToListAsync(cancellationToken);

        var existingActiveCount = existingLeads.Count(x => x.Status == "SCOUT_NEW" || x.Status == "NEW");
        var availableActiveSlots = Math.Max(0, maxNewLeads - existingActiveCount);

        var stagedUrlKeys = new HashSet<string>(
            existingLeads.Select(x => NormalizeUrl(x.Url)),
            StringComparer.OrdinalIgnoreCase);

var canonicalShows = await _db.ShowEvents.AsNoTracking()
            .Select(x => new { x.Id, x.Name })
            .ToListAsync(cancellationToken);

        var canonicalTitleKeys = new HashSet<string>(
            canonicalShows.Select(x => NormalizeTitle(x.Name)),
            StringComparer.OrdinalIgnoreCase);

        // Same-run duplicate tracking is separate from persisted staging/canonical suppression.
        var runUrlKeys = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var runIdentityKeys = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

        var plans = BuildSearchPlans(targetYear, targetMonth, region);
        var allHits = new List<SearchHit>();

        // Search concurrently so a full Midwest pass does not take forever.
        using var gate = new SemaphoreSlim(12);
        var tasks = plans.Select(async plan =>
        {
            await gate.WaitAsync(cancellationToken);
            try
            {
                return await SearchAsync(plan, 20, cancellationToken);
            }
            finally
            {
                gate.Release();
            }
        }).ToList();

        var batches = await Task.WhenAll(tasks);
        foreach (var batch in batches) allHits.AddRange(batch.Hits);

        // S1.17: seed every requested state with deterministic festival-directory lanes.
        // These are source pages only; they never become canonical shows. Search remains date-blind.
        allHits.AddRange(BuildStateSourceSeeds(region));

        // A useful directory/calendar page is a SOURCE, not automatically a SHOW.
        // Crawl accepted source pages and extract event-detail links before final staging.
        var sourceSeeds = allHits
            .Where(IsCrawlableSourceSeed)
            .Where(x => x.Source == "STATE_SOURCE" || IsSourceSeedInRequestedGeography(x, region))
            .GroupBy(x => NormalizeUrl(x.Url), StringComparer.OrdinalIgnoreCase)
            // If search also found the deterministic URL, preserve STATE_SOURCE identity.
            .Select(g => g.OrderByDescending(x => x.Source == "STATE_SOURCE").First())
            // State lanes are guaranteed a seat before opportunistic search-discovered sources.
            .OrderByDescending(x => x.Source == "STATE_SOURCE")
            .ThenBy(x => x.Source)
            .Take(120)
            .ToList();

        var crawlBatches = await CrawlSourceSeedsAsync(sourceSeeds, region, cancellationToken);

        // S1.22: recovery is real, not just a diagnostic flag. If a deterministic state lane
        // produces zero event links, try curated alternate state sources in the same run.
        var zeroYieldPrimaryStates = crawlBatches
            .Where(x => x.EventLinksAccepted == 0)
            .Select(x => DetectStateFromText(x.SourceUrl))
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Cast<string>()
            .ToList();
        var recoverySeeds = BuildRecoverySeeds(zeroYieldPrimaryStates)
            .Where(x => IsSourceSeedInRequestedGeography(x, region))
            .Where(x => !IsBlockedCrawlSource(x.Url))
            .GroupBy(x => NormalizeUrl(x.Url), StringComparer.OrdinalIgnoreCase)
            .Select(x => x.First())
            .ToList();
        if (recoverySeeds.Count > 0)
        {
            sourceSeeds.AddRange(recoverySeeds);
            var recoveryBatches = await CrawlSourceSeedsAsync(recoverySeeds, region, cancellationToken);
            crawlBatches.AddRange(recoveryBatches);
        }

        var crawledHits = crawlBatches.SelectMany(x => x.Hits).ToList();

        // Source seed pages do not become show candidates merely because they are directories/calendars.
        allHits = allHits
            .Where(x => !IsCrawlableSourceSeed(x))
            .Concat(crawledHits)
            .ToList();

        var sourcePagesCrawled = crawlBatches.Count;
        var sourcePagesFetched = crawlBatches.Count(x => x.FetchSucceeded);
        var deterministicStateSeeds = sourceSeeds.Count(x => x.Source == "STATE_SOURCE");
        var sourceLinksParsed = crawlBatches.Sum(x => x.LinksParsed);
        var sourceEventLinksAccepted = crawlBatches.Sum(x => x.EventLinksAccepted);

        var searchesWithHits = batches.Count(x => x.Hits.Count > 0);
        var searchesWithoutHits = batches.Length - searchesWithHits;
        var parsedLinksTotal = batches.Sum(x => x.ParsedLinks);
        var candidateLinksTotal = batches.Sum(x => x.CandidateLinks);

        var parsedLinksBySource = batches
            .GroupBy(x => x.Source)
            .OrderBy(x => x.Key)
            .ToDictionary(x => x.Key, x => x.Sum(y => y.ParsedLinks), StringComparer.OrdinalIgnoreCase);

        var candidateLinksBySource = batches
            .GroupBy(x => x.Source)
            .OrderBy(x => x.Key)
            .ToDictionary(x => x.Key, x => x.Sum(y => y.CandidateLinks), StringComparer.OrdinalIgnoreCase);

        var rejectionTotals = batches
            .SelectMany(x => x.Rejections)
            .GroupBy(x => x.Key, StringComparer.OrdinalIgnoreCase)
            .OrderByDescending(x => x.Sum(y => y.Value))
            .ToDictionary(x => x.Key, x => x.Sum(y => y.Value), StringComparer.OrdinalIgnoreCase);

        var resolutionSamples = batches
            .SelectMany(x => x.ResolutionSamples)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Take(8)
            .ToList();

        var rawHitsBySource = allHits
            .GroupBy(x => x.Source)
            .OrderBy(x => x.Key)
            .ToDictionary(x => x.Key, x => x.Count(), StringComparer.OrdinalIgnoreCase);

        var rawHitsTotal = allHits.Count;

        var added = 0;
        var queued = 0;
        var qualified = 0;
        var knownShows = 0;
        var previouslyStaged = 0;
        var sameRunDuplicates = 0;
        var excluded = 0;
        var newlyStagedBySource = new Dictionary<string, int>(StringComparer.OrdinalIgnoreCase);

        foreach (var hit in allHits)
        {

            var title = CleanTitle(hit.Title);
            var titleKey = NormalizeTitle(title);
            var urlKey = NormalizeUrl(hit.Url);

            if (HardExclude(hit.Title, hit.Snippet, hit.Url))
            {
                excluded++;
                continue;
            }

            if (string.IsNullOrWhiteSpace(titleKey) || string.IsNullOrWhiteSpace(urlKey))
            {
                excluded++;
                continue;
            }

            var identityKey = BuildIdentityKey(title, hit.Url);

            // 1) Already a canonical Show Arm event: suppress as KNOWN SHOW.
            if (canonicalTitleKeys.Contains(titleKey))
            {
                knownShows++;
                continue;
            }

            // 2) Already staged in Scout from an earlier run: suppress as PREVIOUSLY STAGED.
            if (stagedUrlKeys.Contains(urlKey))
            {
                previouslyStaged++;
                continue;
            }

            // 3) Duplicate hit in THIS run: only collapse when URL or stronger event identity matches.
            if (runUrlKeys.Contains(urlKey))
            {
                sameRunDuplicates++;
                continue;
            }

            runUrlKeys.Add(urlKey);
            runIdentityKeys.Add(identityKey);

            var yearSignal = DetectYearSignal($"{hit.Title} {hit.Snippet} {hit.Url}", targetYear);
            var snippet = string.IsNullOrWhiteSpace(hit.Snippet)
                ? $"Found by Scout via {hit.Source}. {yearSignal} Unresearched discovery lead."
                : $"{yearSignal} {hit.Snippet}";

            qualified++;
            var stagedStatus = added < availableActiveSlots ? "SCOUT_NEW" : "SCOUT_QUEUED";

            _db.ShowDiscoveryLeads.Add(new ShowDiscoveryLeadEntity
            {
                ShowVendorProfileId = vendorProfileId,
                TargetYear = targetYear,
                TargetMonth = targetMonth == 0 ? null : targetMonth,
                Title = Limit(title, 500),
                Url = Limit(hit.Url, 1600),
                Snippet = Limit(snippet, 1500),
                SearchQuery = Limit($"SCOUT:S1.22:{hit.Source}:{hit.Query}", 1000),
                Status = stagedStatus,
                DiscoveredAt = DateTimeOffset.UtcNow
            });

            if (stagedStatus == "SCOUT_NEW") added++;
            else queued++;
            newlyStagedBySource[hit.Source] = newlyStagedBySource.GetValueOrDefault(hit.Source) + 1;
        }

        if (qualified > 0)
            await _db.SaveChangesAsync(cancellationToken);

        var searchPathSummary = string.Join(", ",
            plans.GroupBy(x => x.Source)
                .OrderBy(x => x.Key)
                .Select(x => $"{x.Key} {x.Count()}"));

        var newContributionSummary = newlyStagedBySource.Count == 0
            ? "none"
            : string.Join(", ", newlyStagedBySource.OrderByDescending(x => x.Value).Select(x => $"{x.Key} {x.Value}"));

        var rawHitSummary = rawHitsBySource.Count == 0
            ? "none"
            : string.Join(", ", rawHitsBySource.Select(x => $"{x.Key} {x.Value}"));

        var parsedLinkSummary = parsedLinksBySource.Count == 0
            ? "none"
            : string.Join(", ", parsedLinksBySource.Select(x => $"{x.Key} {x.Value}"));

        var candidateLinkSummary = candidateLinksBySource.Count == 0
            ? "none"
            : string.Join(", ", candidateLinksBySource.Select(x => $"{x.Key} {x.Value}"));

        var rejectionSummary = rejectionTotals.Count == 0
            ? "none"
            : string.Join(", ", rejectionTotals.Select(x => $"{x.Key} {x.Value}"));

        var resolutionSampleSummary = resolutionSamples.Count == 0
            ? "none"
            : string.Join(" | ", resolutionSamples);

        var crawlSourceSummary = crawlBatches.Count == 0
            ? "none"
            : string.Join(", ",
                crawlBatches
                    .Where(x => x.EventLinksAccepted > 0)
                    .OrderByDescending(x => x.EventLinksAccepted)
                    .Take(10)
                    .Select(x => $"{ShortUrl(x.SourceUrl)} => {x.EventLinksAccepted}"));

        var requestedStates = ExpandGeography(region);
        var deterministicLaneStates = sourceSeeds
            .Where(x => x.Source == "STATE_SOURCE")
            .Select(DetectSeedState)
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Cast<string>()
            .ToHashSet(StringComparer.OrdinalIgnoreCase);

        // Coverage is outcome-based: any fetched source attributable to a requested state
        // with accepted event links satisfies that state, including successful recovery.
        var successfulStateYield = crawlBatches
            .Where(x => x.EventLinksAccepted > 0)
            .Select(x => DetectStateFromText(x.SourceUrl))
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Cast<string>()
            .GroupBy(x => x, StringComparer.OrdinalIgnoreCase)
            .ToDictionary(g => g.Key, g => crawlBatches
                .Where(b => string.Equals(DetectStateFromText(b.SourceUrl), g.Key, StringComparison.OrdinalIgnoreCase))
                .Sum(b => b.EventLinksAccepted), StringComparer.OrdinalIgnoreCase);
        var satisfiedStates = successfulStateYield.Keys.ToHashSet(StringComparer.OrdinalIgnoreCase);
        var missingStateLanes = requestedStates.Where(x => !deterministicLaneStates.Contains(x)).ToList();
        var missingStateLaneSummary = missingStateLanes.Count == 0 ? "none" : string.Join(", ", missingStateLanes);

        var primaryZeroStates = crawlBatches
            .Where(x => x.EventLinksAccepted == 0)
            .Select(x => DetectStateFromText(x.SourceUrl))
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Cast<string>()
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();
        var recoveredStates = primaryZeroStates.Where(s => satisfiedStates.Contains(s)).ToList();
        var unresolvedZeroStates = primaryZeroStates.Where(s => !satisfiedStates.Contains(s)).ToList();
        var recoveredSummary = recoveredStates.Count == 0 ? "none" : string.Join(", ", recoveredStates.Select(s => $"{s} ({successfulStateYield.GetValueOrDefault(s)} accepted)"));
        var zeroYieldStateSummary = unresolvedZeroStates.Count == 0 ? "none" : string.Join(", ", unresolvedZeroStates);
        var uncoveredStates = requestedStates.Where(s => !satisfiedStates.Contains(s)).ToList();
        var uncoveredSummary = uncoveredStates.Count == 0 ? "none" : string.Join(", ", uncoveredStates);

        var sourceYieldSummary = crawlBatches.Count == 0 ? "none" : string.Join(", ",
            crawlBatches.OrderByDescending(x => x.EventLinksAccepted)
                .Take(18)
                .Select(x => $"{ShortUrl(x.SourceUrl)} {x.EventLinksAccepted}/{x.LinksParsed} [{SourceLearningTierForReport(x, satisfiedStates)}]"));

        var scope = vendor is null ? "general discovery" : $"discovery for {vendor.VendorName}";

        return new ScoutDiscoveryRunResult(
            plans.Count,
            added,
            knownShows,
            previouslyStaged,
            sameRunDuplicates,
            excluded,
            parsedLinksTotal,
            candidateLinksTotal,
            $"Scout S1.27 THIS RUN: {plans.Count} supplemental web searches.  Searches with candidate hits: {searchesWithHits}; zero-hit searches: {searchesWithoutHits}. " +
            $"Links parsed before candidate filtering: {parsedLinksTotal}. Candidate links accepted by parser: {candidateLinksTotal}. Raw candidate hits staged for dedupe: {rawHitsTotal}. " +
            $"Qualified after dedupe/exclusions: {qualified}. Added to ACTIVE this run: {added}. Added to QUEUE this run: {queued}. Active slots available before run: {availableActiveSlots}. Already in Show Arm: {knownShows}. Previously staged before this run: {previouslyStaged}. " +
            $"Same-run duplicate hits: {sameRunDuplicates}. Hard-excluded: {excluded}. New persisted contribution by source: {newContributionSummary}. " +
            $"Search paths: {searchPathSummary}. Parsed links by source: {parsedLinkSummary}. Candidate links by source: {candidateLinkSummary}. Rejected links by reason: {rejectionSummary}. Accepted resolution samples: {resolutionSampleSummary}. " +
            $"Source crawl: seeds {sourceSeeds.Count} (deterministic state lanes {deterministicLaneStates.Count}/{requestedStates.Count}; missing deterministic lanes: {missingStateLaneSummary}; recovered states: {recoveredSummary}; unresolved zero-yield states: {zeroYieldStateSummary}; states without accepted yield: {uncoveredSummary}), fetched {sourcePagesFetched}/{sourcePagesCrawled}, child links parsed {sourceLinksParsed}, event-detail links accepted {sourceEventLinksAccepted}. Top crawl sources: {crawlSourceSummary}. Source yield accepted/parsed: {sourceYieldSummary}. Raw hits after source expansion by source: {rawHitSummary}. " +
            "S1.27 Phase-1 hardening: runtime=S1.27; batching=300+queue; state-attribution=outcome-based; recovery=state-aware-with-success; reseller-block=enabled; source-tiers=enabled. Nothing was added to the canonical Show Arm.");
    }

    private async Task<List<CrawlBatch>> CrawlSourceSeedsAsync(
        List<SearchHit> seeds,
        string region,
        CancellationToken cancellationToken)
    {
        if (seeds.Count == 0) return new List<CrawlBatch>();

        using var gate = new SemaphoreSlim(8);
        var tasks = seeds.Select(async seed =>
        {
            await gate.WaitAsync(cancellationToken);
            try { return await CrawlSourceSeedAsync(seed, region, cancellationToken); }
            finally { gate.Release(); }
        });

        return (await Task.WhenAll(tasks)).ToList();
    }

    private async Task<CrawlBatch> CrawlSourceSeedAsync(SearchHit seed, string region, CancellationToken cancellationToken)
    {
        var hits = new List<SearchHit>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var parsed = 0;
        var accepted = 0;

        try
        {
            var client = _httpClientFactory.CreateClient();
            client.Timeout = TimeSpan.FromSeconds(8);
            client.DefaultRequestHeaders.UserAgent.ParseAdd(
                "Mozilla/5.0 (compatible; AncientInnovationsScout/1.20)");

            var html = await client.GetStringAsync(seed.Url, cancellationToken);

            if (!Uri.TryCreate(seed.Url, UriKind.Absolute, out var baseUri))
                return new CrawlBatch(seed.Url, false, 0, 0, hits);

            var linkRx = new Regex(
                "<a[^>]*href=[\"'](?<url>[^\"'#]+)[\"'][^>]*>(?<title>.*?)</a>",
                RegexOptions.IgnoreCase | RegexOptions.Singleline);

            foreach (Match m in linkRx.Matches(html))
            {
                parsed++;
                if (accepted >= 80) break;

                var href = WebUtility.HtmlDecode(m.Groups["url"].Value).Trim();
                var title = CleanHtml(m.Groups["title"].Value);

                if (string.IsNullOrWhiteSpace(href) || string.IsNullOrWhiteSpace(title))
                    continue;

                if (!Uri.TryCreate(baseUri, href, out var target))
                    continue;

                if (target.Scheme != Uri.UriSchemeHttp && target.Scheme != Uri.UriSchemeHttps)
                    continue;

                var url = target.AbsoluteUri;
                var key = NormalizeUrl(url);
                if (string.IsNullOrWhiteSpace(key) || !seen.Add(key))
                    continue;

                var snippet = FindNearbySnippet(html, m.Index, m.Length);

                if (!IsChildEventInRequestedGeography(seed, title, url, snippet, region))
                    continue;

                if (!LooksLikeEventDetailFromSource(baseUri, target, title, snippet))
                    continue;

                accepted++;
                var qualification = BuildDiscoveryReason(title, snippet, url);
                var location = DetectLocationLabel(seed, title, url, snippet);
                hits.Add(new SearchHit(
                    "SOURCE_CRAWL",
                    $"SCOUT:S1.22:SOURCE:{seed.Source}:{seed.Url}",
                    title,
                    url,
                    $"Detected location: {location}. Source: {ShortUrl(seed.Url)}. Why Scout kept it: {qualification}. {snippet}"));
            }

            return new CrawlBatch(seed.Url, true, parsed, accepted, hits);
        }
        catch
        {
            return new CrawlBatch(seed.Url, false, parsed, accepted, hits);
        }
    }

    private static bool IsCrawlableSourceSeed(SearchHit hit)
    {
        if (!Uri.TryCreate(hit.Url, UriKind.Absolute, out var uri)) return false;

        var host = uri.Host.ToLowerInvariant();
        var path = uri.AbsolutePath.ToLowerInvariant();
        var text = $"{hit.Title} {path} {hit.Snippet}".ToLowerInvariant();

        // Known directory/event-platform sources.
        if (host.EndsWith("zapplication.org") ||
            host.EndsWith("eventeny.com") ||
            host.EndsWith("festivalnet.com") ||
            host.EndsWith("fairsandfestivals.net") ||
            host.EndsWith("sunshineartist.com") ||
            host.EndsWith("eventbrite.com"))
            return true;

        // Local/chamber/tourism/calendar/listing pages.
        return text.Contains("calendar") ||
               text.Contains("events") ||
               text.Contains("festivals") ||
               text.Contains("fairs") ||
               text.Contains("vendor") ||
               text.Contains("market");
    }

    private static string BuildDiscoveryReason(string title, string snippet, string url)
    {
        var text = $"{title} {snippet} {url}".ToLowerInvariant();

        if (text.Contains("vendor application") || text.Contains("vendor registration") ||
            text.Contains("call for vendors") || text.Contains("vendors wanted"))
            return "vendor participation/application signal";

        if (text.Contains("artisan") || text.Contains("handmade") || text.Contains("maker") ||
            text.Contains("craft"))
            return "artisan/handmade/maker signal";

        if (text.Contains("festival") || text.Contains("fair") || text.Contains("oktoberfest") ||
            text.Contains("bazaar") || text.Contains("market"))
            return "festival/fair/market signal";

        if (text.Contains("exhibitor") || text.Contains("booth"))
            return "exhibitor/booth signal";

        return "plausible community event requiring research";
    }

    private static bool LooksLikeEventDetailFromSource(
        Uri baseUri,
        Uri target,
        string title,
        string snippet)
    {
        var host = target.Host.ToLowerInvariant();
        var path = target.AbsolutePath.Trim('/').ToLowerInvariant();
        var titleText = (title ?? "").Trim().ToLowerInvariant();
        var snippetText = (snippet ?? "").ToLowerInvariant();
        var evidence = $"{titleText} {path} {snippetText}";

        if (IsSearchEngineHost(host)) return false;
        if (string.IsNullOrWhiteSpace(path) ||
            path is "index.html" or "index.htm" or "home" or "default.aspx")
            return false;

        var noise = new[]
        {
            "privacy","terms","login","sign in","contact","about","sitemap",
            "newsletter","advertise","membership","facebook","instagram",
            "twitter","youtube","pinterest"
        };
        if (noise.Any(evidence.Contains)) return false;

        // S1.25: directory/calendar pages are productive seeds, but their generic
        // navigation/application links are not individual shows. Reject only generic
        // child-link labels here; named events that include these words still pass.
        if (IsGenericDirectoryChild(titleText, path))
            return false;

        var vendorSignals = new[]
        {
            "vendor","vendors","exhibitor","exhibitors","artisan","artisans",
            "maker","makers","handmade","craft","crafts","booth","marketplace",
            "call for artists","call for vendors","apply to vend","vendor application",
            "vendor registration","vendor space"
        };

        var showSignals = new[]
        {
            "festival","fest "," fair","fair ","art show","art walk","street fair",
            "street festival","maker market","artisan market","handmade market",
            "night market","holiday market","christmas market","bazaar",
            "oktoberfest","renaissance","medieval","celtic","wellness expo",
            "health fair","holistic expo","car show","food truck festival",
            "arts & crafts","arts and crafts"
        };

        var hasVendorSignal = vendorSignals.Any(evidence.Contains);
        var hasShowSignal = showSignals.Any(evidence.Contains);

        // Hard reject obvious non-vendor activities UNLESS the page itself explicitly
        // says vendors/artisans/exhibitors participate.
        var obviousNonVendor = new[]
        {
            "bar crawl","pub crawl","traveling trivia"," trivia","collaboration corner",
            "trailhead","inspiration park","ward school","downtown vision plan",
            "vision plan","master plan","puppuccino","mainopoly",
            "tree lighting","ribbon cutting","board meeting","committee meeting",
            "city council","public hearing","webinar","storytime","book club",
            "blood drive","job fair","career fair","networking event"
        };
        if (!hasVendorSignal && obviousNonVendor.Any(evidence.Contains))
            return false;

        if (hasVendorSignal || hasShowSignal)
            return true;

        // Event platform detail pages can remain broad for later Research.
        if (host.EndsWith("eventbrite.com") && (path.Contains("/e/") || path.StartsWith("e/")))
            return true;
        if (host.EndsWith("eventeny.com") &&
            (path.Contains("events/") || path.Contains("vendor") || path.Contains("applications")))
            return true;
        if (host.EndsWith("zapplication.org") &&
            (path.Contains("event") || path.Contains("opportunity") || path.Contains("apply")))
            return true;
        if (host.EndsWith("festivalnet.com") &&
            (path.Contains("festival") || path.Contains("event")))
            return true;
        if (host.EndsWith("fairsandfestivals.net") &&
            (path.Contains("event") || path.Contains("festival") || path.Contains("fair")))
            return true;

        // Weak signals are enough only when the child page itself looks like a public event.
        var weakSignals = new[]
        {
            "community event","annual event","downtown event","celebration",
            "parade","family event","admission","entertainment"
        };
        return weakSignals.Any(evidence.Contains);
    }


    private static readonly Dictionary<string, string[]> RegionStates =
        new(StringComparer.OrdinalIgnoreCase)
        {
            ["Midwest"] =
            [
                "Indiana", "Ohio", "Kentucky", "Illinois", "Michigan", "Wisconsin",
                "Missouri", "Iowa", "Minnesota", "Kansas", "Nebraska",
                "North Dakota", "South Dakota"
            ]
        };

    private static bool IsSourceSeedInRequestedGeography(SearchHit hit, string region)
    {
        if (!RegionStates.TryGetValue(region, out var states) || states.Length == 0)
            return true;

        var text = $"{hit.Title} {hit.Url} {hit.Snippet}".ToLowerInvariant();

        var obviousOutside = new[]
        {
            "toronto", "ontario", "canada", ".ca/", ".ca?", "vancouver",
            "montreal", "quebec"
        };
        if (obviousOutside.Any(text.Contains))
            return false;

        var stateTokens = new[]
        {
            "indiana"," in ","ohio"," oh ","kentucky"," ky ","illinois"," il ",
            "michigan"," mi ","wisconsin"," wi ","missouri"," mo ","iowa"," ia ",
            "minnesota"," mn ","kansas"," ks ","nebraska"," ne ",
            "north dakota"," nd ","south dakota"," sd "
        };

        if (stateTokens.Any(text.Contains))
            return true;

        if (hit.Source is "ZAPP" or "EVENTENY" or "FESTIVALNET" or
            "FAIRS_AND_FESTIVALS" or "SUNSHINE_ARTIST" or "EVENTBRITE" or
            "VENDORSMAP")
            return true;

        return hit.Source == "LOCAL";
    }

    private static bool IsChildEventInRequestedGeography(
        SearchHit seed,
        string title,
        string url,
        string snippet,
        string region)
    {
        if (!RegionStates.TryGetValue(region, out var states) || states.Length == 0)
            return true;

        var text = $"{title} {url} {snippet} {seed.Title} {seed.Url} {seed.Snippet}".ToLowerInvariant();

        var obviousOutside = new[]
        {
            "toronto", "ontario", "canada", ".ca/", ".ca?", "vancouver",
            "montreal", "quebec"
        };
        if (obviousOutside.Any(text.Contains))
            return false;

        var stateTokens = new[]
        {
            "indiana"," in ","ohio"," oh ","kentucky"," ky ","illinois"," il ",
            "michigan"," mi ","wisconsin"," wi ","missouri"," mo ","iowa"," ia ",
            "minnesota"," mn ","kansas"," ks ","nebraska"," ne ",
            "north dakota"," nd ","south dakota"," sd "
        };

        if (stateTokens.Any(text.Contains))
            return true;

        if (Uri.TryCreate(seed.Url, UriKind.Absolute, out var seedUri))
        {
            var host = seedUri.Host.ToLowerInvariant();
            if (host.Contains("indiana") || host.Contains("ohio") ||
                host.Contains("kentucky") || host.Contains("illinois") ||
                host.Contains("michigan") || host.Contains("wisconsin") ||
                host.Contains("missouri") || host.Contains("iowa") ||
                host.Contains("minnesota") || host.Contains("kansas") ||
                host.Contains("nebraska") || host.Contains("dakota"))
                return true;
        }

        return false;
    }

    private static string DetectLocationLabel(
        SearchHit seed,
        string title,
        string url,
        string snippet)
    {
        var text = $"{title} {url} {snippet} {seed.Title} {seed.Url} {seed.Snippet}".ToLowerInvariant();

        var map = new (string Needle, string Label)[]
        {
            ("indiana","Indiana"),
            ("ohio","Ohio"),
            ("kentucky","Kentucky"),
            ("illinois","Illinois"),
            ("michigan","Michigan"),
            ("wisconsin","Wisconsin"),
            ("missouri","Missouri"),
            ("iowa","Iowa"),
            ("minnesota","Minnesota"),
            ("kansas","Kansas"),
            ("nebraska","Nebraska"),
            ("north dakota","North Dakota"),
            ("south dakota","South Dakota")
        };

        foreach (var item in map)
            if (text.Contains(item.Needle))
                return item.Label;

        return "Midwest location not yet extracted";
    }

    private static string DetectStateFromStateSeed(SearchHit hit)
    {
        const string marker = "STATE_SOURCE:";
        var i = hit.Query.IndexOf(marker, StringComparison.OrdinalIgnoreCase);
        return i < 0 ? "" : hit.Query[(i + marker.Length)..].Trim();
    }

    private static List<SearchHit> BuildStateSourceSeeds(string region)
    {
        var states = ExpandGeography(region);
        var slugs = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            ["Indiana"]="indiana", ["Ohio"]="ohio", ["Kentucky"]="kentucky",
            ["Illinois"]="illinois", ["Michigan"]="michigan", ["Wisconsin"]="wisconsin",
            ["Missouri"]="missouri", ["Iowa"]="iowa", ["Minnesota"]="minnesota",
            ["Kansas"]="kansas", ["Nebraska"]="nebraska", ["North Dakota"]="north-dakota",
            ["South Dakota"]="south-dakota"
        };
        var hits = new List<SearchHit>();
        foreach (var state in states)
        {
            if (!slugs.TryGetValue(state, out var slug)) continue;
            hits.Add(new SearchHit(
                "STATE_SOURCE",
                $"SCOUT:S1.22:STATE_SOURCE:{state}",
                $"{state} festival directory",
                $"https://festivalguidesandreviews.com/{slug}-festivals/",
                $"{state} festivals fairs markets event directory; deterministic state source seed."));
        }
        return hits;
    }

    private static string? DetectStateFromText(params string?[] values)
    {
        var text = string.Join(" ", values.Where(v => !string.IsNullOrWhiteSpace(v))).ToLowerInvariant();
        var map = new (string Needle, string State)[]
        {
            ("north-dakota","North Dakota"), ("north dakota","North Dakota"),
            ("south-dakota","South Dakota"), ("south dakota","South Dakota"),
            ("indiana","Indiana"), ("ohio","Ohio"), ("kentucky","Kentucky"),
            ("illinois","Illinois"), ("michigan","Michigan"), ("wisconsin","Wisconsin"),
            ("missouri","Missouri"), ("iowa","Iowa"), ("minnesota","Minnesota"),
            ("kansas","Kansas"), ("nebraska","Nebraska")
        };
        foreach (var item in map)
            if (text.Contains(item.Needle)) return item.State;
        return null;
    }

    private static string? DetectSeedState(SearchHit hit)
        => DetectStateFromText(hit.Query, hit.Title, hit.Url, hit.Snippet);

    private static bool IsBlockedCrawlSource(string? url)
    {
        if (string.IsNullOrWhiteSpace(url)) return true;
        var u = url.ToLowerInvariant();
        var blocked = new[]
        {
            "ticketsales.", "tickets-center.", "ticketmaster.", "stubhub.",
            "seatgeek.", "vividseats.", "ticketnetwork.", "eventticketscenter.",
            "/tickets/", "utm_source=bing", "bing.com/aclk"
        };
        return blocked.Any(u.Contains);
    }

    private static string SourceLearningTier(int accepted, int parsed)
    {
        if (parsed <= 0) return "RECOVERY_NEEDED";
        var yield = (double)accepted / parsed;
        if (accepted >= 20 && yield >= .30) return "HIGH";
        if (accepted >= 5 && yield >= .05) return "MEDIUM";
        if (accepted == 0) return "ZERO";
        return "LOW";
    }

    private static string SourceLearningTierForReport(CrawlBatch batch, HashSet<string> satisfiedStates)
    {
        var tier = SourceLearningTier(batch.EventLinksAccepted, batch.LinksParsed);
        var state = DetectStateFromText(batch.SourceUrl);
        if (batch.EventLinksAccepted == 0 && !string.IsNullOrWhiteSpace(state) && satisfiedStates.Contains(state))
            return "PRIMARY_ZERO_RECOVERED";
        return tier;
    }

    private static readonly Dictionary<string, string[]> RecoverySourceUrls =
        new(StringComparer.OrdinalIgnoreCase)
        {
            ["Ohio"] = new[]
            {
                "https://ohiofestivals.net/ohio-festivals/",
                "https://ohio.org/festivals-and-events"
            },
            ["Nebraska"] = new[]
            {
                "https://visitnebraska.com/events",
                "https://nebraskafairs.org/"
            },
            ["North Dakota"] = new[]
            {
                "https://www.ndtourism.com/events",
                "https://www.ndfairs.com/"
            },
            ["South Dakota"] = new[]
            {
                "https://www.travelsouthdakota.com/events",
                "https://www.sdfairs.com/"
            }
        };

    private static IEnumerable<SearchHit> BuildRecoverySeeds(IEnumerable<string> missingStates)
    {
        foreach (var state in missingStates)
        {
            if (!RecoverySourceUrls.TryGetValue(state, out var urls)) continue;
            foreach (var url in urls)
                yield return new SearchHit(
                    "STATE_RECOVERY",
                    $"SCOUT:S1.22:RECOVERY:{state}",
                    $"{state} event/festival directory recovery source",
                    url,
                    $"Alternate source lane for {state}; primary state source produced no crawlable results.");
        }
    }

    private static List<SearchPlan> BuildSearchPlans(int year, int month, string region)
    {
        // S1.7 remains DATE-BLIND. Year/month are planning metadata only.
        // The goal is fewer, broader, higher-yield searches whose result pages
        // can contribute multiple candidate events.

        var geographies = ExpandGeography(region);
        var plans = new List<SearchPlan>();

        string[] broadFamilies =
        [
            "\"vendor application\" festival craft art holiday maker market",
            "\"vendors wanted\" festival fair market craft artisan",
            "\"craft show\" OR \"craft fair\" OR \"maker market\" vendors",
            "\"art show\" OR \"art fair\" OR \"art walk\" vendors",
            "\"street fair\" OR \"street festival\" OR \"downtown festival\" vendors",
            "\"holiday bazaar\" OR \"Christmas market\" OR \"holiday show\" vendors",
            "\"harvest festival\" OR \"fall festival\" OR Oktoberfest vendors",
            "\"heritage festival\" OR \"historic festival\" OR \"cultural festival\" vendors",
            "\"Celtic festival\" OR \"renaissance festival\" OR \"medieval festival\" vendors",
            "\"food festival\" OR \"food truck festival\" OR \"wine festival\" artisan vendors",
            "\"night market\" OR \"pop-up market\" OR \"artisan market\" OR \"handmade market\"",
            "\"car show\" OR \"classic car show\" vendors",
            "\"health and wellness show\" OR \"wellness expo\" OR \"holistic expo\" vendors"
        ];

        foreach (var geo in geographies)
        {
            // Dedicated public source discovery.
            plans.Add(new("ZAPP", $"site:zapplication.org {geo} vendor application festival"));
            plans.Add(new("EVENTENY", $"site:eventeny.com {geo} vendor application festival"));
            plans.Add(new("FESTIVALNET", $"site:festivalnet.com {geo} craft art festival"));
            plans.Add(new("FAIRS_AND_FESTIVALS", $"site:fairsandfestivals.net {geo} craft art festival"));
            plans.Add(new("SUNSHINE_ARTIST", $"site:sunshineartist.com {geo} art craft festival"));
            plans.Add(new("EVENTBRITE", $"site:eventbrite.com {geo} vendor event market festival"));
            plans.Add(new("VENDORSMAP", $"VendorsMap {geo} vendor events"));

            // Local calendars.
            plans.Add(new("LOCAL", $"{geo} chamber tourism city events calendar festival fair market"));
            plans.Add(new("LOCAL", $"{geo} visitors bureau events craft art festival market"));

            // Discussion/review signals.
            plans.Add(new("REDDIT", $"site:reddit.com {geo} vendor craft show festival review"));
            plans.Add(new("FACEBOOK", $"site:facebook.com/groups {geo} vendor craft festival"));
            plans.Add(new("NEXTDOOR", $"site:nextdoor.com {geo} vendor festival market"));

            // Broad high-yield open-web families.
            foreach (var family in broadFamilies)
                plans.Add(new("WEB", $"{geo} {family}"));

            // Additive small-footprint/mobile-cart discovery.
            plans.Add(new("WEB", $"{geo} \"small footprint vendor\" OR pushcart OR \"mobile cart vendor\" festival market"));
        }

        // Region-wide discovery.
        plans.Add(new("WEB", $"{region} \"vendor application\" festivals craft art holiday maker market"));
        plans.Add(new("WEB", $"{region} \"craft show\" \"art fair\" \"street festival\" vendors"));
        plans.Add(new("REDDIT", $"site:reddit.com {region} vendor festival review craft show"));

        return plans
            .GroupBy(x => $"{x.Source}|{x.Query}", StringComparer.OrdinalIgnoreCase)
            .Select(x => x.First())
            .Take(500)
            .ToList();
    }

    private static List<string> ExpandGeography(string region)
    {
        if (region.Contains("midwest", StringComparison.OrdinalIgnoreCase))
        {
            // Formal Midwest plus Kentucky because it is operationally relevant from Batesville.
            return
            [
                "Indiana", "Ohio", "Kentucky", "Illinois", "Michigan", "Wisconsin",
                "Missouri", "Iowa", "Minnesota", "Kansas", "Nebraska", "North Dakota",
                "South Dakota"
            ];
        }

        // Allow comma-separated custom multi-state searches.
        var split = region.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
        return split.Length > 1 ? split.Distinct(StringComparer.OrdinalIgnoreCase).ToList() : [region];
    }

    private async Task<SearchBatch> SearchAsync(SearchPlan plan, int maxResults, CancellationToken cancellationToken)
    {
        var client = _httpClientFactory.CreateClient();
        client.Timeout = TimeSpan.FromSeconds(7);
        client.DefaultRequestHeaders.UserAgent.ParseAdd("Mozilla/5.0 (compatible; AncientInnovationsScout/1.20)");

        var hits = new List<SearchHit>();
        var parsedLinks = 0;
        var candidateLinks = 0;
        var rejections = new Dictionary<string,int>(StringComparer.OrdinalIgnoreCase);
        var resolutionSamples = new List<string>();

        void AddRejections(Dictionary<string,int> source)
        {
            foreach (var pair in source)
                rejections[pair.Key] = rejections.GetValueOrDefault(pair.Key) + pair.Value;
        }

        try
        {
            var url = "https://www.bing.com/search?q=" + Uri.EscapeDataString(plan.Query) +
                      "&count=" + Math.Max(maxResults, 10);

            var html = await client.GetStringAsync(url, cancellationToken);
            var parsed = ParseBingWithDiagnostics(html, plan, maxResults);
            parsedLinks += parsed.ParsedLinks;
            candidateLinks += parsed.CandidateLinks;
            AddRejections(parsed.Rejections);
            resolutionSamples.AddRange(parsed.ResolutionSamples);
            hits.AddRange(parsed.Hits);
        }
        catch { }

        // Only fall back when Bing yields zero candidate hits.
        if (hits.Count == 0)
        {
            try
            {
                using var fallbackCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
                fallbackCts.CancelAfter(TimeSpan.FromSeconds(3));

                var url = "https://html.duckduckgo.com/html/?q=" + Uri.EscapeDataString(plan.Query);
                var html = await client.GetStringAsync(url, fallbackCts.Token);

                var parsed = ParseDuckDuckGoWithDiagnostics(html, plan, maxResults);
                parsedLinks += parsed.ParsedLinks;
                candidateLinks += parsed.CandidateLinks;
                AddRejections(parsed.Rejections);
                resolutionSamples.AddRange(parsed.ResolutionSamples);

                foreach (var h in parsed.Hits)
                {
                    if (!hits.Any(x => NormalizeUrl(x.Url) == NormalizeUrl(h.Url)))
                        hits.Add(h);
                }
            }
            catch { }
        }

        return new SearchBatch(
            plan.Source,
            plan.Query,
            hits.Take(maxResults).ToList(),
            parsedLinks,
            candidateLinks,
            rejections,
            resolutionSamples.Take(4).ToList());
    }

    private static ParseBatch ParseBingWithDiagnostics(string html, SearchPlan plan, int max)
    {
        var hits = new List<SearchHit>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var rejected = new Dictionary<string,int>(StringComparer.OrdinalIgnoreCase);
        var resolutionSamples = new List<string>();
        var parsedLinks = 0;
        var candidateLinks = 0;

        void Reject(string reason) => rejected[reason] = rejected.GetValueOrDefault(reason) + 1;

        var linkRx = new Regex(
            "<h2[^>]*>\\s*<a[^>]*href=[\"'](?<url>[^\"']+)[\"'][^>]*>(?<title>.*?)</a>\\s*</h2>",
            RegexOptions.IgnoreCase | RegexOptions.Singleline);

        var matches = linkRx.Matches(html).Cast<Match>().ToList();

        if (matches.Count == 0)
        {
            var algoRx = new Regex(
                "<li[^>]*class=[\"'][^\"']*b_algo[^\"']*[\"'][^>]*>(?<block>.*?)</li>",
                RegexOptions.IgnoreCase | RegexOptions.Singleline);

            foreach (Match block in algoRx.Matches(html))
            {
                var lm = Regex.Match(
                    block.Groups["block"].Value,
                    "<a[^>]*href=[\"'](?<url>[^\"']+)[\"'][^>]*>(?<title>.*?)</a>",
                    RegexOptions.IgnoreCase | RegexOptions.Singleline);
                if (lm.Success) matches.Add(lm);
            }
        }

        foreach (var m in matches)
        {
            parsedLinks++;

            var rawUrl = WebUtility.HtmlDecode(m.Groups["url"].Value);
            var url = UnwrapSearchResultUrl(rawUrl);
            var title = CleanHtml(m.Groups["title"].Value);

            if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) ||
                (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps))
            {
                Reject("bad-url");
                continue;
            }

            if (IsSearchEngineHost(uri.Host))
            {
                Reject("search-wrapper-unresolved");
                continue;
            }

            var key = NormalizeUrl(url);
            if (string.IsNullOrWhiteSpace(key) || string.IsNullOrWhiteSpace(title))
            {
                Reject("missing-title-or-url");
                continue;
            }

            if (!seen.Add(key))
            {
                Reject("parser-duplicate-url");
                continue;
            }

            var snippet = FindNearbySnippet(html, m.Index, m.Length);
            var reason = CandidateRejectionReason(url, title, snippet, plan);
            if (reason is not null)
            {
                Reject(reason);
                continue;
            }

            candidateLinks++;
            if (!string.Equals(rawUrl, url, StringComparison.OrdinalIgnoreCase) && resolutionSamples.Count < 4)
                resolutionSamples.Add($"{ShortUrl(rawUrl)} -> {ShortUrl(url)}");
            if (hits.Count < max)
                hits.Add(new SearchHit(plan.Source, plan.Query, title, url, snippet));
        }

        return new ParseBatch(hits, parsedLinks, candidateLinks, rejected, resolutionSamples);
    }

    private static ParseBatch ParseDuckDuckGoWithDiagnostics(string html, SearchPlan plan, int max)
    {
        var hits = new List<SearchHit>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var rejected = new Dictionary<string,int>(StringComparer.OrdinalIgnoreCase);
        var resolutionSamples = new List<string>();
        var parsedLinks = 0;
        var candidateLinks = 0;

        void Reject(string reason) => rejected[reason] = rejected.GetValueOrDefault(reason) + 1;

        var resultRx = new Regex(
            "<a[^>]*class=[\"'][^\"']*result__a[^\"']*[\"'][^>]*href=[\"'](?<url>[^\"']+)[\"'][^>]*>(?<title>.*?)</a>",
            RegexOptions.IgnoreCase | RegexOptions.Singleline);

        foreach (Match m in resultRx.Matches(html))
        {
            parsedLinks++;

            var rawUrl = WebUtility.HtmlDecode(m.Groups["url"].Value);
            var decodedDuck = DecodeDuckUrl(rawUrl);
            var url = UnwrapSearchResultUrl(decodedDuck);
            var title = CleanHtml(m.Groups["title"].Value);

            if (!Uri.TryCreate(url, UriKind.Absolute, out var uri))
            {
                Reject("bad-url");
                continue;
            }

            if (IsSearchEngineHost(uri.Host))
            {
                Reject("search-wrapper-unresolved");
                continue;
            }

            var key = NormalizeUrl(url);
            if (string.IsNullOrWhiteSpace(key) || string.IsNullOrWhiteSpace(title))
            {
                Reject("missing-title-or-url");
                continue;
            }

            if (!seen.Add(key))
            {
                Reject("parser-duplicate-url");
                continue;
            }

            var snippet = FindNearbySnippet(html, m.Index, m.Length);
            var reason = CandidateRejectionReason(url, title, snippet, plan);
            if (reason is not null)
            {
                Reject(reason);
                continue;
            }

            candidateLinks++;
            if (!string.Equals(rawUrl, url, StringComparison.OrdinalIgnoreCase) && resolutionSamples.Count < 4)
                resolutionSamples.Add($"{ShortUrl(rawUrl)} -> {ShortUrl(url)}");
            if (hits.Count < max)
                hits.Add(new SearchHit(plan.Source, plan.Query, title, url, snippet));
        }

        return new ParseBatch(hits, parsedLinks, candidateLinks, rejected, resolutionSamples);
    }

    private static string UnwrapSearchResultUrl(string rawUrl)
    {
        if (string.IsNullOrWhiteSpace(rawUrl)) return rawUrl;

        var url = WebUtility.HtmlDecode(rawUrl).Trim();

        if (url.StartsWith("//", StringComparison.Ordinal))
            url = "https:" + url;

        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri))
            return url;

        if (!IsSearchEngineHost(uri.Host))
            return url;

        var query = ParseQueryString(uri.Query);

        // Bing organic links commonly use:
        // https://www.bing.com/ck/a?...&u=a1<base64url(destination)>&...
        if (query.TryGetValue("u", out var bingU) && !string.IsNullOrWhiteSpace(bingU))
        {
            var decoded = TryDecodeBingUParameter(bingU);
            if (!string.IsNullOrWhiteSpace(decoded))
                return decoded;
        }

        // Common redirect parameter names used by search engines.
        foreach (var key in new[] { "url", "uddg", "target", "r", "dest", "destination" })
        {
            if (!query.TryGetValue(key, out var encoded) || string.IsNullOrWhiteSpace(encoded))
                continue;

            var decoded = RepeatedUrlDecode(encoded);
            if (Uri.TryCreate(decoded, UriKind.Absolute, out var target) &&
                (target.Scheme == Uri.UriSchemeHttp || target.Scheme == Uri.UriSchemeHttps))
                return decoded;
        }

        // Some Bing variants can surface the encoded destination under other parameter names.
        foreach (var pair in query)
        {
            var decoded = TryDecodeBingUParameter(pair.Value);
            if (!string.IsNullOrWhiteSpace(decoded))
                return decoded;
        }

        return url;
    }

    private static string? TryDecodeBingUParameter(string value)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;

        var v = RepeatedUrlDecode(value).Trim();

        // Already a real URL after URL-decoding.
        if (Uri.TryCreate(v, UriKind.Absolute, out var direct) &&
            (direct.Scheme == Uri.UriSchemeHttp || direct.Scheme == Uri.UriSchemeHttps) &&
            !IsSearchEngineHost(direct.Host))
            return v;

        // Microsoft's Bing redirect payload commonly begins with "a1".
        if (v.StartsWith("a1", StringComparison.OrdinalIgnoreCase))
            v = v[2..];

        // Try URL-safe and standard Base64.
        foreach (var candidate in new[] { v, v.Replace('-', '+').Replace('_', '/') })
        {
            try
            {
                var padded = candidate;
                switch (padded.Length % 4)
                {
                    case 2: padded += "=="; break;
                    case 3: padded += "="; break;
                }

                var bytes = Convert.FromBase64String(padded);
                var decoded = System.Text.Encoding.UTF8.GetString(bytes).Trim('\0', ' ', '"');

                // Occasionally the decoded payload itself remains URL encoded.
                decoded = RepeatedUrlDecode(decoded);

                if (Uri.TryCreate(decoded, UriKind.Absolute, out var target) &&
                    (target.Scheme == Uri.UriSchemeHttp || target.Scheme == Uri.UriSchemeHttps) &&
                    !IsSearchEngineHost(target.Host))
                    return decoded;
            }
            catch { }
        }

        return null;
    }

    private static string ShortUrl(string value)
    {
        if (string.IsNullOrWhiteSpace(value)) return "";
        const int max = 140;
        return value.Length <= max ? value : value[..max] + "…";
    }

    private static Dictionary<string,string> ParseQueryString(string query)
    {
        var result = new Dictionary<string,string>(StringComparer.OrdinalIgnoreCase);
        if (string.IsNullOrWhiteSpace(query)) return result;

        foreach (var part in query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var pieces = part.Split('=', 2);
            var key = Uri.UnescapeDataString(pieces[0]);
            var value = pieces.Length > 1 ? pieces[1] : "";
            result[key] = value;
        }
        return result;
    }

    private static string RepeatedUrlDecode(string value)
    {
        var current = value.Replace("+", "%20");
        for (var i = 0; i < 3; i++)
        {
            var decoded = Uri.UnescapeDataString(current);
            if (decoded == current) break;
            current = decoded;
        }
        return current;
    }

    private static bool IsSearchEngineHost(string host)
    {
        host = host.ToLowerInvariant();
        return host.Contains("bing.com") ||
               host.Contains("duckduckgo.com") ||
               host.Contains("google.com") ||
               host.Contains("yahoo.com");
    }

    private static string? CandidateRejectionReason(string url, string title, string snippet, SearchPlan plan)
    {
        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri))
            return "bad-url";

        var host = uri.Host.ToLowerInvariant();
        var path = uri.AbsolutePath.Trim('/').ToLowerInvariant();
        var titleText = (title ?? "").ToLowerInvariant();
        var snippetText = (snippet ?? "").ToLowerInvariant();
        var evidence = $"{titleText} {path} {snippetText}";

        // Dedicated-source searches must actually land on the intended source.
        // Bing frequently ignores site: restrictions and returns unrelated geography pages.
        var requiredDomain = plan.Source switch
        {
            "ZAPP" => new[] { "zapplication.org" },
            "EVENTENY" => new[] { "eventeny.com" },
            "FESTIVALNET" => new[] { "festivalnet.com" },
            "FAIRS_AND_FESTIVALS" => new[] { "fairsandfestivals.net" },
            "SUNSHINE_ARTIST" => new[] { "sunshineartist.com" },
            "EVENTBRITE" => new[] { "eventbrite.com" },
            "REDDIT" => new[] { "reddit.com" },
            "FACEBOOK" => new[] { "facebook.com" },
            "NEXTDOOR" => new[] { "nextdoor.com" },
            _ => Array.Empty<string>()
        };

        if (requiredDomain.Length > 0 &&
            !requiredDomain.Any(d => host.Equals(d, StringComparison.OrdinalIgnoreCase) ||
                                     host.EndsWith("." + d, StringComparison.OrdinalIgnoreCase)))
            return "wrong-source-domain";

        // Obvious encyclopedia/map/general-information destinations are not show leads.
        var blockedHosts = new[]
        {
            "wikipedia.org", "worldatlas.com", "britannica.com",
            "mapquest.com", "maps.google.com"
        };
        if (blockedHosts.Any(d => host.Equals(d) || host.EndsWith("." + d)))
            return "generic-information-page";

        // Root/home pages are not event records. This intentionally does NOT ban the host:
        // a tourism/chamber/city site can still contribute a real event-detail page.
        if (string.IsNullOrWhiteSpace(path) ||
            path is "index.html" or "index.htm" or "home" or "default.aspx")
            return "navigation-homepage";

        var navigationTerms = new[]
        {
            "privacy", "terms of use", "terms-and-conditions", "sign in", "login",
            "help center", "advertising", "contact us", "about us", "site map"
        };
        if (navigationTerms.Any(evidence.Contains))
            return "navigation-or-policy";

        // Strong event/show evidence must come from the RESULT itself, never merely the query.
        var strongSignals = new[]
        {
            "festival", "fest ", "fair", "craft show", "art show", "art walk",
            "street fair", "street festival", "maker market", "makers market",
            "artisan market", "handmade market", "night market", "holiday market",
            "christmas market", "bazaar", "oktoberfest", "renaissance", "medieval",
            "celtic", "vendor application", "vendor registration", "vendors wanted",
            "call for vendors", "call for artists", "exhibitor application",
            "exhibitor registration", "vendor booth", "vendor space",
            "wellness expo", "health fair", "holistic expo", "car show",
            "food truck", "marketplace", "arts & crafts", "arts and crafts"
        };

        var hasStrongSignal = strongSignals.Any(evidence.Contains);

        // Directory/platform event pages can use names that omit generic words such as "festival".
        // For those sources, require event-detail path semantics if no textual event signal exists.
        if (!hasStrongSignal)
        {
            var platformDetail = plan.Source switch
            {
                "ZAPP" => path.Contains("event") || path.Contains("opportunity") || path.Contains("apply"),
                "EVENTENY" => path.Contains("events") || path.Contains("vendor") || path.Contains("applications"),
                "FESTIVALNET" => path.Contains("festival") || path.Contains("event"),
                "FAIRS_AND_FESTIVALS" => path.Contains("event") || path.Contains("festival") || path.Contains("fair"),
                "SUNSHINE_ARTIST" => path.Contains("show") || path.Contains("event") || path.Contains("festival"),
                "EVENTBRITE" => path.Contains("e/") || path.Contains("event"),
                "REDDIT" => path.Contains("comments") && HasRedditShowEvidence(evidence),
                "FACEBOOK" => path.Contains("events") || path.Contains("groups"),
                "NEXTDOOR" => path.Contains("events") || path.Contains("p/"),
                _ => false
            };

            if (!platformDetail)
                return "no-event-signal";
        }

        // Generic listing/search/category pages are useful as discovery SOURCES but should not
        // themselves become staged event candidates.
        var genericPathTerms = new[]
        {
            "/search", "/category", "/categories", "/directory", "/calendar",
            "/events/", "/festivals/", "/fairs/", "/markets/"
        };

        var looksGenericListing = genericPathTerms.Any(t => ("/" + path).EndsWith(t.TrimEnd('/')));

        // Listing/calendar pages are allowed through this parser stage as SOURCE SEEDS.
        // S1.12 removes them from final show candidates and crawls their child links instead.
        return null;
    }

    private static bool HasRedditShowEvidence(string evidence)
    {
        if (string.IsNullOrWhiteSpace(evidence)) return false;
        var e = evidence.ToLowerInvariant();
        var required = new[]
        {
            "craft show", "art show", "vendor show", "vendor event", "vendor application",
            "festival vendor", "festival booth", "fair vendor", "artisan market", "maker market",
            "handmade market", "street festival", "holiday market", "renaissance fair",
            "renaissance festival", "celtic festival", "oktoberfest vendor"
        };
        if (!required.Any(e.Contains)) return false;

        var unrelated = new[]
        {
            "chatgpt", "chat gpt", "sportsbook", "sports betting", "medicalschool",
            "medical school", "study prompt", "betting prompt"
        };
        return !unrelated.Any(e.Contains);
    }

    private static string FindNearbySnippet(string html, int matchIndex, int matchLength)
    {
        if (string.IsNullOrWhiteSpace(html)) return string.Empty;

        var start = Math.Max(0, matchIndex);
        var len = Math.Min(1800, html.Length - start);
        if (len <= 0) return string.Empty;

        var window = html.Substring(start, len);

        var snippet = Regex.Match(
            window,
            "<p[^>]*>(?<snippet>.*?)</p>",
            RegexOptions.IgnoreCase | RegexOptions.Singleline);

        if (snippet.Success)
            return CleanHtml(snippet.Groups["snippet"].Value);

        snippet = Regex.Match(
            window,
            "<div[^>]*class=[\"'][^\"']*(?:b_caption|result__snippet)[^\"']*[\"'][^>]*>(?<snippet>.*?)</div>",
            RegexOptions.IgnoreCase | RegexOptions.Singleline);

        return snippet.Success
            ? CleanHtml(snippet.Groups["snippet"].Value)
            : string.Empty;
    }

    private static string DecodeDuckUrl(string raw)
    {
        if (raw.StartsWith("//")) raw = "https:" + raw;

        if (Uri.TryCreate(raw, UriKind.Absolute, out var uri) &&
            uri.Host.Contains("duckduckgo.com", StringComparison.OrdinalIgnoreCase))
        {
            foreach (var part in uri.Query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries))
            {
                var kv = part.Split('=', 2);
                if (kv.Length == 2 && kv[0] == "uddg")
                    return Uri.UnescapeDataString(kv[1].Replace('+', ' '));
            }
        }

        return raw;
    }

    private static bool LooksLikeCandidateLink(string url, string title, string query)
    {
        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri)) return false;

        var host = uri.Host.ToLowerInvariant();
        if (host.Contains("bing.com") ||
            host.Contains("microsoft.com") ||
            host.Contains("duckduckgo.com") ||
            host.Contains("google.com"))
            return false;

        var text = $"{title} {url} {query}".ToLowerInvariant();
        string[] likely =
        [
            "festival", "fair", "market", "artisan", "craft", "art show",
            "vendor", "application", "heritage", "celtic", "renaissance",
            "oktoberfest", "holiday", "oddities"
        ];

        return likely.Any(text.Contains);
    }

    private static bool IsGenericDirectoryChild(string? title, string? path)
    {
        var t = Regex.Replace((title ?? string.Empty).Trim().ToLowerInvariant(), @"\s+", " ");
        var p = (path ?? string.Empty).Trim('/').ToLowerInvariant();

        // Exact/near-exact labels commonly emitted by fair/festival directory navigation.
        // Deliberately conservative: "Dayton Celtic Festival Vendor Application" is NOT generic.
        string[] genericTitles =
        [
            "vendor registration", "vendor application", "vendor applications",
            "vendor information", "vendor info", "vendor forms", "vendor form",
            "fair registration", "festival registration", "event registration",
            "exhibitor registration", "exhibitor application", "exhibitor information",
            "registration", "register", "application", "applications", "apply",
            "forms", "forms and policies", "policies", "rules", "rules and regulations",
            "updated policies", "vendor handbook", "vendor packet",
            "fair forms", "festival forms", "event forms",
            "calendar", "events calendar", "event calendar",
            "directory", "fair directory", "festival directory",
            "map", "site map", "fair map", "grounds map",
            "membership", "member login", "resources", "links"
        ];
        if (genericTitles.Contains(t, StringComparer.OrdinalIgnoreCase))
            return true;

        // Also catch generic labels with harmless punctuation/year suffixes.
        var stripped = Regex.Replace(t, @"\b20\d{2}\b", " ");
        stripped = Regex.Replace(stripped, @"[^a-z]+", " ").Trim();
        stripped = Regex.Replace(stripped, @"\s+", " ");
        if (genericTitles.Contains(stripped, StringComparer.OrdinalIgnoreCase))
            return true;

        // S1.26: reject page furniture, broad section headings, and leaked template/code
        // expressions. These are extraction artifacts, not event names.
        string[] furnitureTitles =
        [
            "vendors", "vendor", "fairs", "fair", "festivals", "festival",
            "festivals events", "events", "event", "things to do",
            "my review", "review", "reviews", "leave a review", "write a review",
            "read more", "learn more", "view more", "see more", "more",
            "home", "about", "contact", "contact us", "search", "menu",
            "previous", "next", "back", "share", "print", "subscribe",
            "current list of directors information", "directors", "board of directors"
        ];
        if (furnitureTitles.Contains(stripped, StringComparer.OrdinalIgnoreCase))
            return true;

        if (LooksLikeTemplateOrCodeArtifact(t))
            return true;

        // Generic form/resource endpoints are navigation when the anchor is also generic.
        var genericPathTail = new[]
        {
            "/register", "/registration", "/apply", "/application", "/applications",
            "/forms", "/policies", "/rules", "/resources", "/membership"
        };
        var pathLooksGeneric = genericPathTail.Any(x => ("/" + p).EndsWith(x, StringComparison.OrdinalIgnoreCase));
        var titleHasNamedEventSignal = new[]
        {
            "festival", "oktoberfest", "renaissance", "celtic", "market", "bazaar",
            "art show", "craft show", "street fair", "county fair", "state fair",
            "heritage", "celebration"
        }.Any(t.Contains);

        return pathLooksGeneric && !titleHasNamedEventSignal &&
               (t.Contains("vendor") || t.Contains("exhibitor") || t.Contains("registration") ||
                t.Contains("application") || t.Contains("form") || t.Contains("policy") ||
                t.Contains("rule") || t.Contains("resource"));
    }

    private static bool LooksLikeTemplateOrCodeArtifact(string? value)
    {
        var t = (value ?? string.Empty).Trim();
        if (t.Length == 0) return true;

        var lower = t.ToLowerInvariant();
        string[] codeSignals =
        [
            "titlecurrentcontent", "currentcontentorig", "innerhtml", "innertext",
            "document.", "window.", "function(", "onclick=", "href=", "javascript:",
            "{{", "}}", "${", "<script", "</", "undefined", "[object object]"
        ];
        if (codeSignals.Any(lower.Contains))
            return true;

        // Common concatenation/template leakage such as '+titleCurrentContentOrig+'.
        if ((t.Contains("'+") || t.Contains("+'") || t.Contains("\"+") || t.Contains("+\"")) &&
            Regex.IsMatch(t, @"[A-Za-z_$][A-Za-z0-9_$]*"))
            return true;

        // A candidate title should contain at least one letter or number and should not
        // be predominantly punctuation/code delimiters.
        var meaningful = t.Count(char.IsLetterOrDigit);
        return meaningful == 0 || meaningful * 2 < t.Length;
    }

    private static bool HardExclude(string title, string snippet, string url)
    {
        var text = $" {title} {snippet} {url} ".ToLowerInvariant();

        string[] conventionTerms =
        [
            "comic con", "comic-con", "anime convention",
            "gaming convention", "fan convention", "cosplay con"
        ];
        if (conventionTerms.Any(text.Contains)) return true;

        if (text.Contains("blue ribbon events")) return true;
        if (LooksLikeTemplateOrCodeArtifact(title)) return true;

        // S1.27: concatenated page-section/body text is not an event title.
        // Keep ordinary long event names; reject only clearly body-sized headings.
        var compactTitle = WhitespaceRegex.Replace((title ?? string.Empty).Trim(), " ");
        if (compactTitle.Length > 180) return true;

        var normalizedTitle = Regex.Replace((title ?? string.Empty).Trim().ToLowerInvariant(), @"[^a-z0-9]+", " ").Trim();
        string[] genericFurniture =
        [
            "vendors", "vendor", "fairs", "fair", "festivals", "festival",
            "festivals events", "events", "event", "my review", "review", "reviews",
            "leave a review", "write a review", "read more", "learn more",
            "view more", "see more", "home", "about", "contact", "contact us",
            "search", "menu", "previous", "next", "back", "share", "print",
            "subscribe", "directors", "board of directors"
        ];
        if (genericFurniture.Contains(normalizedTitle, StringComparer.OrdinalIgnoreCase))
            return true;

        if (Uri.TryCreate(url, UriKind.Absolute, out var candidateUri) &&
            IsGenericDirectoryChild((title ?? string.Empty).ToLowerInvariant(),
                                    candidateUri.AbsolutePath.Trim('/').ToLowerInvariant()))
            return true;

        // S1.24: conservative retail/ticket-broker cleanup. These can contain festival
        // words in snippets but are not individual vendor opportunities.
        string[] nonEventRetailTerms =
        [
            "michaels arts and crafts store", "michaels store",
            "hobby lobby store", "joann fabric", "joann fabrics"
        ];
        if (nonEventRetailTerms.Any(text.Contains)) return true;

        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri)) return true;

        var host = uri.Host.ToLowerInvariant();
        string[] nonCandidateHosts =
        [
            "facebook.com", "pinterest.", "yelp.", "google.com",
            "youtube.com", "instagram.com",
            "michaels.com", "hobbylobby.com", "joann.com",
            "ticketmaster.com", "stubhub.com", "vividseats.com",
            "seatgeek.com", "ticketsmarter.com", "ticketnetwork.com",
            "eventticketscenter.com"
        ];

        return nonCandidateHosts.Any(host.Contains);
    }

    private static string BuildIdentityKey(string title, string url)
    {
        // Do NOT dedupe purely on a generic title. Include organizer/domain and normalized core title.
        // This keeps "Fall Festival" in two different towns as two different candidates.
        var titleCore = NormalizeTitle(
            Regex.Replace(
                title ?? string.Empty,
                @"\b(20\d{2}|annual|festival|fest|fair|market|show|vendor|application|applications)\b",
                " ",
                RegexOptions.IgnoreCase));

        var host = "";
        if (Uri.TryCreate(url, UriKind.Absolute, out var uri))
            host = uri.Host.ToLowerInvariant().Replace("www.", "");

        return $"{host}|{titleCore}";
    }

    private static string DetectYearSignal(string text, int targetYear)
    {
        if (text.Contains(targetYear.ToString(), StringComparison.OrdinalIgnoreCase))
            return $"{targetYear} listing/date signal found.";

        for (var y = targetYear - 3; y < targetYear; y++)
        {
            if (text.Contains(y.ToString(), StringComparison.OrdinalIgnoreCase))
                return $"Established event discovered from {y} evidence; {targetYear} status not yet published/verified.";
        }

        return $"Target season {targetYear}; year/date not yet published or verified.";
    }

    private static string CleanTitle(string title)
    {
        var cleaned = Regex.Replace(
            title ?? string.Empty,
            @"\s*[|–—]\s*(Facebook|Instagram|Eventbrite|Yelp).*$",
            "",
            RegexOptions.IgnoreCase).Trim();

        return string.IsNullOrWhiteSpace(cleaned)
            ? (title ?? string.Empty).Trim()
            : cleaned;
    }

    private static string NormalizeTitle(string? value) =>
        Regex.Replace((value ?? string.Empty).ToLowerInvariant(), @"[^a-z0-9]+", "");

    private static string NormalizeUrl(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return string.Empty;

        var s = value.Trim();

        if (Uri.TryCreate(s, UriKind.Absolute, out var u))
            return $"{u.Host.ToLowerInvariant()}{u.AbsolutePath.TrimEnd('/').ToLowerInvariant()}";

        return s.ToLowerInvariant();
    }

    private static string CleanHtml(string value) =>
        WhitespaceRegex.Replace(
            WebUtility.HtmlDecode(TagRegex.Replace(value ?? string.Empty, " ")),
            " ").Trim();

    private static string Limit(string? value, int max) =>
        string.IsNullOrWhiteSpace(value) ? string.Empty :
        value.Length <= max ? value : value[..max];

    private sealed record SearchPlan(string Source, string Query);
    private sealed record SearchHit(string Source, string Query, string Title, string Url, string Snippet);
    private sealed record SearchBatch(string Source, string Query, List<SearchHit> Hits, int ParsedLinks, int CandidateLinks, Dictionary<string,int> Rejections, List<string> ResolutionSamples);
    private sealed record ParseBatch(List<SearchHit> Hits, int ParsedLinks, int CandidateLinks, Dictionary<string,int> Rejections, List<string> ResolutionSamples);
    private sealed record CrawlBatch(string SourceUrl, bool FetchSucceeded, int LinksParsed, int EventLinksAccepted, List<SearchHit> Hits);
}
