using System.Text.RegularExpressions;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record ShowFinderResult(int LeadsFound, int Researched, int Ranked, string Message);

public interface IShowPlacementService
{
    Task<ShowFinderResult> FindAndResearchAsync(long vendorId, int year, int month, CancellationToken ct = default);
    Task<long?> ProcessResearchLeadAsync(long leadId, CancellationToken ct = default);
    Task OfferToVendorAsync(long showEditionId, long vendorId, CancellationToken ct = default);
}

public sealed class ShowPlacementService : IShowPlacementService
{
    private readonly ShowArmDbContext _db;
    private readonly IShowWebResearchService _web;

    public ShowPlacementService(ShowArmDbContext db, IShowWebResearchService web)
    {
        _db = db;
        _web = web;
    }

    public async Task<ShowFinderResult> FindAndResearchAsync(long vendorId, int year, int month, CancellationToken ct = default)
    {
        using var totalBudget = CancellationTokenSource.CreateLinkedTokenSource(ct);
        totalBudget.CancelAfter(TimeSpan.FromSeconds(35));
        var budgetCt = totalBudget.Token;

        var vendor = await _db.ShowVendorProfiles.AsNoTracking().FirstAsync(x => x.Id == vendorId && x.IsActive, budgetCt);
        await QuarantineClearFinderJunkAsync(year, budgetCt);

        ShowDiscoveryRunResult discovery;
        try
        {
            discovery = await _web.DiscoverCandidatesAsync(vendorId, year, month, budgetCt);
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            discovery = new ShowDiscoveryRunResult(0, 0, "Discovery hit the total Finder budget.");
        }

        var leads = await _db.ShowDiscoveryLeads
            .Where(x => x.ShowVendorProfileId == vendorId
                     && x.TargetYear == year
                     && x.TargetMonth == month
                     && x.Status == "NEW"
                     && (x.SearchQuery == null || !x.SearchQuery.StartsWith(OperationalBoundaryRules.ScoutLeadPrefix)))
            .OrderByDescending(x => x.SearchQuery.StartsWith("DATABASE:"))
            .ThenByDescending(x => x.DiscoveredAt)
            .Take(10)
            .ToListAsync(ct);

        var researched = 0;
        var verificationCount = 0;
        foreach (var lead in leads)
        {
            if (budgetCt.IsCancellationRequested || verificationCount >= 3) break;
            try
            {
                verificationCount++;
                var verification = await _web.VerifyCandidateAsync(lead, year, month, budgetCt);
                if (!verification.Verified)
                {
                    lead.Status = "QUARANTINED_UNVERIFIED";
                    await _db.SaveChangesAsync(budgetCt);
                    continue;
                }

                var edition = await QueueVerifiedLeadAsync(lead, verification, vendorId, year, budgetCt);
                if (edition is null) continue;

                if (await IsVendorUnavailableAsync(vendorId, edition, budgetCt))
                {
                    var opp = await EnsureOpportunityAsync(edition.Id, vendorId, budgetCt);
                    opp.Status = "VENDOR_UNAVAILABLE";
                    opp.Priority = "PASS";
                    opp.FitRationale = "Vendor previously selected NOT THIS WEEKEND for overlapping dates.";
                    lead.Status = "QUEUED";
                    await _db.SaveChangesAsync(budgetCt);
                    continue;
                }

                if (researched == 0 && !budgetCt.IsCancellationRequested)
                {
                    await _web.CollectEvidenceAsync(edition.Id, budgetCt);
                    await RankAsync(edition, vendor, budgetCt);
                    researched++;
                }

                lead.Status = "QUEUED";
                await _db.SaveChangesAsync(budgetCt);
            }
            catch (OperationCanceledException) when (!ct.IsCancellationRequested)
            {
                break;
            }
        }

        var ranked = await _db.ShowOpportunities.CountAsync(x =>
            x.ShowVendorProfileId == vendorId &&
            x.ShowEdition.Year == year &&
            x.Status == "RESEARCHED", ct);

        var budgetHit = totalBudget.IsCancellationRequested && !ct.IsCancellationRequested;
        return new(discovery.NewLeads, researched, ranked,
            budgetHit
                ? $"Finder stopped at its 35-second total budget and kept partial results: {discovery.NewLeads} new lead(s), {researched} researched, {ranked} ranked."
                : $"Show Finder found {discovery.NewLeads} new lead(s), automatically researched {researched}, and ranked viable placements for {vendor.VendorName}.");
    }

    public async Task<long?> ProcessResearchLeadAsync(long leadId, CancellationToken ct = default)
    {
        var lead = await _db.ShowDiscoveryLeads.FirstOrDefaultAsync(x => x.Id == leadId, ct);
        if (lead is null) return null;
        if (OperationalBoundaryRules.IsScoutLead(lead.SearchQuery)) return null;

        var verification = await _web.VerifyCandidateAsync(lead, lead.TargetYear, lead.TargetMonth ?? 1, ct);
        if (!verification.Verified)
        {
            lead.Status = "RESEARCH_REJECTED";
            lead.Snippet = LimitQueueText($"{lead.Snippet} | Verification failed: {verification.Reason}", 1500);
            await _db.SaveChangesAsync(ct);
            return null;
        }

        var name = verification.Name.Trim();
        var ev = await _db.ShowEvents.FirstOrDefaultAsync(x => x.Name.ToLower() == name.ToLower(), ct);
        if (ev is null)
        {
            ev = new ShowEventEntity
            {
                Name = name,
                City = verification.City,
                State = verification.State,
                WebsiteUrl = verification.VerifiedUrl,
                Notes = lead.Snippet,
                CreatedAt = DateTimeOffset.UtcNow,
                UpdatedAt = DateTimeOffset.UtcNow
            };
            _db.ShowEvents.Add(ev);
            await _db.SaveChangesAsync(ct);
        }
        else
        {
            ev.City ??= verification.City;
            ev.State ??= verification.State;
            ev.WebsiteUrl ??= verification.VerifiedUrl;
            ev.UpdatedAt = DateTimeOffset.UtcNow;
        }

        var edition = await _db.ShowEditions.FirstOrDefaultAsync(x => x.ShowEventId == ev.Id && x.Year == lead.TargetYear, ct);
        if (edition is null)
        {
            edition = new ShowEditionEntity
            {
                ShowEventId = ev.Id,
                Year = lead.TargetYear,
                StartDate = verification.StartDate,
                EndDate = verification.EndDate ?? verification.StartDate,
                Status = "RESEARCHING",
                LeadSource = lead.SearchQuery?.StartsWith("DATABASE:", StringComparison.OrdinalIgnoreCase) == true ? "SHOW_INTELLIGENCE_DATABASE" : "RESEARCH_QUEUE",
                ResearchStatus = "RESEARCHING",
                Recommendation = "UNDECIDED",
                ResearchPriority = "NORMAL",
                LeadUrl = verification.VerifiedUrl,
                LeadNote = $"{verification.Reason} {lead.Snippet}",
                ResearchStartedAt = DateTimeOffset.UtcNow,
                CreatedAt = DateTimeOffset.UtcNow,
                UpdatedAt = DateTimeOffset.UtcNow
            };
            _db.ShowEditions.Add(edition);
            await _db.SaveChangesAsync(ct);
        }
        else
        {
            edition.StartDate ??= verification.StartDate;
            edition.EndDate ??= verification.EndDate ?? verification.StartDate;
            edition.LeadUrl ??= verification.VerifiedUrl;
            edition.ResearchStatus = "RESEARCHING";
            edition.ResearchStartedAt ??= DateTimeOffset.UtcNow;
            edition.UpdatedAt = DateTimeOffset.UtcNow;
            await _db.SaveChangesAsync(ct);
        }

        if (lead.ShowVendorProfileId is long vendorId)
            await EnsureOpportunityAsync(edition.Id, vendorId, ct);

        await _web.CollectEvidenceAsync(edition.Id, ct);

        if (lead.ShowVendorProfileId is long rankVendorId)
        {
            var vendor = await _db.ShowVendorProfiles.AsNoTracking().FirstOrDefaultAsync(x => x.Id == rankVendorId, ct);
            if (vendor is not null) await RankAsync(edition, vendor, ct);
        }
        else
        {
            var evidenceCount = await _db.ShowResearchEvidence.CountAsync(x => x.ShowEditionId == edition.Id, ct);
            edition.ResearchStatus = evidenceCount >= 2 ? "RESEARCH_COMPLETE" : "NEEDS_MORE_RESEARCH";
            edition.ResearchCompletedAt = evidenceCount >= 2 ? DateTimeOffset.UtcNow : null;
            edition.UpdatedAt = DateTimeOffset.UtcNow;
        }

        lead.Status = "RESEARCH_COMPLETE";
        await _db.SaveChangesAsync(ct);
        return edition.Id;
    }

    private static string LimitQueueText(string? value, int length)
    {
        var s = value ?? "";
        return s.Length <= length ? s : s[..length];
    }

    public async Task OfferToVendorAsync(long showEditionId, long vendorId, CancellationToken ct = default)
    {
        var opportunity = await EnsureOpportunityAsync(showEditionId, vendorId, ct);
        var ownerDecision = await _db.ShowEditions.AsNoTracking()
            .Where(x => x.Id == showEditionId)
            .Select(x => x.Recommendation)
            .FirstOrDefaultAsync(ct);

        if (opportunity.Status == "VENDOR_UNAVAILABLE")
            throw new InvalidOperationException("This vendor is unavailable for this placement.");

        // Jaime's vetted show-pool decision outranks the legacy automated research PASS score.
        // APPROVE/MAYBE are operational decisions; old research cannot silently block them.
        var ownerSelectable = ownerDecision is "OWNER_APPROVE" or "OWNER_MAYBE";
        if (opportunity.Status == "PASS" && !ownerSelectable)
            throw new InvalidOperationException("This placement is not currently eligible to offer to this vendor.");

        var assignment = await _db.ShowAssignments.FirstOrDefaultAsync(x => x.ShowEditionId == showEditionId && x.ShowVendorProfileId == vendorId, ct);
        if (assignment is null)
        {
            assignment = new ShowAssignmentEntity
            {
                ShowEditionId = showEditionId,
                ShowVendorProfileId = vendorId,
                Status = "OFFERED",
                OfferedAt = DateTimeOffset.UtcNow
            };
            _db.ShowAssignments.Add(assignment);
        }
        else
        {
            assignment.Status = "OFFERED";
            assignment.OfferedAt = DateTimeOffset.UtcNow;
            assignment.RespondedAt = null;
            assignment.DeclineReason = null;
        }
        opportunity.Status = "OFFERED_TO_VENDOR";
        await _db.SaveChangesAsync(ct);
    }

    private async Task<ShowEditionEntity?> QueueVerifiedLeadAsync(ShowDiscoveryLeadEntity lead, ShowCandidateVerificationResult verified, long vendorId, int year, CancellationToken ct)
    {
        var name = verified.Name.Trim();
        var usableUrl = verified.VerifiedUrl;

        var ev = await _db.ShowEvents.FirstOrDefaultAsync(x => x.Name.ToLower() == name.ToLower(), ct);
        if (ev is null)
        {
            ev = new ShowEventEntity
            {
                Name = name,
                City = verified.City,
                State = verified.State,
                WebsiteUrl = usableUrl,
                Notes = lead.Snippet,
                CreatedAt = DateTimeOffset.UtcNow,
                UpdatedAt = DateTimeOffset.UtcNow
            };
            _db.ShowEvents.Add(ev);
            await _db.SaveChangesAsync(ct);
        }
        else
        {
            ev.City ??= verified.City;
            ev.State ??= verified.State;
            ev.WebsiteUrl ??= usableUrl;
            ev.UpdatedAt = DateTimeOffset.UtcNow;
        }

        var edition = await _db.ShowEditions.FirstOrDefaultAsync(x => x.ShowEventId == ev.Id && x.Year == year, ct);
        if (edition is null)
        {
            edition = new ShowEditionEntity
            {
                ShowEventId = ev.Id,
                Year = year,
                StartDate = verified.StartDate,
                EndDate = verified.EndDate ?? verified.StartDate,
                Status = "RESEARCHING",
                LeadSource = lead.SearchQuery?.StartsWith("DATABASE:", StringComparison.OrdinalIgnoreCase) == true ? "SHOW_INTELLIGENCE_DATABASE" : "SHOW_FINDER",
                ResearchStatus = "NEEDS_RESEARCH",
                Recommendation = "UNDECIDED",
                ResearchPriority = "NORMAL",
                LeadUrl = usableUrl,
                LeadNote = $"{verified.Reason} {lead.Snippet}",
                CreatedAt = DateTimeOffset.UtcNow,
                UpdatedAt = DateTimeOffset.UtcNow
            };
            _db.ShowEditions.Add(edition);
        }
        else
        {
            edition.StartDate ??= verified.StartDate;
            edition.EndDate ??= verified.EndDate ?? verified.StartDate;
            edition.LeadUrl ??= usableUrl;
            edition.UpdatedAt = DateTimeOffset.UtcNow;
        }
        await _db.SaveChangesAsync(ct);

        await EnsureOpportunityAsync(edition.Id, vendorId, ct);
        return edition;
    }

    private async Task<ShowOpportunityEntity> EnsureOpportunityAsync(long editionId, long vendorId, CancellationToken ct)
    {
        var opp = await _db.ShowOpportunities.FirstOrDefaultAsync(x => x.ShowEditionId == editionId && x.ShowVendorProfileId == vendorId, ct);
        if (opp is null)
        {
            opp = new ShowOpportunityEntity
            {
                ShowEditionId = editionId,
                ShowVendorProfileId = vendorId,
                Priority = "B",
                Status = "CANDIDATE",
                FitRationale = "Show Finder candidate awaiting automatic research."
            };
            _db.ShowOpportunities.Add(opp);
            await _db.SaveChangesAsync(ct);
        }
        return opp;
    }

    private async Task RankAsync(ShowEditionEntity edition, ShowVendorProfileEntity vendor, CancellationToken ct)
    {
        var opp = await EnsureOpportunityAsync(edition.Id, vendor.Id, ct);
        var evidence = await _db.ShowResearchEvidence.AsNoTracking().Where(x => x.ShowEditionId == edition.Id).ToListAsync(ct);
        var calibrations = await _db.ShowCalibrationRecords.AsNoTracking().Where(x => x.ShowEventId == edition.ShowEventId).OrderByDescending(x => x.Year).ToListAsync(ct);

        var score = 50;
        var why = new List<string>();
        var databaseScore = ExtractDatabaseScore(edition.LeadNote);
        if (databaseScore is not null)
        {
            score = (score + databaseScore.Value) / 2;
            why.Add($"Show database score {databaseScore}/100.");
        }

        if (!string.IsNullOrWhiteSpace(vendor.HomeState) && !string.IsNullOrWhiteSpace(edition.ShowEvent.State))
        {
            if (vendor.HomeState.Equals(edition.ShowEvent.State, StringComparison.OrdinalIgnoreCase))
            {
                score += 15;
                why.Add("Home-state placement.");
            }
            else if ((vendor.MaxTravelHours ?? 0) <= 2)
            {
                score -= 12;
                why.Add("Outside home state; short travel profile requires stronger proof.");
            }
        }

        if (evidence.Any(x => x.EvidenceType == "OWN_HISTORY" && x.Sentiment == "NEGATIVE"))
        {
            score -= 35;
            why.Add("Ancient Innovations own history contains negative evidence.");
        }
        if (calibrations.Any(x => x.IsDoNotReturn))
        {
            score -= 50;
            why.Add("Historical DO NOT RETURN flag.");
        }

        var priorGross = calibrations.Where(x => x.ActualGross > 0).OrderByDescending(x => x.Year).FirstOrDefault()?.ActualGross;
        if (priorGross is >= 7000) { score += 25; why.Add($"Prior gross {priorGross:C0}."); }
        else if (priorGross is >= 4000) { score += 12; why.Add($"Prior gross {priorGross:C0}."); }
        else if (priorGross is > 0 and < 2000) { score -= 15; why.Add($"Prior gross only {priorGross:C0}."); }

        if (evidence.Any(x => x.EvidenceType == "APPLICATION")) { score += 5; why.Add("Application source located."); }
        if (evidence.Any(x => x.EvidenceType == "ATTENDANCE")) { score += 5; why.Add("Attendance evidence located."); }
        if (evidence.Any(x => x.EvidenceType == "VENDOR_QUALITY" || x.EvidenceType == "VENDOR_REPORT")) { score += 5; why.Add("Vendor-quality evidence located."); }

        score = Math.Clamp(score, 0, 100);
        var coreVerified = edition.StartDate is not null
            && !string.IsNullOrWhiteSpace(edition.ShowEvent.State)
            && evidence.Count >= 2;

        opp.Priority = score >= 82 ? "A+" : score >= 72 ? "A" : score >= 58 ? "B" : score >= 45 ? "C" : "PASS";
        opp.Status = !coreVerified ? "RESEARCH_GAP" : score < 45 ? "PASS" : "RESEARCHED";
        opp.FitRationale = $"{score}/100 potential for {vendor.VendorName}. {string.Join(" ", why)}";

        if (vendor.TargetGrossSales is > 0)
        {
            opp.ForecastLow = Math.Round(vendor.TargetGrossSales.Value * (score >= 72 ? 0.8m : 0.55m), 0);
            opp.ForecastHigh = Math.Round(vendor.TargetGrossSales.Value * (score >= 72 ? 1.25m : 0.9m), 0);
            opp.ForecastConfidence = priorGross is not null ? "HISTORICAL" : "EARLY";
        }

        edition.ResearchStatus = coreVerified ? "RESEARCH_COMPLETE" : "NEEDS_MORE_RESEARCH";
        edition.Recommendation = !coreVerified ? "UNDECIDED" : score >= 82 ? "STRONG_APPLY" : score >= 58 ? "RESEARCHED_OPTION" : score >= 45 ? "BACKUP" : "REJECT";
        edition.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
    }

    private async Task QuarantineClearFinderJunkAsync(int year, CancellationToken ct)
    {
        var candidates = await _db.ShowEditions
            .Include(x => x.ShowEvent)
            .Where(x => x.Year == year
                     && (x.LeadSource == "SHOW_FINDER" || x.LeadSource == "RESEARCHER")
                     && x.Status != "QUARANTINED")
            .ToListAsync(ct);

        foreach (var e in candidates)
        {
            var title = (e.ShowEvent.Name ?? "").ToLowerInvariant();
            var url = (e.LeadUrl ?? e.ShowEvent.WebsiteUrl ?? "").ToLowerInvariant();
            var clearJunk =
                title.Contains("midwest art, craft, vendor, fairs, and shows")
                || title.Contains("craft shows, art & craft fairs")
                || title.Contains("independent bookstore day")
                || title.Contains("spring programs")
                || Regex.IsMatch(title, @"\b(watch|programs|calendar|directory|group)\s*$")
                || url.Contains("facebook.com/groups/")
                || url.Contains("artscraftsshowbusiness.com/");

            if (!clearJunk) continue;

            var hasAssignment = await _db.ShowAssignments.AnyAsync(x => x.ShowEditionId == e.Id, ct);
            var hasOwnHistory = await _db.ShowCalibrationRecords.AnyAsync(x => x.ShowEventId == e.ShowEventId, ct);
            if (hasAssignment || hasOwnHistory) continue;

            e.Status = "QUARANTINED";
            e.ResearchStatus = "REJECTED_AUTO_GENERIC";
            e.Recommendation = "REJECT";
            e.UpdatedAt = DateTimeOffset.UtcNow;

            var opportunities = await _db.ShowOpportunities.Where(x => x.ShowEditionId == e.Id).ToListAsync(ct);
            foreach (var opp in opportunities)
            {
                opp.Status = "PASS";
                opp.Priority = "PASS";
                opp.FitRationale = "Auto-quarantined: source page/non-event was incorrectly promoted to a show record by older Finder logic.";
            }
        }
        await _db.SaveChangesAsync(ct);
    }

    private async Task<bool> IsVendorUnavailableAsync(long vendorId, ShowEditionEntity edition, CancellationToken ct)
    {
        if (edition.StartDate is null) return false;
        var start = edition.StartDate.Value;
        var end = edition.EndDate ?? start;
        return await _db.ShowAssignments
            .Where(x => x.ShowVendorProfileId == vendorId && x.Status == "UNAVAILABLE_WEEKEND")
            .AnyAsync(x => x.ShowEdition.StartDate != null &&
                           x.ShowEdition.StartDate.Value <= end &&
                           (x.ShowEdition.EndDate ?? x.ShowEdition.StartDate.Value) >= start, ct);
    }

    private static int? ExtractDatabaseScore(string? text)
    {
        if (string.IsNullOrWhiteSpace(text)) return null;
        var m = Regex.Match(text, @"database score\s+(\d{1,3})/100", RegexOptions.IgnoreCase);
        return m.Success && int.TryParse(m.Groups[1].Value, out var n) ? Math.Clamp(n, 0, 100) : null;
    }
}
