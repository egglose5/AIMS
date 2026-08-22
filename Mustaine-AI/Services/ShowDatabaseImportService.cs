using System.Globalization;
using System.Text.Json;
using System.Text.RegularExpressions;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record ShowDatabaseSyncResult(int HistoricalRows, int EventsAdded, int EditionsAdded, int EvidenceAdded, int CalibrationsAdded, string Message);

public interface IShowDatabaseImportService
{
    Task<ShowDatabaseSyncResult> EnsureHistoricalImportedAsync(CancellationToken cancellationToken = default);
}

/// <summary>
/// Bridges the legacy Festival Command Center into the Show Arm without turning the 2027 research candidate
/// database into committed show records. Known 2025/2026 history is upserted; 2027 Jan-Jun candidate intelligence
/// remains a discovery source consumed by ShowWebResearchService.
/// </summary>
public sealed class ShowDatabaseImportService(ShowArmDbContext db, IWebHostEnvironment env) : IShowDatabaseImportService
{
    private const string CommandCenterSource = "FESTIVAL_COMMAND_CENTER_2025_2026";
    private static readonly Regex MoneyRegex = new(@"-?\$?\s*([0-9][0-9,]*(?:\.[0-9]+)?)", RegexOptions.Compiled);
    private static readonly Regex DigitsRegex = new(@"([0-9][0-9,]*)", RegexOptions.Compiled);

    public async Task<ShowDatabaseSyncResult> EnsureHistoricalImportedAsync(CancellationToken cancellationToken = default)
    {
        var path = Path.Combine(env.WebRootPath, "data", "show-intelligence", "festival-command-center-2025-2026.json");
        if (!File.Exists(path)) return new(0,0,0,0,0,$"Festival Command Center data file not found at {path}.");

        var json = await File.ReadAllTextAsync(path, cancellationToken);
        var rows = JsonSerializer.Deserialize<List<Dictionary<string, JsonElement>>>(json) ?? [];

        var events = await db.ShowEvents.Include(x => x.Editions).ToListAsync(cancellationToken);
        var eventByKey = events.GroupBy(x => Key(x.Name)).ToDictionary(g => g.Key, g => g.First(), StringComparer.OrdinalIgnoreCase);
        var eventsAdded = 0;
        var editionsAdded = 0;

        foreach (var row in rows)
        {
            var name = Text(row, "Event Name");
            if (string.IsNullOrWhiteSpace(name)) continue;
            var year = Int(row, "Year");
            if (year is not 2025 and not 2026) continue;

            var key = Key(name);
            if (!eventByKey.TryGetValue(key, out var ev))
            {
                ev = new ShowEventEntity
                {
                    Name = name.Trim(),
                    City = Text(row, "City"),
                    State = Text(row, "State"),
                    EventType = Text(row, "Event Type"),
                    WebsiteUrl = FirstNonBlank(Text(row, "Website URL"), Text(row, "Application URL")),
                    Notes = Limit(Text(row, "Extra Notes"), 4000),
                    CreatedAt = DateTimeOffset.UtcNow,
                    UpdatedAt = DateTimeOffset.UtcNow
                };
                db.ShowEvents.Add(ev);
                eventByKey[key] = ev;
                eventsAdded++;
            }
            else
            {
                ev.City ??= Text(row, "City");
                ev.State ??= Text(row, "State");
                ev.EventType ??= Text(row, "Event Type");
                ev.WebsiteUrl ??= FirstNonBlank(Text(row, "Website URL"), Text(row, "Application URL"));
                ev.UpdatedAt = DateTimeOffset.UtcNow;
            }

            var edition = ev.Editions.FirstOrDefault(x => x.Year == year);
            if (edition is null)
            {
                edition = new ShowEditionEntity
                {
                    ShowEvent = ev,
                    Year = year.Value,
                    StartDate = Date(row, "Start Date"),
                    EndDate = Date(row, "End Date"),
                    Status = MapOperationalStatus(Text(row, "Application Status Raw"), Text(row, "Status")),
                    LeadSource = CommandCenterSource,
                    ResearchStatus = "IMPORTED_HISTORICAL",
                    Recommendation = MapReturnDecision(Text(row, "Return Decision")),
                    ResearchPriority = "NORMAL",
                    LeadUrl = FirstNonBlank(Text(row, "Application URL"), Text(row, "Website URL")),
                    LeadNote = BuildHistoricalLeadNote(row),
                    JuryFee = Money(row, "App Fee"),
                    BoothFee = Money(row, "Booth Fee"),
                    Notes = Limit(Text(row, "Extra Notes"), 5000),
                    CreatedAt = DateTimeOffset.UtcNow,
                    UpdatedAt = DateTimeOffset.UtcNow
                };
                ev.Editions.Add(edition);
                db.ShowEditions.Add(edition);
                editionsAdded++;
            }
            else
            {
                edition.StartDate ??= Date(row, "Start Date");
                edition.EndDate ??= Date(row, "End Date");
                edition.JuryFee ??= Money(row, "App Fee");
                edition.BoothFee ??= Money(row, "Booth Fee");
                edition.LeadUrl ??= FirstNonBlank(Text(row, "Application URL"), Text(row, "Website URL"));
                edition.UpdatedAt = DateTimeOffset.UtcNow;
            }
        }

        await db.SaveChangesAsync(cancellationToken);

        // Reload IDs and existing evidence/calibrations after the event/edition upsert.
        events = await db.ShowEvents.Include(x => x.Editions).ToListAsync(cancellationToken);
        eventByKey = events.GroupBy(x => Key(x.Name)).ToDictionary(g => g.Key, g => g.First(), StringComparer.OrdinalIgnoreCase);
        var existingEvidence = (await db.ShowResearchEvidence.AsNoTracking()
            .Where(x => x.SourceName == "Festival Command Center")
            .Select(x => x.ShowEditionId)
            .ToListAsync(cancellationToken)).ToHashSet();
        var existingCalibrations = await db.ShowCalibrationRecords.AsNoTracking().Where(x => x.SourceType == CommandCenterSource).ToListAsync(cancellationToken);
        var calibrationKeys = existingCalibrations.Select(x => $"{x.ShowEventId}|{x.Year}|{Key(x.VendorName)}").ToHashSet(StringComparer.OrdinalIgnoreCase);

        var evidenceAdded = 0;
        var calibrationsAdded = 0;
        foreach (var row in rows)
        {
            var name = Text(row, "Event Name");
            var year = Int(row, "Year");
            if (string.IsNullOrWhiteSpace(name) || year is not 2025 and not 2026) continue;
            if (!eventByKey.TryGetValue(Key(name), out var ev)) continue;
            var edition = ev.Editions.FirstOrDefault(x => x.Year == year);
            if (edition is null) continue;

            if (!existingEvidence.Contains(edition.Id))
            {
                db.ShowResearchEvidence.Add(new ShowResearchEvidenceEntity
                {
                    ShowEditionId = edition.Id,
                    EvidenceType = "OWNER_DATABASE_HISTORY",
                    SourceName = "Festival Command Center",
                    SourceUrl = FirstNonBlank(Text(row, "Website URL"), Text(row, "Application URL")),
                    AppliesToYear = year,
                    Finding = Limit(BuildHistoricalEvidence(row), 5000),
                    Reliability = "OWNER_DATABASE",
                    Sentiment = "NEUTRAL",
                    ResearchedAt = DateTimeOffset.UtcNow
                });
                existingEvidence.Add(edition.Id);
                evidenceAdded++;
            }

            var gross = Money(row, "Gross Sales");
            var returnDecision = Text(row, "Return Decision");
            if (gross is null && string.IsNullOrWhiteSpace(returnDecision)) continue;
            var vendor = FirstNonBlank(Text(row, "Lead"), "Unknown");
            var ckey = $"{ev.Id}|{year}|{Key(vendor)}";
            if (calibrationKeys.Contains(ckey)) continue;
            db.ShowCalibrationRecords.Add(new ShowCalibrationRecordEntity
            {
                ShowEventId = ev.Id,
                Year = year,
                PeriodLabel = "FESTIVAL_COMMAND_CENTER",
                VendorName = vendor,
                ActualGross = gross,
                Conditions = Limit(BuildExpenseSummary(row), 1500),
                Lesson = Limit(FirstNonBlank(returnDecision, Text(row, "Extra Notes")), 2500),
                IsDoNotReturn = IsDoNotReturn(returnDecision),
                SourceType = CommandCenterSource,
                CreatedAt = DateTimeOffset.UtcNow
            });
            calibrationKeys.Add(ckey);
            calibrationsAdded++;
        }

        await db.SaveChangesAsync(cancellationToken);
        return new(rows.Count, eventsAdded, editionsAdded, evidenceAdded, calibrationsAdded,
            $"Show database connected: {rows.Count} historical Command Center rows checked; {eventsAdded} show(s), {editionsAdded} year record(s), {evidenceAdded} evidence record(s), and {calibrationsAdded} sales/return calibration record(s) added. The 296 Jan–June 2027 intelligence candidates remain staged for database-first discovery.");
    }

    private static string BuildHistoricalLeadNote(Dictionary<string, JsonElement> row)
        => Limit($"Festival Command Center history. Dates: {Text(row,"Show Dates Text")}. Application: {Text(row,"Application Status Raw")}. Lead: {Text(row,"Lead")}. Booth: {Text(row,"Booth Size")}. Return: {Text(row,"Return Decision")}. Notes: {Text(row,"Extra Notes")}", 5000) ?? "Festival Command Center history.";

    private static string BuildHistoricalEvidence(Dictionary<string, JsonElement> row)
        => $"Historical owner database record. Status={Text(row,"Status")}; application={Text(row,"Application Status Raw")}; applied={Text(row,"Date Applied")}; app fee={Text(row,"App Fee")}; booth fee={Text(row,"Booth Fee")}; payment={Text(row,"Payment Status")}; lead={Text(row,"Lead")}; backer={Text(row,"Backer")}; gross={Text(row,"Gross Sales")}; net profit={Text(row,"Net Profit")}; revenue/day={Text(row,"Revenue/Day")}; return decision={Text(row,"Return Decision")}; product fit={Text(row,"Product Fit")}; notes={Text(row,"Extra Notes")}";

    private static string BuildExpenseSummary(Dictionary<string, JsonElement> row)
        => $"Booth={Text(row,"Booth Fee")}; hotel={Text(row,"Hotel Est")}; fuel={Text(row,"Fuel Est")}; food={Text(row,"Food Est")}; parking/other={Text(row,"Parking/Other")}; net profit={Text(row,"Net Profit")}.";

    private static string MapOperationalStatus(string? application, string? status)
    {
        var text = $"{application} {status}".ToLowerInvariant();
        if (text.Contains("accepted")) return "ACCEPTED";
        if (text.Contains("wait")) return "WAITLISTED";
        if (text.Contains("reject") || text.Contains("declin")) return "REJECTED";
        if (text.Contains("applied") || text.Contains("submitted")) return "APPLIED";
        return "HISTORICAL";
    }

    private static string MapReturnDecision(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return "UNDECIDED";
        var s = value.ToLowerInvariant();
        if (s.Contains("no") || s.Contains("pass") || s.Contains("do not") || s.Contains("never")) return "REJECT";
        if (s.Contains("yes") || s.Contains("return")) return "STRONG_APPLY";
        return "UNDECIDED";
    }

    private static bool IsDoNotReturn(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return false;
        var s = value.ToLowerInvariant();
        return s.Contains("do not") || s.Contains("don't") || s.Contains("no return") || s.Contains("never") || s.Contains("pass");
    }

    private static string Key(string? value)
        => Regex.Replace((value ?? string.Empty).ToLowerInvariant(), @"[^a-z0-9]+", string.Empty);

    private static string? Text(Dictionary<string, JsonElement> row, string key)
    {
        if (!row.TryGetValue(key, out var el) || el.ValueKind is JsonValueKind.Null or JsonValueKind.Undefined) return null;
        var s = el.ValueKind == JsonValueKind.String ? el.GetString() : el.ToString();
        return string.IsNullOrWhiteSpace(s) ? null : s.Trim();
    }

    private static int? Int(Dictionary<string, JsonElement> row, string key)
    {
        var s = Text(row, key);
        if (int.TryParse(s, NumberStyles.Any, CultureInfo.InvariantCulture, out var i)) return i;
        if (decimal.TryParse(s, NumberStyles.Any, CultureInfo.InvariantCulture, out var d)) return (int)d;
        return null;
    }

    private static decimal? Money(Dictionary<string, JsonElement> row, string key)
    {
        var s = Text(row, key);
        if (string.IsNullOrWhiteSpace(s)) return null;
        if (decimal.TryParse(s, NumberStyles.Any, CultureInfo.InvariantCulture, out var direct)) return direct;
        var m = MoneyRegex.Match(s);
        return m.Success && decimal.TryParse(m.Groups[1].Value.Replace(",", ""), NumberStyles.Any, CultureInfo.InvariantCulture, out var d) ? d : null;
    }

    private static DateOnly? Date(Dictionary<string, JsonElement> row, string key)
    {
        var s = Text(row, key);
        return DateOnly.TryParse(s, CultureInfo.InvariantCulture, DateTimeStyles.None, out var d) ? d : null;
    }

    private static string? FirstNonBlank(params string?[] values) => values.FirstOrDefault(x => !string.IsNullOrWhiteSpace(x));
    private static string? Limit(string? value, int max) => string.IsNullOrWhiteSpace(value) ? null : value.Length <= max ? value : value[..max];
}
