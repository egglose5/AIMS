using System.Data;
using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record ScoutWatchSnapshot(
    string ControlAppShowId,
    long ShowEventId,
    long? CurrentShowEditionId,
    bool ScoutWatchEnabled,
    string? ScoutShowId,
    string CanonicalShowName,
    string? OrganizerName,
    string? PrimaryLocation,
    string? OfficialWebsiteUrl,
    int CurrentEventYear,
    string? KnownAliases,
    DateTimeOffset? LastScoutUpdateAt,
    DateTimeOffset? LastVerifiedAt,
    decimal? ScoutMatchConfidence,
    bool? ApplicationOpen,
    DateOnly? ApplicationOpenDate,
    DateOnly? ApplicationDeadline,
    string? ApplicationUrl,
    string? ApplicationMethod,
    decimal? BoothFee,
    decimal? JuryFee,
    decimal? CommissionRate,
    DateOnly? EventStartDate,
    DateOnly? EventEndDate,
    string? AcceptanceWindow,
    string? VendorPacketUrl,
    string? VendorMapUrl,
    string? LoadInInstructionsUrl,
    string? CancellationStatus,
    string? OrganizerContactName,
    string? OrganizerContactEmail,
    string? OrganizerContactPhone);

public sealed record ScoutLinkRequest(string? ScoutShowId, string? KnownAliases, decimal? ScoutMatchConfidence);

public sealed record ScoutFactUpdateRequest(
    string FieldName,
    string? NewValue,
    string? SourceUrl,
    string? SourceType,
    string? SourceTitle,
    DateTimeOffset? ObservedAt,
    DateTimeOffset? VerifiedAt,
    decimal? Confidence,
    string? IdempotencyKey);

public sealed record ScoutDocumentRequest(
    string DocumentId,
    string DocumentType,
    string OriginalFilename,
    string? SourceUrl,
    DateTimeOffset DownloadedAt,
    int EventYear,
    string? VersionOrDate,
    string FileHash,
    string? StoredPath);

public interface IScoutIntegrationService
{
    Task EnsureSchemaAsync(CancellationToken ct = default);
    Task<ScoutWatchSnapshot?> EnsureWatchLinkAsync(long showEditionId, CancellationToken ct = default);
    Task SetWatchEnabledAsync(long showEditionId, bool enabled, CancellationToken ct = default);
    Task<ScoutWatchSnapshot?> GetSnapshotForEditionAsync(long showEditionId, CancellationToken ct = default);
    Task<IReadOnlyList<ScoutWatchSnapshot>> GetWatchQueueAsync(CancellationToken ct = default);
    Task<(bool Created, string Message)> LinkScoutShowAsync(string controlAppShowId, ScoutLinkRequest request, CancellationToken ct = default);
    Task<(bool Applied, bool Duplicate, string Message)> ApplyFactAsync(string controlAppShowId, ScoutFactUpdateRequest request, CancellationToken ct = default);
    Task<(bool Created, bool Duplicate, string Message)> RegisterDocumentAsync(string controlAppShowId, ScoutDocumentRequest request, CancellationToken ct = default);
}

public sealed class ScoutIntegrationService : IScoutIntegrationService
{
    private readonly ShowArmDbContext _db;

    // This is the hard security boundary. Scout cannot write anything not explicitly listed here.
    private static readonly HashSet<string> AllowedFactFields = new(StringComparer.OrdinalIgnoreCase)
    {
        "CanonicalShowName", "OrganizerName", "PrimaryLocation", "OfficialWebsiteUrl", "CurrentEventYear", "KnownAliases",
        "LastScoutUpdateAt", "LastVerifiedAt", "ScoutMatchConfidence",
        "ApplicationOpen", "ApplicationOpenDate", "ApplicationDeadline", "ApplicationUrl", "ApplicationMethod",
        "BoothFee", "JuryFee", "CommissionRate", "EventStartDate", "EventEndDate", "AcceptanceWindow",
        "VendorPacketUrl", "VendorMapUrl", "LoadInInstructionsUrl", "CancellationStatus",
        "OrganizerContactName", "OrganizerContactEmail", "OrganizerContactPhone"
    };

    // Explicitly documented protected concepts. These never pass through ApplyFactAsync.
    public static readonly string[] ProtectedOperationalFields =
    {
        "Recommendation", "OwnerDecision", "ApplicationStatus", "PaymentStatus", "AssignedVendor", "Backer", "BoothAssignment",
        "PromoterDecision", "AcceptedRejectedStatus", "OwnerNotes", "ActualSales", "Expenses", "Profitability", "CompletedTask", "Committed"
    };

    public ScoutIntegrationService(ShowArmDbContext db) => _db = db;

    public async Task EnsureSchemaAsync(CancellationToken ct = default)
    {
        var sql = """
CREATE TABLE IF NOT EXISTS scout_show_links (
    id BIGSERIAL PRIMARY KEY,
    show_event_id BIGINT NOT NULL UNIQUE,
    current_show_edition_id BIGINT NULL,
    control_app_show_id TEXT NOT NULL UNIQUE,
    scout_watch_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    scout_show_id TEXT NULL,
    canonical_show_name TEXT NOT NULL,
    organizer_name TEXT NULL,
    primary_location TEXT NULL,
    official_website_url TEXT NULL,
    current_event_year INTEGER NOT NULL DEFAULT 2027,
    known_aliases TEXT NULL,
    last_scout_update_at TIMESTAMPTZ NULL,
    last_verified_at TIMESTAMPTZ NULL,
    scout_match_confidence NUMERIC(5,4) NULL,
    application_open BOOLEAN NULL,
    application_open_date DATE NULL,
    application_deadline DATE NULL,
    application_url TEXT NULL,
    application_method TEXT NULL,
    booth_fee NUMERIC(12,2) NULL,
    jury_fee NUMERIC(12,2) NULL,
    commission_rate NUMERIC(8,4) NULL,
    event_start_date DATE NULL,
    event_end_date DATE NULL,
    acceptance_window TEXT NULL,
    vendor_packet_url TEXT NULL,
    vendor_map_url TEXT NULL,
    load_in_instructions_url TEXT NULL,
    cancellation_status TEXT NULL,
    organizer_contact_name TEXT NULL,
    organizer_contact_email TEXT NULL,
    organizer_contact_phone TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ix_scout_show_links_watch ON scout_show_links(scout_watch_enabled, current_event_year);

CREATE TABLE IF NOT EXISTS scout_fact_changes (
    id BIGSERIAL PRIMARY KEY,
    show_event_id BIGINT NOT NULL,
    show_edition_id BIGINT NULL,
    control_app_show_id TEXT NOT NULL,
    scout_show_id TEXT NULL,
    field_name TEXT NOT NULL,
    source_url TEXT NULL,
    source_type TEXT NULL,
    source_title TEXT NULL,
    observed_at TIMESTAMPTZ NOT NULL,
    verified_at TIMESTAMPTZ NULL,
    confidence NUMERIC(5,4) NULL,
    previous_value TEXT NULL,
    new_value TEXT NULL,
    idempotency_key TEXT NOT NULL UNIQUE,
    applied BOOLEAN NOT NULL DEFAULT FALSE,
    rejection_reason TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS ix_scout_fact_changes_show ON scout_fact_changes(control_app_show_id, created_at DESC);

CREATE TABLE IF NOT EXISTS scout_documents (
    id BIGSERIAL PRIMARY KEY,
    document_id TEXT NOT NULL UNIQUE,
    show_event_id BIGINT NOT NULL,
    show_edition_id BIGINT NULL,
    control_app_show_id TEXT NOT NULL,
    document_type TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    source_url TEXT NULL,
    downloaded_at TIMESTAMPTZ NOT NULL,
    event_year INTEGER NOT NULL,
    version_or_date TEXT NULL,
    file_hash TEXT NOT NULL,
    stored_path TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(show_event_id, file_hash)
);
CREATE INDEX IF NOT EXISTS ix_scout_documents_show ON scout_documents(control_app_show_id, event_year);
""";
        await _db.Database.ExecuteSqlRawAsync(sql, ct);
    }

    public async Task<ScoutWatchSnapshot?> EnsureWatchLinkAsync(long showEditionId, CancellationToken ct = default)
    {
        await EnsureSchemaAsync(ct);
        var edition = await _db.ShowEditions.Include(x => x.ShowEvent).FirstOrDefaultAsync(x => x.Id == showEditionId, ct);
        if (edition is null) return null;

        var controlId = ControlId(edition.ShowEventId);
        var location = string.Join(", ", new[] { edition.ShowEvent.City, edition.ShowEvent.State }.Where(x => !string.IsNullOrWhiteSpace(x)));
        await ExecuteAsync("""
INSERT INTO scout_show_links
(show_event_id,current_show_edition_id,control_app_show_id,scout_watch_enabled,canonical_show_name,organizer_name,primary_location,official_website_url,current_event_year,application_open_date,application_deadline,booth_fee,jury_fee,event_start_date,event_end_date,updated_at)
VALUES (@event,@edition,@control,@enabled,@name,@organizer,@location,@website,@year,@open_date,@deadline,@booth,@jury,@start,@end,NOW())
ON CONFLICT (show_event_id) DO UPDATE SET
 current_show_edition_id=EXCLUDED.current_show_edition_id,
 control_app_show_id=scout_show_links.control_app_show_id,
 scout_watch_enabled=EXCLUDED.scout_watch_enabled,
 canonical_show_name=COALESCE(NULLIF(scout_show_links.canonical_show_name,''),EXCLUDED.canonical_show_name),
 organizer_name=COALESCE(scout_show_links.organizer_name,EXCLUDED.organizer_name),
 primary_location=COALESCE(scout_show_links.primary_location,EXCLUDED.primary_location),
 official_website_url=COALESCE(scout_show_links.official_website_url,EXCLUDED.official_website_url),
 current_event_year=EXCLUDED.current_event_year,
 application_open_date=COALESCE(scout_show_links.application_open_date,EXCLUDED.application_open_date),
 application_deadline=COALESCE(scout_show_links.application_deadline,EXCLUDED.application_deadline),
 booth_fee=COALESCE(scout_show_links.booth_fee,EXCLUDED.booth_fee),
 jury_fee=COALESCE(scout_show_links.jury_fee,EXCLUDED.jury_fee),
 event_start_date=COALESCE(scout_show_links.event_start_date,EXCLUDED.event_start_date),
 event_end_date=COALESCE(scout_show_links.event_end_date,EXCLUDED.event_end_date),
 updated_at=NOW();
""", new Dictionary<string, object?>
        {
            ["event"] = edition.ShowEventId, ["edition"] = edition.Id, ["control"] = controlId,
            ["enabled"] = edition.Recommendation == "OWNER_APPROVE", ["name"] = edition.ShowEvent.Name,
            ["organizer"] = edition.ShowEvent.PromoterName, ["location"] = string.IsNullOrWhiteSpace(location) ? null : location,
            ["website"] = edition.ShowEvent.WebsiteUrl, ["year"] = edition.Year,
            ["open_date"] = edition.ApplicationOpenDate, ["deadline"] = edition.ApplicationDeadline,
            ["booth"] = edition.BoothFee, ["jury"] = edition.JuryFee,
            ["start"] = edition.StartDate, ["end"] = edition.EndDate
        }, ct);
        return await GetByControlIdAsync(controlId, ct);
    }

    public async Task SetWatchEnabledAsync(long showEditionId, bool enabled, CancellationToken ct = default)
    {
        var snapshot = await EnsureWatchLinkAsync(showEditionId, ct);
        if (snapshot is null) return;
        await ExecuteAsync("UPDATE scout_show_links SET scout_watch_enabled=@enabled, updated_at=NOW() WHERE control_app_show_id=@control",
            new() { ["enabled"] = enabled, ["control"] = snapshot.ControlAppShowId }, ct);
    }

    public async Task<ScoutWatchSnapshot?> GetSnapshotForEditionAsync(long showEditionId, CancellationToken ct = default)
    {
        await EnsureSchemaAsync(ct);
        var eventId = await _db.ShowEditions.AsNoTracking().Where(x => x.Id == showEditionId).Select(x => (long?)x.ShowEventId).FirstOrDefaultAsync(ct);
        return eventId is null ? null : await GetByControlIdAsync(ControlId(eventId.Value), ct);
    }

    public async Task<IReadOnlyList<ScoutWatchSnapshot>> GetWatchQueueAsync(CancellationToken ct = default)
    {
        await EnsureSchemaAsync(ct);
        var approvedIds = await _db.ShowEditions.AsNoTracking().Where(x => x.Year >= 2027 && x.Recommendation == "OWNER_APPROVE").Select(x => x.Id).ToListAsync(ct);
        foreach (var id in approvedIds) await EnsureWatchLinkAsync(id, ct);
        return await QuerySnapshotsAsync("SELECT * FROM scout_show_links WHERE scout_watch_enabled=TRUE ORDER BY current_event_year,event_start_date NULLS LAST,canonical_show_name", new(), ct);
    }

    public async Task<(bool Created, string Message)> LinkScoutShowAsync(string controlAppShowId, ScoutLinkRequest request, CancellationToken ct = default)
    {
        await EnsureSchemaAsync(ct);
        var snap = await GetByControlIdAsync(controlAppShowId, ct);
        if (snap is null) return (false, "Unknown ControlAppShowId.");
        await ExecuteAsync("""
UPDATE scout_show_links SET scout_show_id=@scout, known_aliases=@aliases,
 scout_match_confidence=@confidence, last_scout_update_at=NOW(), updated_at=NOW()
WHERE control_app_show_id=@control
""", new() { ["scout"] = request.ScoutShowId, ["aliases"] = request.KnownAliases, ["confidence"] = request.ScoutMatchConfidence, ["control"] = controlAppShowId }, ct);
        return (true, "Scout link updated.");
    }

    public async Task<(bool Applied, bool Duplicate, string Message)> ApplyFactAsync(string controlAppShowId, ScoutFactUpdateRequest request, CancellationToken ct = default)
    {
        await EnsureSchemaAsync(ct);
        var field = request.FieldName?.Trim() ?? "";
        if (!AllowedFactFields.Contains(field))
        {
            return (false, false, $"Field '{field}' is protected or not Scout-writable. Scout may update verified factual/research fields only.");
        }
        var snap = await GetByControlIdAsync(controlAppShowId, ct);
        if (snap is null) return (false, false, "Unknown ControlAppShowId.");

        var normalized = Normalize(request.NewValue);
        var idem = string.IsNullOrWhiteSpace(request.IdempotencyKey)
            ? Sha256($"{controlAppShowId}|{field.ToUpperInvariant()}|{normalized}|{Normalize(request.SourceUrl)}")
            : request.IdempotencyKey.Trim();
        if (await ExistsAsync("SELECT 1 FROM scout_fact_changes WHERE idempotency_key=@key", new() { ["key"] = idem }, ct))
            return (false, true, "Duplicate Scout fact ignored; audit history was not duplicated.");

        var previous = SnapshotValue(snap, field);
        var observed = request.ObservedAt ?? DateTimeOffset.UtcNow;
        await ExecuteAsync("""
INSERT INTO scout_fact_changes
(show_event_id,show_edition_id,control_app_show_id,scout_show_id,field_name,source_url,source_type,source_title,observed_at,verified_at,confidence,previous_value,new_value,idempotency_key,applied)
VALUES (@event,@edition,@control,@scout,@field,@url,@type,@title,@observed,@verified,@confidence,@previous,@new,@key,FALSE)
""", new()
        {
            ["event"] = snap.ShowEventId, ["edition"] = snap.CurrentShowEditionId, ["control"] = controlAppShowId, ["scout"] = snap.ScoutShowId,
            ["field"] = field, ["url"] = request.SourceUrl, ["type"] = request.SourceType, ["title"] = request.SourceTitle,
            ["observed"] = observed, ["verified"] = request.VerifiedAt, ["confidence"] = request.Confidence,
            ["previous"] = previous, ["new"] = request.NewValue, ["key"] = idem
        }, ct);

        try
        {
            await ApplySnapshotFieldAsync(snap, field, request.NewValue, ct);
            await MirrorExistingControlFieldsAsync(snap, field, request.NewValue, ct);
            await ExecuteAsync("UPDATE scout_fact_changes SET applied=TRUE WHERE idempotency_key=@key", new() { ["key"] = idem }, ct);
            return (true, false, previous == request.NewValue ? "Fact confirmed; value unchanged but provenance recorded." : $"{field}: {previous ?? "(blank)"} → {request.NewValue ?? "(blank)"}");
        }
        catch (Exception ex)
        {
            await ExecuteAsync("UPDATE scout_fact_changes SET rejection_reason=@reason WHERE idempotency_key=@key", new() { ["reason"] = ex.Message, ["key"] = idem }, ct);
            return (false, false, "Fact was audited but not applied: " + ex.Message);
        }
    }

    public async Task<(bool Created, bool Duplicate, string Message)> RegisterDocumentAsync(string controlAppShowId, ScoutDocumentRequest request, CancellationToken ct = default)
    {
        await EnsureSchemaAsync(ct);
        var snap = await GetByControlIdAsync(controlAppShowId, ct);
        if (snap is null) return (false, false, "Unknown ControlAppShowId.");
        if (string.IsNullOrWhiteSpace(request.FileHash)) return (false, false, "FileHash is required.");
        if (await ExistsAsync("SELECT 1 FROM scout_documents WHERE show_event_id=@event AND lower(file_hash)=lower(@hash)", new() { ["event"] = snap.ShowEventId, ["hash"] = request.FileHash.Trim() }, ct))
            return (false, true, "Document already filed for this show; duplicate hash ignored.");

        await ExecuteAsync("""
INSERT INTO scout_documents(document_id,show_event_id,show_edition_id,control_app_show_id,document_type,original_filename,source_url,downloaded_at,event_year,version_or_date,file_hash,stored_path)
VALUES(@doc,@event,@edition,@control,@type,@filename,@url,@downloaded,@year,@version,@hash,@path)
ON CONFLICT (document_id) DO NOTHING
""", new()
        {
            ["doc"] = request.DocumentId, ["event"] = snap.ShowEventId, ["edition"] = snap.CurrentShowEditionId, ["control"] = controlAppShowId,
            ["type"] = request.DocumentType, ["filename"] = request.OriginalFilename, ["url"] = request.SourceUrl,
            ["downloaded"] = request.DownloadedAt, ["year"] = request.EventYear, ["version"] = request.VersionOrDate,
            ["hash"] = request.FileHash.Trim(), ["path"] = request.StoredPath
        }, ct);
        await ExecuteAsync("UPDATE scout_show_links SET last_scout_update_at=NOW(), updated_at=NOW() WHERE control_app_show_id=@control", new() { ["control"] = controlAppShowId }, ct);
        return (true, false, "Document metadata filed.");
    }

    private async Task ApplySnapshotFieldAsync(ScoutWatchSnapshot snap, string field, string? value, CancellationToken ct)
    {
        var column = field switch
        {
            "CanonicalShowName" => "canonical_show_name", "OrganizerName" => "organizer_name", "PrimaryLocation" => "primary_location",
            "OfficialWebsiteUrl" => "official_website_url", "CurrentEventYear" => "current_event_year", "KnownAliases" => "known_aliases",
            "LastScoutUpdateAt" => "last_scout_update_at", "LastVerifiedAt" => "last_verified_at", "ScoutMatchConfidence" => "scout_match_confidence",
            "ApplicationOpen" => "application_open", "ApplicationOpenDate" => "application_open_date", "ApplicationDeadline" => "application_deadline",
            "ApplicationUrl" => "application_url", "ApplicationMethod" => "application_method", "BoothFee" => "booth_fee", "JuryFee" => "jury_fee",
            "CommissionRate" => "commission_rate", "EventStartDate" => "event_start_date", "EventEndDate" => "event_end_date",
            "AcceptanceWindow" => "acceptance_window", "VendorPacketUrl" => "vendor_packet_url", "VendorMapUrl" => "vendor_map_url",
            "LoadInInstructionsUrl" => "load_in_instructions_url", "CancellationStatus" => "cancellation_status",
            "OrganizerContactName" => "organizer_contact_name", "OrganizerContactEmail" => "organizer_contact_email", "OrganizerContactPhone" => "organizer_contact_phone",
            _ => throw new InvalidOperationException("Field is not Scout-writable.")
        };
        var converted = ConvertFieldValue(field, value);
        await ExecuteAsync($"UPDATE scout_show_links SET {column}=@value, last_scout_update_at=NOW(), updated_at=NOW() WHERE control_app_show_id=@control",
            new() { ["value"] = converted, ["control"] = snap.ControlAppShowId }, ct);
    }

    private async Task MirrorExistingControlFieldsAsync(ScoutWatchSnapshot snap, string field, string? value, CancellationToken ct)
    {
        if (snap.CurrentShowEditionId is not long editionId) return;
        var edition = await _db.ShowEditions.Include(x => x.ShowEvent).FirstOrDefaultAsync(x => x.Id == editionId, ct);
        if (edition is null) return;
        switch (field)
        {
            case "CanonicalShowName": if (!string.IsNullOrWhiteSpace(value)) edition.ShowEvent.Name = value.Trim(); break;
            case "OrganizerName": edition.ShowEvent.PromoterName = BlankToNull(value); break;
            case "OfficialWebsiteUrl": edition.ShowEvent.WebsiteUrl = BlankToNull(value); break;
            case "ApplicationOpenDate": edition.ApplicationOpenDate = ParseDate(value); break;
            case "ApplicationDeadline": edition.ApplicationDeadline = ParseDate(value); break;
            case "BoothFee": edition.BoothFee = ParseDecimal(value); break;
            case "JuryFee": edition.JuryFee = ParseDecimal(value); break;
            case "EventStartDate": edition.StartDate = ParseDate(value); break;
            case "EventEndDate": edition.EndDate = ParseDate(value); break;
            default: return; // Stored in Scout factual snapshot only; operational fields are not inferred.
        }
        edition.UpdatedAt = DateTimeOffset.UtcNow;
        edition.ShowEvent.UpdatedAt = DateTimeOffset.UtcNow;
        await _db.SaveChangesAsync(ct);
    }

    private async Task<ScoutWatchSnapshot?> GetByControlIdAsync(string controlAppShowId, CancellationToken ct)
        => (await QuerySnapshotsAsync("SELECT * FROM scout_show_links WHERE control_app_show_id=@control LIMIT 1", new() { ["control"] = controlAppShowId }, ct)).FirstOrDefault();

    private async Task<IReadOnlyList<ScoutWatchSnapshot>> QuerySnapshotsAsync(string sql, Dictionary<string, object?> args, CancellationToken ct)
    {
        var rows = new List<ScoutWatchSnapshot>();
        var conn = _db.Database.GetDbConnection();
        if (conn.State != ConnectionState.Open) await conn.OpenAsync(ct);
        await using var cmd = conn.CreateCommand(); cmd.CommandText = sql; AddParameters(cmd, args);
        await using var r = await cmd.ExecuteReaderAsync(ct);
        while (await r.ReadAsync(ct))
        {
            rows.Add(new ScoutWatchSnapshot(
                GetString(r,"control_app_show_id")!, GetInt64(r,"show_event_id"), GetNullableInt64(r,"current_show_edition_id"), GetBool(r,"scout_watch_enabled"),
                GetString(r,"scout_show_id"), GetString(r,"canonical_show_name") ?? "", GetString(r,"organizer_name"), GetString(r,"primary_location"), GetString(r,"official_website_url"),
                GetInt32(r,"current_event_year"), GetString(r,"known_aliases"), GetDateTimeOffset(r,"last_scout_update_at"), GetDateTimeOffset(r,"last_verified_at"), GetDecimal(r,"scout_match_confidence"),
                GetNullableBool(r,"application_open"), GetDateOnly(r,"application_open_date"), GetDateOnly(r,"application_deadline"), GetString(r,"application_url"), GetString(r,"application_method"),
                GetDecimal(r,"booth_fee"), GetDecimal(r,"jury_fee"), GetDecimal(r,"commission_rate"), GetDateOnly(r,"event_start_date"), GetDateOnly(r,"event_end_date"), GetString(r,"acceptance_window"),
                GetString(r,"vendor_packet_url"), GetString(r,"vendor_map_url"), GetString(r,"load_in_instructions_url"), GetString(r,"cancellation_status"), GetString(r,"organizer_contact_name"), GetString(r,"organizer_contact_email"), GetString(r,"organizer_contact_phone")));
        }
        return rows;
    }

    private async Task ExecuteAsync(string sql, Dictionary<string, object?> args, CancellationToken ct)
    {
        var conn = _db.Database.GetDbConnection();
        if (conn.State != ConnectionState.Open) await conn.OpenAsync(ct);
        await using var cmd = conn.CreateCommand(); cmd.CommandText = sql; AddParameters(cmd, args); await cmd.ExecuteNonQueryAsync(ct);
    }
    private async Task<bool> ExistsAsync(string sql, Dictionary<string, object?> args, CancellationToken ct)
    {
        var conn = _db.Database.GetDbConnection();
        if (conn.State != ConnectionState.Open) await conn.OpenAsync(ct);
        await using var cmd = conn.CreateCommand(); cmd.CommandText = sql; AddParameters(cmd, args); return await cmd.ExecuteScalarAsync(ct) is not null;
    }
    private static void AddParameters(System.Data.Common.DbCommand cmd, Dictionary<string, object?> args)
    {
        foreach (var kv in args) { var p = cmd.CreateParameter(); p.ParameterName = "@" + kv.Key; p.Value = ToDb(kv.Value); cmd.Parameters.Add(p); }
    }
    private static object ToDb(object? value) => value switch
    {
        null => DBNull.Value,
        DateOnly d => d.ToDateTime(TimeOnly.MinValue),
        _ => value
    };

    private static string ControlId(long eventId) => $"AI-SHOW-{eventId}";
    private static string Normalize(string? s) => (s ?? "").Trim();
    private static string Sha256(string s) => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(s))).ToLowerInvariant();
    private static string? BlankToNull(string? s) => string.IsNullOrWhiteSpace(s) ? null : s.Trim();
    private static DateOnly? ParseDate(string? s) => DateOnly.TryParse(s, CultureInfo.InvariantCulture, DateTimeStyles.None, out var d) ? d : null;
    private static decimal? ParseDecimal(string? s) => decimal.TryParse(s, NumberStyles.Any, CultureInfo.InvariantCulture, out var d) ? d : null;
    private static object? ConvertFieldValue(string field, string? value) => field switch
    {
        "CurrentEventYear" => int.TryParse(value, out var i) ? i : throw new InvalidOperationException("CurrentEventYear must be an integer."),
        "ApplicationOpen" => bool.TryParse(value, out var b) ? b : throw new InvalidOperationException("ApplicationOpen must be true/false."),
        "ApplicationOpenDate" or "ApplicationDeadline" or "EventStartDate" or "EventEndDate" => ParseDate(value),
        "BoothFee" or "JuryFee" or "CommissionRate" or "ScoutMatchConfidence" => ParseDecimal(value),
        "LastScoutUpdateAt" or "LastVerifiedAt" => DateTimeOffset.TryParse(value, out var dto) ? dto : null,
        _ => BlankToNull(value)
    };

    private static string? SnapshotValue(ScoutWatchSnapshot s, string f) => f switch
    {
        "CanonicalShowName" => s.CanonicalShowName, "OrganizerName" => s.OrganizerName, "PrimaryLocation" => s.PrimaryLocation, "OfficialWebsiteUrl" => s.OfficialWebsiteUrl,
        "CurrentEventYear" => s.CurrentEventYear.ToString(CultureInfo.InvariantCulture), "KnownAliases" => s.KnownAliases,
        "LastScoutUpdateAt" => s.LastScoutUpdateAt?.ToString("O"), "LastVerifiedAt" => s.LastVerifiedAt?.ToString("O"), "ScoutMatchConfidence" => s.ScoutMatchConfidence?.ToString(CultureInfo.InvariantCulture),
        "ApplicationOpen" => s.ApplicationOpen?.ToString(), "ApplicationOpenDate" => s.ApplicationOpenDate?.ToString("yyyy-MM-dd"), "ApplicationDeadline" => s.ApplicationDeadline?.ToString("yyyy-MM-dd"),
        "ApplicationUrl" => s.ApplicationUrl, "ApplicationMethod" => s.ApplicationMethod, "BoothFee" => s.BoothFee?.ToString(CultureInfo.InvariantCulture), "JuryFee" => s.JuryFee?.ToString(CultureInfo.InvariantCulture),
        "CommissionRate" => s.CommissionRate?.ToString(CultureInfo.InvariantCulture), "EventStartDate" => s.EventStartDate?.ToString("yyyy-MM-dd"), "EventEndDate" => s.EventEndDate?.ToString("yyyy-MM-dd"),
        "AcceptanceWindow" => s.AcceptanceWindow, "VendorPacketUrl" => s.VendorPacketUrl, "VendorMapUrl" => s.VendorMapUrl, "LoadInInstructionsUrl" => s.LoadInInstructionsUrl,
        "CancellationStatus" => s.CancellationStatus, "OrganizerContactName" => s.OrganizerContactName, "OrganizerContactEmail" => s.OrganizerContactEmail, "OrganizerContactPhone" => s.OrganizerContactPhone,
        _ => null
    };

    private static int Ord(IDataRecord r, string n) => r.GetOrdinal(n);
    private static string? GetString(IDataRecord r,string n) => r.IsDBNull(Ord(r,n))?null:r.GetString(Ord(r,n));
    private static long GetInt64(IDataRecord r,string n) => Convert.ToInt64(r.GetValue(Ord(r,n)), CultureInfo.InvariantCulture);
    private static long? GetNullableInt64(IDataRecord r,string n) => r.IsDBNull(Ord(r,n))?null:GetInt64(r,n);
    private static int GetInt32(IDataRecord r,string n) => Convert.ToInt32(r.GetValue(Ord(r,n)), CultureInfo.InvariantCulture);
    private static bool GetBool(IDataRecord r,string n) => Convert.ToBoolean(r.GetValue(Ord(r,n)), CultureInfo.InvariantCulture);
    private static bool? GetNullableBool(IDataRecord r,string n) => r.IsDBNull(Ord(r,n))?null:GetBool(r,n);
    private static decimal? GetDecimal(IDataRecord r,string n) => r.IsDBNull(Ord(r,n))?null:Convert.ToDecimal(r.GetValue(Ord(r,n)), CultureInfo.InvariantCulture);
    private static DateTimeOffset? GetDateTimeOffset(IDataRecord r,string n) => r.IsDBNull(Ord(r,n))?null:new DateTimeOffset(Convert.ToDateTime(r.GetValue(Ord(r,n)), CultureInfo.InvariantCulture));
    // Npgsql 10 returns PostgreSQL DATE columns as System.DateOnly. Older providers (and some
    // tests/import paths) may still surface DateTime, DateTimeOffset, or text. Do not use
    // Convert.ToDateTime blindly here: DateOnly does not implement IConvertible and caused
    // the Show Arm to fail at runtime when Scout created/loaded a watch link. Keeping this
    // adapter tolerant also protects the Local -> Ops database move from provider differences.
    private static DateOnly? GetDateOnly(IDataRecord r, string n)
    {
        var ordinal = Ord(r, n);
        if (r.IsDBNull(ordinal)) return null;

        var value = r.GetValue(ordinal);
        return value switch
        {
            DateOnly d => d,
            DateTime dt => DateOnly.FromDateTime(dt),
            DateTimeOffset dto => DateOnly.FromDateTime(dto.DateTime),
            string text when DateOnly.TryParse(text, CultureInfo.InvariantCulture, DateTimeStyles.None, out var parsed) => parsed,
            _ => DateOnly.FromDateTime(Convert.ToDateTime(value, CultureInfo.InvariantCulture))
        };
    }
}

public static class ScoutIntegrationEndpoints
{
    public static void MapScoutIntegrationEndpoints(this WebApplication app)
    {
        var group = app.MapGroup("/api/scout");
        group.AddEndpointFilter(async (context, next) =>
        {
            var expected = Environment.GetEnvironmentVariable("SCOUT_INTEGRATION_KEY");
            if (string.IsNullOrWhiteSpace(expected))
                return Results.Problem("SCOUT_INTEGRATION_KEY is not configured.", statusCode: StatusCodes.Status503ServiceUnavailable);
            var supplied = context.HttpContext.Request.Headers["X-Scout-Key"].ToString();
            if (!CryptographicOperations.FixedTimeEquals(Encoding.UTF8.GetBytes(supplied), Encoding.UTF8.GetBytes(expected)))
                return Results.Unauthorized();
            return await next(context);
        });

        group.MapGet("/shows/watch", async (IScoutIntegrationService scout, CancellationToken ct) => Results.Ok(await scout.GetWatchQueueAsync(ct)));
        group.MapPost("/shows/{controlAppShowId}/link", async (string controlAppShowId, ScoutLinkRequest req, IScoutIntegrationService scout, CancellationToken ct) =>
        {
            var result = await scout.LinkScoutShowAsync(controlAppShowId, req, ct);
            return result.Created ? Results.Ok(result) : Results.NotFound(result);
        });
        group.MapPost("/shows/{controlAppShowId}/facts", async (string controlAppShowId, ScoutFactUpdateRequest req, IScoutIntegrationService scout, CancellationToken ct) =>
        {
            var result = await scout.ApplyFactAsync(controlAppShowId, req, ct);
            return result.Duplicate ? Results.Ok(result) : result.Applied ? Results.Ok(result) : Results.BadRequest(result);
        });
        group.MapPost("/shows/{controlAppShowId}/documents", async (string controlAppShowId, ScoutDocumentRequest req, IScoutIntegrationService scout, CancellationToken ct) =>
        {
            var result = await scout.RegisterDocumentAsync(controlAppShowId, req, ct);
            return result.Duplicate || result.Created ? Results.Ok(result) : Results.BadRequest(result);
        });
        group.MapGet("/protected-fields", () => Results.Ok(ScoutIntegrationService.ProtectedOperationalFields));
    }
}
