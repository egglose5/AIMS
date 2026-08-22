using System.Net;
using System.Text.Json;
using System.Globalization;
using System.Text.RegularExpressions;
using System.Text;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record WebSearchHit(string Title, string Url, string Snippet, string Query);
public sealed record ShowResearchRunResult(int Searches, int NewEvidence, string Message);
public sealed record ShowDiscoveryRunResult(int Searches, int NewLeads, string Message);
public sealed record ShowCandidateVerificationResult(
    bool Verified,
    string Name,
    string? City,
    string? State,
    DateOnly? StartDate,
    DateOnly? EndDate,
    string? VerifiedUrl,
    string Reason);

public interface IShowWebResearchService
{
    Task<ShowResearchRunResult> CollectEvidenceAsync(long showEditionId, CancellationToken cancellationToken = default);
    Task<ShowDiscoveryRunResult> DiscoverCandidatesAsync(long? vendorProfileId, int targetYear, int targetMonth, CancellationToken cancellationToken = default);
    Task<ShowCandidateVerificationResult> VerifyCandidateAsync(ShowDiscoveryLeadEntity lead, int targetYear, int targetMonth, CancellationToken cancellationToken = default);
}

/// <summary>
/// Keyless public-web research collector. It intentionally stores source leads/snippets rather than pretending
/// that search-engine text is verified truth. Admin review and the Show Arm evidence model remain authoritative.
/// </summary>
public sealed class ShowWebResearchService : IShowWebResearchService
{
    private DateTimeOffset _discoveryDeadline = DateTimeOffset.MinValue;
    private int _discoveryPageBudget = 0;

    private readonly ShowArmDbContext _db;
    private readonly IHttpClientFactory _httpClientFactory;
    private readonly IWebHostEnvironment _env;
    private static readonly Regex TagRegex = new("<[^>]+>", RegexOptions.Compiled);
    private static readonly Regex WhitespaceRegex = new(@"\s+", RegexOptions.Compiled);

    public ShowWebResearchService(ShowArmDbContext db, IHttpClientFactory httpClientFactory, IWebHostEnvironment env)
    {
        _db = db;
        _httpClientFactory = httpClientFactory;
        _env = env;
    }

    public async Task<ShowCandidateVerificationResult> VerifyCandidateAsync(ShowDiscoveryLeadEntity lead, int targetYear, int targetMonth, CancellationToken cancellationToken = default)
    {
        var rawName = CleanTitle(lead.Title ?? "");
        if (!LooksLikeSpecificShow(rawName, lead.Snippet ?? "", lead.Url ?? "") || IsGenericCandidateTitle(rawName))
            return new(false, rawName, null, null, null, null, null, "Title is not a specific event.");

        var monthName = new DateTime(targetYear, Math.Clamp(targetMonth, 1, 12), 1).ToString("MMMM");
        var candidates = new List<WebSearchHit>();

        if (!IsForbiddenCandidateUrl(lead.Url))
        {
            var direct = await HydrateHitFromPageAsync(new WebSearchHit(rawName, lead.Url ?? "", lead.Snippet ?? "", lead.SearchQuery ?? ""), cancellationToken);
            if (direct is not null) candidates.Add(direct);
        }

        var queries = new[]
        {
            $"\"{rawName}\" {targetYear} {monthName} official festival",
            $"\"{rawName}\" {targetYear} dates location vendor application",
            $"\"{rawName}\" {targetYear} site:eventeny.com",
            $"\"{rawName}\" {targetYear} site:zapplication.org",
            $"\"{rawName}\" {targetYear} site:festivalnet.com",
            $"\"{rawName}\" {targetYear} site:sunshineartist.com"
        };

        foreach (var q in queries)
        {
            foreach (var hit in await SearchAsync(q, 6, cancellationToken))
            {
                if (IsForbiddenCandidateUrl(hit.Url)) continue;
                var hydrated = await HydrateHitFromPageAsync(hit, cancellationToken);
                candidates.Add(hydrated ?? hit);
            }
        }

        foreach (var hit in candidates
            .Where(x => !string.IsNullOrWhiteSpace(x.Url))
            .GroupBy(x => x.Url, StringComparer.OrdinalIgnoreCase)
            .Select(x => x.First()))
        {
            var title = CleanTitle(hit.Title);
            var combined = $"{title} {hit.Snippet}";
            if (IsForbiddenCandidateUrl(hit.Url) || IsGenericCandidateTitle(title)) continue;
            if (!NamesProbablyMatch(rawName, title, hit.Snippet)) continue;

            var (city, state) = ParseLocation(combined);
            var (startDate, endDate) = ParseEventDates(combined, targetYear, targetMonth);
            if (string.IsNullOrWhiteSpace(state) || startDate is null) continue;

            var canonical = ChooseCleanerEventName(rawName, title);
            return new(true, canonical, city, state, startDate, endDate, hit.Url,
                $"Verified specific event from page content: {canonical} · {(string.IsNullOrWhiteSpace(city) ? "" : city + ", ")}{state} · {startDate:MMM d, yyyy}.");
        }

        return new(false, rawName, null, null, null, null, null,
            "Could not verify both location and an actual event date from a specific event/official/application page.");
    }

    public async Task<ShowResearchRunResult> CollectEvidenceAsync(long showEditionId, CancellationToken cancellationToken = default)
    {
        var edition = await _db.ShowEditions.Include(x => x.ShowEvent).FirstAsync(x => x.Id == showEditionId, cancellationToken);
        edition.ResearchStatus = "RESEARCHING";
        edition.ResearchStartedAt ??= DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(cancellationToken);

        var place = string.Join(" ", new[] { edition.ShowEvent.City, edition.ShowEvent.State }.Where(x => !string.IsNullOrWhiteSpace(x)));
        var core = $"\"{edition.ShowEvent.Name}\" {place}".Trim();
        var researchYear = Math.Max(DateTime.UtcNow.Year, edition.Year - 1);
        var searches = new (string Type, string Query)[]
        {
            ("ATTENDANCE", $"{core} attendance {researchYear} festival"),
            ("VENDOR_QUALITY", $"{core} vendor list handmade artisans craft {researchYear}"),
            ("VENDOR_REPORT", $"{core} vendor review artist sales craft fair"),
            ("MARKETING", $"{core} advertising marketing media festival {researchYear}"),
            ("MAP_PLACEMENT", $"{core} vendor map booth map pdf"),
            ("PROMOTER", $"{core} organizer promoter application vendor rules"),
            ("APPLICATION", $"{core} {edition.Year} vendor application deadline booth fee jury fee"),
            ("APPLICATION", $"site:zapplication.org {core}"),
            ("APPLICATION", $"site:eventeny.com {core}"),
            ("APPLICATION", $"site:callforentry.org {core}"),
            ("APPLICATION", $"site:entrythingy.com {core}"),
            ("APPLICATION", $"site:artcall.org {core}"),
            ("APPLICATION", $"site:showsubmit.com {core}"),
            ("OPERATIONS", $"{core} {edition.Year} dates hours setup load in parking indoor outdoor"),
            ("RESTRICTIONS", $"{core} vendor rules handmade resale restrictions prohibited items"),
            ("LODGING", $"{core} hotels lodging campground vendor camping"),
            ("TRAVEL", $"{core} directions drive travel")
        };

        var existingUrls = await _db.ShowResearchEvidence
            .Where(x => x.ShowEditionId == showEditionId && x.SourceUrl != null)
            .Select(x => x.SourceUrl!)
            .ToListAsync(cancellationToken);
        var existing = new HashSet<string>(existingUrls, StringComparer.OrdinalIgnoreCase);
        var added = 0;

        // Ancient Innovations' own history outranks generic web enthusiasm.
        var relatedIds = await _db.ShowEditions.AsNoTracking().Where(x => x.ShowEventId == edition.ShowEventId).Select(x => x.Id).ToListAsync(cancellationToken);
        var notes = await _db.ShowNotes.AsNoTracking().Where(x => relatedIds.Contains(x.ShowEditionId) && x.UseForShowArm).OrderByDescending(x => x.CreatedAt).Take(30).ToListAsync(cancellationToken);
        foreach (var note in notes)
        {
            var key = $"internal://show-note/{note.Id}";
            if (!existing.Add(key)) continue;
            _db.ShowResearchEvidence.Add(new ShowResearchEvidenceEntity { ShowEditionId=showEditionId, EvidenceType="OWN_HISTORY", SourceName="Ancient Innovations show note", SourceUrl=key, Finding=Limit(note.NoteText,1200), Reliability="HIGH", Sentiment=InferInternalSentiment(note.NoteText), AppliesToYear=edition.Year, ResearchedAt=DateTimeOffset.UtcNow });
            added++;
        }
        var calibrations = await _db.ShowCalibrationRecords.AsNoTracking().Where(x => x.ShowEventId == edition.ShowEventId).OrderByDescending(x => x.Year).Take(12).ToListAsync(cancellationToken);
        foreach (var c in calibrations)
        {
            var key = $"internal://show-result/{c.Id}";
            if (!existing.Add(key)) continue;
            var finding = $"Ancient Innovations historical result: {c.Year} {c.PeriodLabel}; gross {(c.ActualGross.HasValue ? c.ActualGross.Value.ToString("C0") : "not recorded")}." + (c.IsDoNotReturn ? " DO NOT RETURN was recorded." : "");
            _db.ShowResearchEvidence.Add(new ShowResearchEvidenceEntity { ShowEditionId=showEditionId, EvidenceType="OWN_HISTORY", SourceName="Ancient Innovations historical result", SourceUrl=key, Finding=finding, Reliability="HIGH", Sentiment=c.IsDoNotReturn ? "NEGATIVE" : "NEUTRAL", AppliesToYear=c.Year, ResearchedAt=DateTimeOffset.UtcNow });
            added++;
        }

        foreach (var search in searches)
        {
            var hits = await SearchAsync(search.Query, 4, cancellationToken);
            foreach (var hit in hits)
            {
                if (IsExcluded(hit.Title, hit.Snippet, hit.Url) || !existing.Add(hit.Url)) continue;
                _db.ShowResearchEvidence.Add(new ShowResearchEvidenceEntity
                {
                    ShowEditionId = showEditionId,
                    EvidenceType = search.Type,
                    SourceName = Limit(hit.Title, 300),
                    SourceUrl = Limit(hit.Url, 1200),
                    Finding = $"Automated web lead — review source before treating as fact. {Limit(hit.Snippet, 1200)}",
                    Reliability = "UNRATED",
                    Sentiment = "NEUTRAL",
                    AppliesToYear = edition.Year,
                    ResearchedAt = DateTimeOffset.UtcNow
                });
                added++;
                if (search.Type == "APPLICATION") await CaptureApplicationPlatformAsync(edition, hit, cancellationToken);
            }
        }

        edition.ResearchStatus = added > 0 ? "NEEDS_MORE_RESEARCH" : "NEEDS_RESEARCH";
        edition.ResearchCompletedAt = null; // Collection is not the same thing as completed/verified research.
        edition.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(cancellationToken);

        var message = added > 0
            ? $"Collected {added} source leads across {searches.Length} decision-intelligence searches: attendance, vendor quality, application/fees, operations, restrictions, lodging/travel, maps and promoter evidence. Review/verify sources before freezing a forecast."
            : "No public-web source leads were returned. Keep the show in Needs Research and use direct/owner sources.";
        return new ShowResearchRunResult(searches.Length, added, message);
    }

    private async Task CaptureApplicationPlatformAsync(ShowEditionEntity edition, WebSearchHit hit, CancellationToken ct)
    {
        var platform = DetectPlatform(hit.Url);
        if (platform is null) return;
        var app = await _db.ShowApplications.FirstOrDefaultAsync(x => x.ShowEditionId == edition.Id && x.ShowVendorProfileId == null, ct);
        if (app is null) { app = new ShowApplicationEntity { ShowEditionId = edition.Id, Status = "DISCOVERED" }; _db.ShowApplications.Add(app); }
        // Prefer a recognized application-platform URL over a generic lead URL, but never submit anything.
        app.Platform = platform; app.ApplicationUrl = Limit(hit.Url,1600); app.LastCheckedAt = DateTimeOffset.UtcNow;
        app.ExternalStatus ??= "LINK_DISCOVERED";
        app.NextAction = edition.ApplicationOpenDate is not null && edition.ApplicationOpenDate > DateOnly.FromDateTime(DateTime.Today) ? $"Application not open yet; opens {edition.ApplicationOpenDate:MMM d, yyyy}." : "Review application details and verify deadline/fees before applying.";
    }

    private static string? DetectPlatform(string? url)
    {
        if (string.IsNullOrWhiteSpace(url)) return null;
        var u=url.ToLowerInvariant();
        if (u.Contains("zapplication.org")) return "ZAPPlication";
        if (u.Contains("eventeny.com")) return "Eventeny";
        if (u.Contains("callforentry.org")) return "CaFE";
        if (u.Contains("entrythingy.com")) return "EntryThingy";
        if (u.Contains("artcall.org")) return "ArtCall";
        if (u.Contains("showsubmit.com")) return "ShowSubmit";
        return null;
    }

    private static string InferInternalSentiment(string? text)
    {
        var t=(text??"").ToLowerInvariant();
        if (Regex.IsMatch(t,@"\b(failed|bad|poor|terrible|awful|never again|do not return|don't return|low sales|dead|sucked|sucks)\b")) return "NEGATIVE";
        if (Regex.IsMatch(t,@"\b(great|excellent|strong|packed|best|good traffic|sold out|return|successful)\b")) return "POSITIVE";
        return "NEUTRAL";
    }

    public async Task<ShowDiscoveryRunResult> DiscoverCandidatesAsync(long? vendorProfileId, int targetYear, int targetMonth, CancellationToken cancellationToken = default)
    {
        // One Finder click must never sit spinning for minutes.
        // Give external discovery a bounded window; preserve whatever good results are found inside it.
        _discoveryDeadline = DateTimeOffset.UtcNow.AddSeconds(15);
        _discoveryPageBudget = 8;

        var profile = vendorProfileId is null ? null : await _db.ShowVendorProfiles.AsNoTracking().FirstOrDefaultAsync(x => x.Id == vendorProfileId, cancellationToken);
        var monthName = new DateTime(targetYear, Math.Clamp(targetMonth, 1, 12), 1).ToString("MMMM");
        var geography = profile is null
            ? "Midwest"
            : !string.IsNullOrWhiteSpace(profile.HomeCity) && !string.IsNullOrWhiteSpace(profile.HomeState)
                ? $"{profile.HomeCity} {profile.HomeState}"
                : profile.HomeState ?? "Midwest";

        // Clean up stale low-quality web cards from older discovery logic for this exact vendor/month.
        var staleLeads = await _db.ShowDiscoveryLeads
            .Where(x => x.TargetYear == targetYear
                     && x.TargetMonth == targetMonth
                     && x.ShowVendorProfileId == vendorProfileId
                     && x.Status == "NEW"
                     && !x.SearchQuery.StartsWith("DATABASE:")
                     && !x.SearchQuery.StartsWith(OperationalBoundaryRules.ScoutLeadPrefix))
            .ToListAsync(cancellationToken);
        foreach (var stale in staleLeads)
        {
            var pseudo = new WebSearchHit(stale.Title ?? "", stale.Url ?? "", stale.Snippet ?? "", stale.SearchQuery ?? "");
            if (IsExcluded(pseudo.Title,pseudo.Snippet,pseudo.Url) || !LooksLikeSpecificShow(pseudo.Title,pseudo.Snippet,pseudo.Url))
                stale.Status = "IGNORED_AUTO_GENERIC";
        }
        if (staleLeads.Any(x => x.Status == "IGNORED_AUTO_GENERIC")) await _db.SaveChangesAsync(cancellationToken);

        // Discovery leads are vendor placements, not globally-owned URLs.
        // The same good show must be allowed to be evaluated for Abby, Sonya, Blake, etc.
        // Only suppress duplicates inside this vendor/month search.
        var existingLeadUrls = await _db.ShowDiscoveryLeads
            .Where(x => x.TargetYear == targetYear
                     && x.TargetMonth == targetMonth
                     && x.ShowVendorProfileId == vendorProfileId
                     && (x.Status == "NEW" || x.Status == "QUEUED"))
            .Select(x => x.Url)
            .ToListAsync(cancellationToken);
        var existing = new HashSet<string>(existingLeadUrls, StringComparer.OrdinalIgnoreCase);
        var existingShowKeys = (await _db.ShowEditions.AsNoTracking().Include(x => x.ShowEvent)
            .Where(x => x.Year == targetYear)
            .Select(x => x.ShowEvent.Name)
            .ToListAsync(cancellationToken))
            .Select(DatabaseKey).ToHashSet(StringComparer.OrdinalIgnoreCase);

        // Database first, part 1: recur known 2025/2026 Command Center shows into the 2027 candidate pool.
        var historicalEditions = await _db.ShowEditions.AsNoTracking().Include(x => x.ShowEvent)
            .Where(x => x.Year < targetYear && x.Year >= targetYear - 2 && x.StartDate != null && x.StartDate.Value.Month == targetMonth)
            .OrderByDescending(x => x.Year).ToListAsync(cancellationToken);
        var historicalAdded = 0;
        foreach (var edition in historicalEditions.GroupBy(x => DatabaseKey(x.ShowEvent.Name)).Select(g => g.OrderByDescending(x => x.Year).First())
                     .OrderByDescending(x => HistoricalVendorFitScore(profile, x.ShowEvent)))
        {
            if (historicalAdded >= 12) break;
            if (existingShowKeys.Contains(DatabaseKey(edition.ShowEvent.Name))) continue;
            var uniqueUrl = $"database://historical/{edition.ShowEventId}/{targetYear}";
            if (!existing.Add(uniqueUrl)) continue;
            var calibration = await _db.ShowCalibrationRecords.AsNoTracking()
                .Where(x => x.ShowEventId == edition.ShowEventId)
                .OrderByDescending(x => x.Year).ThenByDescending(x => x.ActualGross).FirstOrDefaultAsync(cancellationToken);
            var prior = calibration?.ActualGross is > 0 ? $" · prior gross {calibration.ActualGross.Value:C0} ({calibration.Year})" : "";
            var returnNote = calibration?.IsDoNotReturn == true ? " · WARNING: historical do-not-return flag" : "";
            _db.ShowDiscoveryLeads.Add(new ShowDiscoveryLeadEntity
            {
                ShowVendorProfileId = vendorProfileId, TargetYear = targetYear, TargetMonth = targetMonth,
                Title = Limit(edition.ShowEvent.Name,500), Url = uniqueUrl,
                Snippet = Limit($"HISTORICAL COMMAND CENTER · recurring show from {edition.Year} · {edition.ShowEvent.City}, {edition.ShowEvent.State}{prior}{returnNote} · verify {targetYear} dates/application before adding.",1500),
                SearchQuery = $"DATABASE:HISTORICAL_RECURRING:{monthName}:{profile?.VendorName ?? "GENERAL"}",
                Status = "NEW", DiscoveredAt = DateTimeOffset.UtcNow
            });
            historicalAdded++;
        }

        // Database first, part 2: use the Jan-Jun 2027 intelligence database before asking the public web.
        var databaseCandidates = await LoadDatabaseCandidatesAsync(targetYear, targetMonth, cancellationToken);
        databaseCandidates = databaseCandidates
            .Where(x => !existingShowKeys.Contains(DatabaseKey(x.Name)))
            .OrderByDescending(x => VendorFitScore(profile, x))
            .ThenByDescending(x => x.OverallScore ?? 0)
            .ThenBy(x => x.Name)
            .ToList();

        var databaseAdded = 0;
        foreach (var candidate in databaseCandidates.Take(Math.Max(0,12-historicalAdded)))
        {
            var url = string.IsNullOrWhiteSpace(candidate.Url)
                ? $"database://event-intelligence/{Uri.EscapeDataString(candidate.EventId ?? candidate.Name)}"
                : candidate.Url;
            if (!existing.Add(url)) continue;
            _db.ShowDiscoveryLeads.Add(new ShowDiscoveryLeadEntity
            {
                ShowVendorProfileId = vendorProfileId,
                TargetYear = targetYear,
                TargetMonth = targetMonth,
                Title = Limit(candidate.Name, 500),
                Url = Limit(url, 1600),
                Snippet = Limit(BuildDatabaseSnippet(candidate, profile), 1500),
                SearchQuery = $"DATABASE:JUNE_2027:{monthName}:{profile?.VendorName ?? "GENERAL"}",
                Status = "NEW",
                DiscoveredAt = DateTimeOffset.UtcNow
            });
            databaseAdded++;
        }

        // Web is now gap-filling, not the primary database.
        var webAdded = 0;
        var searches = 0;
        if (historicalAdded + databaseAdded < 8)
        {
            // Source Registry: discovery sources are allowed to FIND names, but directory/social pages
            // never become the candidate. They are resolved to a specific event/official/application page first.
            var plans = BuildDiscoveryPlans(monthName, targetYear, geography, profile);
            foreach (var plan in plans)
            {
                if (DateTimeOffset.UtcNow >= _discoveryDeadline) break;
                searches++;
                var hits = await SearchAsync(plan.Query, plan.MaxResults, cancellationToken);
                foreach (var rawHit in hits)
                {
                    if (DateTimeOffset.UtcNow >= _discoveryDeadline) break;
                    if (historicalAdded + databaseAdded + webAdded >= 12) break;

                    var expanded = await ExpandDiscoveryHitAsync(rawHit, plan, monthName, targetYear, targetMonth, geography, cancellationToken);
                    foreach (var hit in expanded)
                    {
                        if (historicalAdded + databaseAdded + webAdded >= 12) break;
                        if (IsExcluded(hit.Title, hit.Snippet, hit.Url)
                            || IsForbiddenCandidateUrl(hit.Url)
                            || IsGenericCandidateTitle(hit.Title)
                            || !LooksLikeSpecificShow(hit.Title, hit.Snippet, hit.Url)
                            || !MatchesDiscoveryIntent(hit, monthName, geography)
                            || !existing.Add(hit.Url)) continue;

                        var verificationText = $"{hit.Title} {hit.Snippet}";
                        var parsedLocation = ParseLocation(verificationText);
                        var parsedDates = ParseEventDates(verificationText, targetYear, targetMonth);
                        if (string.IsNullOrWhiteSpace(parsedLocation.State) || parsedDates.Start is null) continue;

                        var cleanName = CleanTitle(hit.Title);
                        if (existingShowKeys.Contains(DatabaseKey(cleanName))) continue;

                        _db.ShowDiscoveryLeads.Add(new ShowDiscoveryLeadEntity
                        {
                            ShowVendorProfileId = vendorProfileId,
                            TargetYear = targetYear,
                            TargetMonth = targetMonth,
                            Title = Limit(cleanName, 500),
                            Url = Limit(hit.Url, 1600),
                            Snippet = Limit($"{plan.Label} discovery → extracted and verified event page. {hit.Snippet}", 1500),
                            SearchQuery = Limit($"SOURCE:{plan.Source}:{plan.Query}", 1000),
                            Status = "NEW",
                            DiscoveredAt = DateTimeOffset.UtcNow
                        });
                        webAdded++;
                    }
                }
            }
        }

        await _db.SaveChangesAsync(cancellationToken);
        var total = historicalAdded + databaseAdded + webAdded;
        var externalTimedOut = DateTimeOffset.UtcNow >= _discoveryDeadline || _discoveryPageBudget <= 0;
        return new ShowDiscoveryRunResult(searches, total,
            total > 0
                ? $"Finder finished: {historicalAdded} recurring historical show(s), {databaseAdded} intelligence-database candidate(s), and {webAdded} verified external event(s). External discovery is time-bounded so one click cannot spin for minutes."
                : externalTimedOut
                    ? $"Finder completed its bounded external-search window for {profile?.VendorName ?? "this vendor"} / {monthName}. No external event passed the real-show gate this cycle."
                    : $"No NEW {monthName} placement leads were created for {profile?.VendorName ?? "this vendor"} after duplicate and real-event verification.");
    }

    private sealed record DatabaseCandidate(string? EventId, int Year, string Month, string Name, string? City, string? State, string? EventType, string? Status, string? Priority, string? Recommended, string? Promoter, string? Url, int? OverallScore, string? WhyWeBelong, string? Notes, string? CustomerTags);

    private async Task<List<DatabaseCandidate>> LoadDatabaseCandidatesAsync(int targetYear, int targetMonth, CancellationToken cancellationToken)
    {
        var path = Path.Combine(_env.WebRootPath, "data", "show-intelligence", "event-intelligence-june-2027.json");
        if (!File.Exists(path)) return [];
        try
        {
            var json = await File.ReadAllTextAsync(path, cancellationToken);
            using var doc = JsonDocument.Parse(json);
            var monthName = new DateTime(targetYear, Math.Clamp(targetMonth,1,12),1).ToString("MMMM");
            var list = new List<DatabaseCandidate>();
            foreach (var el in doc.RootElement.EnumerateArray())
            {
                var year = JsonInt(el, "Year") ?? 0;
                var month = JsonText(el, "Month") ?? "";
                var name = JsonText(el, "Event Name");
                if (year != targetYear || !month.Equals(monthName, StringComparison.OrdinalIgnoreCase) || string.IsNullOrWhiteSpace(name)) continue;
                list.Add(new DatabaseCandidate(
                    JsonText(el,"Event ID"), year, month, name!,
                    JsonText(el,"City"), JsonText(el,"State"), JsonText(el,"Event Type"),
                    JsonText(el,"Status"), JsonText(el,"Priority"), JsonText(el,"Recommended"),
                    JsonText(el,"Promoter"), FirstNonBlank(JsonText(el,"Website / Application Link"),JsonText(el,"Source URL")),
                    JsonInt(el,"Overall Score"), JsonText(el,"Why We Belong"), JsonText(el,"Notes"), JsonText(el,"Customer Tags")));
            }
            return list;
        }
        catch { return []; }
    }

    private static string BuildDatabaseSnippet(DatabaseCandidate c, ShowVendorProfileEntity? profile)
    {
        var pieces = new List<string>
        {
            "SHOW INTELLIGENCE DATABASE",
            $"{c.Month} {c.Year}",
            string.Join(", ", new[]{c.City,c.State}.Where(x=>!string.IsNullOrWhiteSpace(x))),
            c.EventType ?? "",
            !string.IsNullOrWhiteSpace(c.Priority) ? $"Priority {c.Priority}" : "",
            c.OverallScore is not null ? $"database score {c.OverallScore}/100" : "",
            !string.IsNullOrWhiteSpace(c.Recommended) ? $"recommended {c.Recommended}" : "",
            !string.IsNullOrWhiteSpace(c.Promoter) ? $"promoter {c.Promoter}" : "",
            !string.IsNullOrWhiteSpace(c.WhyWeBelong) ? c.WhyWeBelong! : "",
            profile is not null ? $"Vendor fit: {VendorFitLabel(profile,c)} (rank {VendorFitScore(profile,c)})" : ""
        };
        return string.Join(" · ", pieces.Where(x=>!string.IsNullOrWhiteSpace(x)));
    }

    private static int HistoricalVendorFitScore(ShowVendorProfileEntity? profile, ShowEventEntity ev)
    {
        var score = 50;
        if (profile is null) return score;
        if (!string.IsNullOrWhiteSpace(profile.HomeState) && !string.IsNullOrWhiteSpace(ev.State))
        {
            if (profile.HomeState.Equals(ev.State,StringComparison.OrdinalIgnoreCase)) score += 30;
            else if (Adjacent(profile.HomeState,ev.State)) score += 12;
            else if ((profile.MaxTravelHours ?? 0) <= 2) score -= 25;
        }
        return score;
    }

    private static int VendorFitScore(ShowVendorProfileEntity? profile, DatabaseCandidate c)
    {
        var score = c.OverallScore ?? 0;
        if (profile is null) return score;
        if (!string.IsNullOrWhiteSpace(profile.HomeState) && !string.IsNullOrWhiteSpace(c.State))
        {
            if (profile.HomeState.Equals(c.State,StringComparison.OrdinalIgnoreCase)) score += 30;
            else if (Adjacent(profile.HomeState,c.State)) score += 12;
            else if ((profile.MaxTravelHours ?? 0) <= 2) score -= 25;
        }
        if (profile.IsFullTimeVendor) score += 5;
        if (profile.CanCamp && (c.CustomerTags ?? "").Contains("Tourist",StringComparison.OrdinalIgnoreCase)) score += 3;
        return score;
    }

    private static string VendorFitLabel(ShowVendorProfileEntity profile, DatabaseCandidate c)
    {
        if (!string.IsNullOrWhiteSpace(profile.HomeState) && profile.HomeState.Equals(c.State,StringComparison.OrdinalIgnoreCase)) return "home-state candidate";
        if (!string.IsNullOrWhiteSpace(profile.HomeState) && !string.IsNullOrWhiteSpace(c.State) && Adjacent(profile.HomeState,c.State)) return "adjacent-state candidate";
        if ((profile.MaxTravelHours ?? 0) <= 2) return "distance needs verification";
        return "travel fit needs verification";
    }

    private static bool Adjacent(string a, string b)
    {
        var map = new Dictionary<string,string[]>(StringComparer.OrdinalIgnoreCase)
        {
            ["IN"]=["IL","MI","OH","KY"], ["OH"]=["IN","MI","PA","WV","KY"], ["KY"]=["IN","OH","WV","VA","TN","MO","IL"],
            ["IL"]=["IN","WI","IA","MO","KY"], ["MI"]=["IN","OH","WI"], ["WI"]=["MI","IL","IA","MN"],
            ["MO"]=["IL","IA","NE","KS","OK","AR","TN","KY"], ["FL"]=["GA","AL"]
        };
        return map.TryGetValue(a.Trim(), out var neighbors) && neighbors.Contains(b.Trim(),StringComparer.OrdinalIgnoreCase);
    }

    private static string DatabaseKey(string? value) => Regex.Replace((value ?? "").ToLowerInvariant(), @"[^a-z0-9]+", "");
    private static string? JsonText(JsonElement el,string name)
    {
        if (!el.TryGetProperty(name,out var p) || p.ValueKind is JsonValueKind.Null or JsonValueKind.Undefined) return null;
        return p.ValueKind==JsonValueKind.String ? p.GetString() : p.ToString();
    }
    private static int? JsonInt(JsonElement el,string name)
    {
        var s=JsonText(el,name); return int.TryParse(s,NumberStyles.Any,CultureInfo.InvariantCulture,out var i)?i:null;
    }
    private static string? FirstNonBlank(params string?[] values)=>values.FirstOrDefault(x=>!string.IsNullOrWhiteSpace(x));

    private sealed record DiscoveryPlan(string Source, string Label, string Query, int MaxResults = 6, bool ResolveToEvent = false);

    private static List<DiscoveryPlan> BuildDiscoveryPlans(string monthName, int year, string geography, ShowVendorProfileEntity? profile)
    {
        var travel = profile?.MaxTravelHours is > 0 ? $" within about {profile.MaxTravelHours:0.#} hours" : "";
        var vendorRules = string.IsNullOrWhiteSpace(profile?.SchedulingRequirements) ? "" : $" {profile.SchedulingRequirements}";
        var intent = $"handmade artisan craft art festival fair market {monthName} {year} {geography}{travel}";

        return new()
        {
            new("ZAPP", "ZAPPlication", $"site:zapplication.org {intent}", 4, true),
            new("EVENTENY", "Eventeny", $"site:eventeny.com {intent} vendor application", 4, true),
            new("FESTIVALNET", "FestivalNet", $"site:festivalnet.com {intent}", 4, true),
            new("SUNSHINE", "Sunshine Artist", $"site:sunshineartist.com {intent}", 4, true),

            // Vetted vendor intelligence is a lead/evidence source, never the final candidate URL.
            new("VENDOR_INTEL", "vetted vendor-review community", $"\"Art Fair Insiders\" {monthName} {geography} festival fair", 4, true),
            new("VENDOR_INTEL", "vetted vendor-review community", $"\"Art Fair Review\" {monthName} {geography} festival fair", 4, true),

            // Promoters and official/local organizations catch strong shows missed by national platforms.
            new("PROMOTER", "promoter/organizer", $"{intent} promoter vendor application", 6),
            new("OFFICIAL_LOCAL", "official tourism/chamber/downtown/arts source", $"{intent} chamber tourism downtown arts council vendor", 6),

            // AI-style reasoning expansion: search the buyer/event families that already fit Ancient Innovations
            // instead of only the literal phrase 'craft show'.
            new("AI_EXPANSION", "Brain reasoning expansion", $"{monthName} {year} Celtic Irish Scottish festival artisans {geography}", 5),
            new("AI_EXPANSION", "Brain reasoning expansion", $"{monthName} {year} heritage historic medieval renaissance festival artisan vendors {geography}", 5),
            new("AI_EXPANSION", "Brain reasoning expansion", $"{monthName} {year} juried fine craft art festival handmade {geography}", 5),
            new("AI_EXPANSION", "Brain reasoning expansion", $"{monthName} {year} book literary fantasy festival makers vendors {geography}", 5),
            new("OPEN_WEB", "open-web gap fill", $"{intent}{vendorRules}", 6)
        };
    }

    private async Task<List<WebSearchHit>> ExpandDiscoveryHitAsync(
        WebSearchHit rawHit,
        DiscoveryPlan plan,
        string monthName,
        int year,
        int targetMonth,
        string geography,
        CancellationToken ct)
    {
        var results = new List<WebSearchHit>();

        if (!IsForbiddenCandidateUrl(rawHit.Url) && !IsGenericCandidateTitle(rawHit.Title))
        {
            var hydrated = await HydrateHitFromPageAsync(rawHit, ct);
            if (hydrated is not null) results.Add(hydrated);
        }

        if (plan.ResolveToEvent || IsForbiddenCandidateUrl(rawHit.Url))
        {
            foreach (var child in await ExtractEventLinksFromSourcePageAsync(rawHit, ct))
            {
                var hydrated = await HydrateHitFromPageAsync(child, ct);
                if (hydrated is null) continue;

                var loc = ParseLocation($"{hydrated.Title} {hydrated.Snippet}");
                var dates = ParseEventDates($"{hydrated.Title} {hydrated.Snippet}", year, targetMonth);
                if (string.IsNullOrWhiteSpace(loc.State) || dates.Start is null) continue;

                results.Add(hydrated);
                if (results.Count >= 4) break;
            }
        }

        if (results.Count == 0 && !IsGenericCandidateTitle(rawHit.Title))
        {
            var candidateName = CleanTitle(rawHit.Title);
            candidateName = Regex.Replace(candidateName,
                @"\b(FestivalNet|Sunshine Artist|ZAPPlication|ZAPP|Eventeny|Art Fair Insiders|Art Fair Review)\b",
                "", RegexOptions.IgnoreCase).Trim(' ', '-', '–', '—', '|', ':');

            if (candidateName.Length >= 6)
            {
                var verifyQuery = $"\"{candidateName}\" {year} {monthName} {geography} official festival vendor application";
                foreach (var verify in await SearchAsync(verifyQuery, 6, ct))
                {
                    if (IsForbiddenCandidateUrl(verify.Url) || IsGenericCandidateTitle(verify.Title)) continue;
                    var hydrated = await HydrateHitFromPageAsync(verify, ct);
                    if (hydrated is not null) results.Add(hydrated);
                }
            }
        }

        return results
            .GroupBy(x => x.Url, StringComparer.OrdinalIgnoreCase)
            .Select(x => x.First())
            .Take(4)
            .ToList();
    }

    private async Task<WebSearchHit?> HydrateHitFromPageAsync(WebSearchHit hit, CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(hit.Url) || IsForbiddenCandidateUrl(hit.Url)) return null;
        var page = await FetchPageAsync(hit.Url, ct);
        if (page is null) return null;

        var title = string.IsNullOrWhiteSpace(page.Value.Title) ? CleanTitle(hit.Title) : CleanTitle(page.Value.Title);
        if (IsGenericCandidateTitle(title)) title = CleanTitle(hit.Title);

        var snippet = Limit($"{hit.Snippet} {page.Value.Text}", 5000);
        return new WebSearchHit(title, hit.Url, snippet, hit.Query);
    }

    private async Task<List<WebSearchHit>> ExtractEventLinksFromSourcePageAsync(WebSearchHit source, CancellationToken ct)
    {
        var page = await FetchPageAsync(source.Url, ct);
        if (page is null) return [];

        var list = new List<WebSearchHit>();
        foreach (var link in page.Value.Links)
        {
            if (string.IsNullOrWhiteSpace(link.Url) || string.IsNullOrWhiteSpace(link.Text)) continue;
            if (IsGenericCandidateTitle(link.Text)) continue;
            if (!LooksLikeSpecificShow(link.Text, link.Text, link.Url)) continue;

            var t = link.Text.ToLowerInvariant();
            if (t is "home" or "login" or "sign in" or "register" or "search" or "events") continue;
            if (t.Contains("privacy") || t.Contains("terms") || t.Contains("contact us")) continue;

            list.Add(new WebSearchHit(
                CleanTitle(link.Text),
                link.Url,
                $"Discovered from {source.Title}. Source page: {source.Url}",
                source.Query));

            if (list.Count >= 6) break;
        }
        return list;
    }

    private async Task<(string Title, string Text, List<(string Text, string Url)> Links)?> FetchPageAsync(string url, CancellationToken ct)
    {
        if (_discoveryDeadline != DateTimeOffset.MinValue)
        {
            if (DateTimeOffset.UtcNow >= _discoveryDeadline || _discoveryPageBudget <= 0) return null;
            _discoveryPageBudget--;
        }

        try
        {
            var client = _httpClientFactory.CreateClient();
            client.Timeout = TimeSpan.FromSeconds(4);
            client.DefaultRequestHeaders.UserAgent.ParseAdd("Mozilla/5.0 (compatible; AncientInnovationsShowResearch/1.0)");
            var html = await client.GetStringAsync(url, ct);
            if (string.IsNullOrWhiteSpace(html)) return null;

            var titleMatch = Regex.Match(html, @"<title[^>]*>(?<t>.*?)</title>", RegexOptions.IgnoreCase | RegexOptions.Singleline);
            var title = titleMatch.Success ? CleanHtml(titleMatch.Groups["t"].Value) : "";

            var text = Regex.Replace(html, @"<script(?![^>]*application/ld\+json)[^>]*>.*?</script>", " ", RegexOptions.IgnoreCase | RegexOptions.Singleline);
            text = Regex.Replace(text, @"<style[^>]*>.*?</style>", " ", RegexOptions.IgnoreCase | RegexOptions.Singleline);
            text = CleanHtml(text);
            text = Regex.Replace(text, @"\s+", " ").Trim();

            var links = new List<(string Text, string Url)>();
            var linkRx = new Regex(@"<a[^>]*href\s*=\s*[""'](?<u>[^""'#]+)[""'][^>]*>(?<t>.*?)</a>", RegexOptions.IgnoreCase | RegexOptions.Singleline);
            if (Uri.TryCreate(url, UriKind.Absolute, out var baseUri))
            {
                foreach (Match m in linkRx.Matches(html))
                {
                    var raw = WebUtility.HtmlDecode(m.Groups["u"].Value.Trim());
                    var label = CleanHtml(m.Groups["t"].Value);
                    if (string.IsNullOrWhiteSpace(label) || raw.StartsWith("mailto:", StringComparison.OrdinalIgnoreCase) || raw.StartsWith("javascript:", StringComparison.OrdinalIgnoreCase)) continue;
                    if (!Uri.TryCreate(baseUri, raw, out var absolute)) continue;
                    links.Add((label, absolute.ToString()));
                }
            }

            return (title, Limit(text, 20000), links);
        }
        catch
        {
            return null;
        }
    }

    private static bool IsForbiddenCandidateUrl(string? url)
    {
        if (string.IsNullOrWhiteSpace(url)) return true;
        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri)) return true;
        var host = uri.Host.ToLowerInvariant();
        var path = uri.AbsolutePath.ToLowerInvariant();

        if (host.Contains("facebook.com") || host.Contains("pinterest.") || host.Contains("yelp.")) return true;
        if (host.Contains("artscraftsshowbusiness.com") || host.Contains("fairsandfestivals.net") || host.Contains("10times.com")) return true;

        // FestivalNet/Sunshine/etc. are excellent discovery sources but list/category/search pages are not event records.
        if (host.Contains("festivalnet.com") && (path is "/" || path.Contains("craft-shows") || path.Contains("festivals") || path.Contains("events"))) return true;
        if (host.Contains("sunshineartist.com") && (path is "/" || path.EndsWith("/events") || path.Contains("/events?"))) return true;

        return false;
    }

    private static bool IsGenericCandidateTitle(string? title)
    {
        var t = CleanTitle(title ?? "").ToLowerInvariant();
        if (t.Length < 6) return true;
        var bad = new[]
        {
            "midwest art, craft, vendor, fairs, and shows",
            "craft shows, art & craft fairs, street fairs and festivals",
            "craft shows and craft fairs",
            "art shows and craft fairs",
            "independent bookstore day",
            "spring programs",
            "event calendar",
            "events calendar",
            "upcoming events",
            "vendor events",
            "show list",
            "festival guide",
            "things to do",
            "find a craft",
            "fairs and festivals"
        };
        if (bad.Any(x => t.Contains(x))) return true;
        if (Regex.IsMatch(t, @"\b(watch|programs|calendar|directory|group)\s*$", RegexOptions.IgnoreCase)) return true;
        return false;
    }

    private static bool NamesProbablyMatch(string requested, string resultTitle, string snippet)
    {
        static HashSet<string> Tokens(string s) => Regex.Matches((s ?? "").ToLowerInvariant(), @"[a-z0-9]+")
            .Select(m => m.Value)
            .Where(x => x.Length > 2 && x is not ("the" or "and" or "festival" or "fair" or "show" or "event" or "official" or "2027"))
            .ToHashSet();
        var a = Tokens(requested);
        var b = Tokens(resultTitle + " " + snippet);
        if (a.Count == 0) return false;
        var overlap = a.Count(x => b.Contains(x));
        return overlap >= Math.Min(2, a.Count) || (a.Count == 1 && overlap == 1);
    }

    private static string ChooseCleanerEventName(string requested, string result)
    {
        var r = CleanTitle(result);
        r = Regex.Replace(r, @"\s*[-|–—]\s*(Official.*|Home.*|Eventeny.*|ZAPP.*|FestivalNet.*)$", "", RegexOptions.IgnoreCase).Trim();
        return r.Length >= 6 && !IsGenericCandidateTitle(r) ? r : CleanTitle(requested);
    }

    private static (string? City, string? State) ParseLocation(string text)
    {
        if (string.IsNullOrWhiteSpace(text)) return (null, null);
        var m = Regex.Match(text, @"\b(?<city>[A-Z][A-Za-z.'\- ]{2,35}),\s*(?<state>AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY)\b");
        if (m.Success) return (m.Groups["city"].Value.Trim(), m.Groups["state"].Value.ToUpperInvariant());

        var full = new Dictionary<string,string>(StringComparer.OrdinalIgnoreCase)
        {
            ["Indiana"]="IN",["Ohio"]="OH",["Kentucky"]="KY",["Illinois"]="IL",["Michigan"]="MI",["Wisconsin"]="WI",
            ["Missouri"]="MO",["Iowa"]="IA",["Florida"]="FL",["Kansas"]="KS",["Tennessee"]="TN",["Pennsylvania"]="PA",
            ["West Virginia"]="WV",["Virginia"]="VA",["Georgia"]="GA",["North Carolina"]="NC",["South Carolina"]="SC",
            ["Minnesota"]="MN",["Arkansas"]="AR"
        };
        foreach (var kv in full)
            if (Regex.IsMatch(text, $@"\b{Regex.Escape(kv.Key)}\b", RegexOptions.IgnoreCase))
                return (null, kv.Value);
        return (null, null);
    }

    private static (DateOnly? Start, DateOnly? End) ParseEventDates(string text, int targetYear, int targetMonth)
    {
        if (string.IsNullOrWhiteSpace(text)) return (null, null);
        var months = new Dictionary<string,int>(StringComparer.OrdinalIgnoreCase)
        {
            ["January"]=1,["Jan"]=1,["February"]=2,["Feb"]=2,["March"]=3,["Mar"]=3,["April"]=4,["Apr"]=4,
            ["May"]=5,["June"]=6,["Jun"]=6,["July"]=7,["Jul"]=7,["August"]=8,["Aug"]=8,
            ["September"]=9,["Sep"]=9,["Sept"]=9,["October"]=10,["Oct"]=10,["November"]=11,["Nov"]=11,["December"]=12,["Dec"]=12
        };
        var rx = new Regex(@"\b(?<mon>January|Jan|February|Feb|March|Mar|April|Apr|May|June|Jun|July|Jul|August|Aug|September|Sept|Sep|October|Oct|November|Nov|December|Dec)\.?\s+(?<d1>\d{1,2})(?:\s*[-–—]\s*(?<d2>\d{1,2}))?(?:,\s*|\s+)(?<y>20\d{2})\b", RegexOptions.IgnoreCase);
        foreach (Match m in rx.Matches(text))
        {
            if (!int.TryParse(m.Groups["y"].Value, out var y) || y != targetYear) continue;
            var mo = months[m.Groups["mon"].Value];
            if (mo != targetMonth) continue;
            var d1 = int.Parse(m.Groups["d1"].Value);
            var d2 = m.Groups["d2"].Success ? int.Parse(m.Groups["d2"].Value) : d1;
            try { return (new DateOnly(y, mo, d1), new DateOnly(y, mo, d2)); } catch { }
        }

        // Numeric US dates.
        var numeric = new Regex(@"\b(?<m>\d{1,2})/(?<d>\d{1,2})/(?<y>20\d{2})\b");
        foreach (Match m in numeric.Matches(text))
        {
            var mo=int.Parse(m.Groups["m"].Value); var d=int.Parse(m.Groups["d"].Value); var y=int.Parse(m.Groups["y"].Value);
            if (y==targetYear && mo==targetMonth) { try { var dt=new DateOnly(y,mo,d); return (dt,dt); } catch {} }
        }
        // ISO / JSON-LD dates (common on Eventeny/ZAPP/official event pages).
        var iso = new Regex(@"\b(?<y>20\d{2})-(?<m>\d{2})-(?<d>\d{2})\b");
        foreach (Match m in iso.Matches(text))
        {
            var y=int.Parse(m.Groups["y"].Value); var mo=int.Parse(m.Groups["m"].Value); var d=int.Parse(m.Groups["d"].Value);
            if (y==targetYear && mo==targetMonth) { try { var dt=new DateOnly(y,mo,d); return (dt,dt); } catch {} }
        }

        return (null, null);
    }

    private async Task<List<WebSearchHit>> SearchAsync(string query, int maxResults, CancellationToken cancellationToken)
    {
        var client = _httpClientFactory.CreateClient();
        client.Timeout = TimeSpan.FromSeconds(4);
        client.DefaultRequestHeaders.UserAgent.ParseAdd("Mozilla/5.0 (compatible; AncientInnovationsShowResearch/1.0)");

        var hits = new List<WebSearchHit>();
        try
        {
            var url = "https://www.bing.com/search?q=" + Uri.EscapeDataString(query) + "&count=" + Math.Max(maxResults, 8);
            var html = await client.GetStringAsync(url, cancellationToken);
            hits.AddRange(ParseBing(html, query, maxResults));
        }
        catch { /* fall through to DuckDuckGo */ }

        if (hits.Count == 0)
        {
            try
            {
                var url = "https://html.duckduckgo.com/html/?q=" + Uri.EscapeDataString(query);
                var html = await client.GetStringAsync(url, cancellationToken);
                hits.AddRange(ParseDuckDuckGo(html, query, maxResults));
            }
            catch { }
        }
        return hits.Take(maxResults).ToList();
    }

    private static IEnumerable<WebSearchHit> ParseBing(string html, string query, int max)
    {
        var rx = new Regex("<li[^>]*class=\\\"b_algo\\\"[^>]*>.*?<h2>\\s*<a[^>]*href=\\\"(?<url>https?[^\\\"]+)\\\"[^>]*>(?<title>.*?)</a>.*?(?:<p>(?<snippet>.*?)</p>)?", RegexOptions.IgnoreCase | RegexOptions.Singleline);
        foreach (Match m in rx.Matches(html).Cast<Match>().Take(max))
        {
            var url = WebUtility.HtmlDecode(m.Groups["url"].Value);
            var title = CleanHtml(m.Groups["title"].Value);
            var snippet = CleanHtml(m.Groups["snippet"].Value);
            if (!string.IsNullOrWhiteSpace(url) && !string.IsNullOrWhiteSpace(title)) yield return new WebSearchHit(title, url, snippet, query);
        }
    }

    private static IEnumerable<WebSearchHit> ParseDuckDuckGo(string html, string query, int max)
    {
        var rx = new Regex("<a[^>]*class=\\\"result__a\\\"[^>]*href=\\\"(?<url>[^\\\"]+)\\\"[^>]*>(?<title>.*?)</a>.*?<a[^>]*class=\\\"result__snippet[^\\\"]*\\\"[^>]*>(?<snippet>.*?)</a>", RegexOptions.IgnoreCase | RegexOptions.Singleline);
        foreach (Match m in rx.Matches(html).Cast<Match>().Take(max))
        {
            var rawUrl = WebUtility.HtmlDecode(m.Groups["url"].Value);
            var url = DecodeDuckUrl(rawUrl);
            var title = CleanHtml(m.Groups["title"].Value);
            var snippet = CleanHtml(m.Groups["snippet"].Value);
            if (!string.IsNullOrWhiteSpace(url) && !string.IsNullOrWhiteSpace(title)) yield return new WebSearchHit(title, url, snippet, query);
        }
    }

    private static string DecodeDuckUrl(string raw)
    {
        if (raw.StartsWith("//")) raw = "https:" + raw;
        if (Uri.TryCreate(raw, UriKind.Absolute, out var uri) && uri.Host.Contains("duckduckgo.com", StringComparison.OrdinalIgnoreCase))
        {
            var query = uri.Query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries);
            foreach (var part in query)
            {
                var kv = part.Split('=', 2);
                if (kv.Length == 2 && kv[0] == "uddg") return Uri.UnescapeDataString(kv[1].Replace('+', ' '));
            }
        }
        return raw;
    }

    private static bool IsExcluded(string title, string snippet, string url)
    {
        var text = $"{title} {snippet} {url}".ToLowerInvariant();
        if (text.Contains("blue ribbon events")) return true;
        var conventionTerms = new[] { " comic con", "comic-con", "anime convention", "gaming convention", "fan convention", " cosplay con", " convention center convention" };
        if (conventionTerms.Any(text.Contains)) return true;
        // Discovery must surface actual events, not generic directories or social/search list pages.
        var directoryTerms = new[] { "find a craft fair", "craft shows and craft fairs", "events near me", "upcoming craft fairs", "festival directory", "facebook group", "pinterest", "event calendar", "all events", "art fairs 2026", "art fairs 2027", "fairs and festivals", "festivalnet", "craftmaster", "craft fair calendar", "events calendar", "things to do", "event listing", "event listings", "upcoming events" };
        if (directoryTerms.Any(text.Contains)) return true;
        var host = Uri.TryCreate(url, UriKind.Absolute, out var uri) ? uri.Host.ToLowerInvariant() : string.Empty;
        if (host.Contains("facebook.com") || host.Contains("pinterest.") || host.Contains("yelp.") || host.Contains("fairsandfestivals.net") || host.Contains("festivalnet.com") || host.Contains("10times.com")) return true;
        // Require signals that the result is a specific event, not a generic search/listing page.
        var eventSignals = new[] { "festival", "fair", "market", "oktoberfest", "renaissance", "craft show", "art show", "holiday show", "vendor application", "exhibitor" };
        if (!eventSignals.Any(text.Contains)) return true;
        return false;
    }

    private static bool LooksLikeSpecificShow(string title, string snippet, string url)
    {
        var clean = CleanTitle(title);
        var lower = clean.ToLowerInvariant();
        if (clean.Length < 6) return false;
        var generic = new[]
        {
            "craft shows", "craft fairs", "art fairs", "find a", "events", "calendar", "directory",
            "vendor events", "upcoming", "things to do", "festival guide", "show list", "markets near"
        };
        if (generic.Any(x => lower == x || lower.StartsWith(x + " ") || lower.Contains(x + " 202"))) return false;

        // A candidate must look like a named event, not merely a page that happens to mention festivals.
        var combined = $"{clean} {snippet}".ToLowerInvariant();
        var eventSignals = new[] { "festival", "fair", "market", "renaissance", "craft show", "art show", "maker", "oktoberfest" };
        return eventSignals.Any(combined.Contains);
    }

    private static bool MatchesDiscoveryIntent(WebSearchHit hit, string monthName, string geography)
    {
        var text = $"{hit.Title} {hit.Snippet}".ToLowerInvariant();

        // Reject obvious state mismatches when the search geography contains a US state name/abbreviation.
        var states = new Dictionary<string, string[]>(StringComparer.OrdinalIgnoreCase)
        {
            ["Indiana"] = new[] { " indiana", ", in", " in " }, ["Ohio"] = new[] { " ohio", ", oh", " oh " },
            ["Illinois"] = new[] { " illinois", ", il", " il " }, ["Kentucky"] = new[] { " kentucky", ", ky", " ky " },
            ["Michigan"] = new[] { " michigan", ", mi", " mi " }, ["Wisconsin"] = new[] { " wisconsin", ", wi", " wi " },
            ["Missouri"] = new[] { " missouri", ", mo", " mo " }, ["Iowa"] = new[] { " iowa", ", ia", " ia " },
            ["Florida"] = new[] { " florida", ", fl", " fl " }, ["Kansas"] = new[] { " kansas", ", ks", " ks " }
        };
        var requested = states.FirstOrDefault(kv => geography.Contains(kv.Key, StringComparison.OrdinalIgnoreCase) || kv.Value.Any(v => (" " + geography.ToLowerInvariant() + " ").Contains(v)));
        if (!string.IsNullOrWhiteSpace(requested.Key))
        {
            var mentionsAnother = states.Where(kv => !kv.Key.Equals(requested.Key, StringComparison.OrdinalIgnoreCase)).Any(kv => kv.Value.Any(text.Contains));
            var mentionsRequested = requested.Value.Any(text.Contains) || text.Contains(requested.Key.ToLowerInvariant());
            if (mentionsAnother && !mentionsRequested) return false;
        }

        // Month is a preference, not an identity rule: keep annual-event pages when the snippet omits it.
        return true;
    }

    private static string CleanTitle(string title)
    {
        var cleaned = Regex.Replace(title, @"\s*[|\-–—]\s*(Facebook|Instagram|Eventbrite|FestivalNet|10times|Yelp).*$", "", RegexOptions.IgnoreCase).Trim();
        return string.IsNullOrWhiteSpace(cleaned) ? title.Trim() : cleaned;
    }

    private static string CleanHtml(string value)
        => WhitespaceRegex.Replace(WebUtility.HtmlDecode(TagRegex.Replace(value ?? string.Empty, " ")), " ").Trim();

    private static string Limit(string? value, int max)
        => string.IsNullOrWhiteSpace(value) ? string.Empty : value.Length <= max ? value : value[..max];
}
