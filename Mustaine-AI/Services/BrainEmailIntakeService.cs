using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using MailKit;
using MailKit.Net.Imap;
using MailKit.Search;
using Microsoft.EntityFrameworkCore;
using MimeKit;
using MustaineAI.Data;

namespace MustaineAI.Services;

public enum BrainEmailImportScope
{
    DirectBrainAddress,
    BusinessInboxes
}

public sealed record BrainEmailImportResult(int Found, int Imported, int Duplicates, string Message);
public sealed record HistoricalEmailPreviewResult(int Total, int ShowRelated, int ShowMatched, int Financial, int Operations, int Junk, int Uncertain, string Message);
public sealed record HistoricalEmailImportResult(int Total, int Imported, int Duplicates, int ShowMatched, int ReviewNeeded, string Message);
public sealed record CurrentEmailReprocessResult(int Reviewed, int Changed, int NeedsYou, int NeedsReview, int BrainHandled, int Ignored, string Message);
public sealed record CurrentAttachmentBackfillResult(int StoredEmails, int MailMessagesScanned, int MessagesWithAttachments, int FilesSaved, int FilesAlreadyPresent, int ShowDocumentsAdded, int StoredEmailsNotFound, string Message);
public interface IBrainEmailIntakeService
{
    Task<BrainEmailImportResult> ImportAsync(BrainEmailImportScope scope = BrainEmailImportScope.BusinessInboxes, CancellationToken ct = default);
    Task<HistoricalEmailPreviewResult> PreviewHistoricalAsync(CancellationToken ct = default);
    Task<HistoricalEmailImportResult> ImportHistoricalAsync(CancellationToken ct = default);
    Task<CurrentEmailReprocessResult> ReprocessCurrentAsync(CancellationToken ct = default);
    Task<CurrentAttachmentBackfillResult> BackfillCurrentAttachmentsAsync(CancellationToken ct = default);
}

public sealed class BrainEmailIntakeService(
    IServiceScopeFactory scopeFactory,
    IConfiguration config,
    IWebHostEnvironment env) : IBrainEmailIntakeService
{
    sealed record MailboxConfig(string Name, string Username, string Password, string Host, int Port, bool UseSsl);
    sealed record RoutedMessage(string Route, string Status, string? BrainSummary, string? ActionSummary, bool IsProtected, string? UnsubscribeUrl, bool UnsubscribeRecommended);

    public async Task<CurrentEmailReprocessResult> ReprocessCurrentAsync(CancellationToken ct = default)
    {
        await using var reprocessScope = scopeFactory.CreateAsyncScope();
        var db = reprocessScope.ServiceProvider.GetRequiredService<ShowArmDbContext>();
        return await ReclassifyStoredAsync(db, ct);
    }

    public async Task<CurrentAttachmentBackfillResult> BackfillCurrentAttachmentsAsync(CancellationToken ct = default)
    {
        var mailboxes = GetMailboxes(BrainEmailImportScope.BusinessInboxes);
        if (mailboxes.Count == 0)
            return new(0, 0, 0, 0, 0, 0, 0, "No monitored business mailbox is configured yet.");

        await using var backfillScope = scopeFactory.CreateAsyncScope();
        var db = backfillScope.ServiceProvider.GetRequiredService<ShowArmDbContext>();
        var stored = await db.ShowEmailIntakes
            .Where(x => x.ExternalMessageId != null
                && !x.ExternalMessageId.StartsWith("archive:")
                && x.MailboxAddress != null)
            .ToListAsync(ct);

        var uploadRoot = Path.Combine(env.WebRootPath, "uploads", "brain-email");
        Directory.CreateDirectory(uploadRoot);

        var scanned = 0;
        var messagesWithAttachments = 0;
        var filesSaved = 0;
        var filesAlreadyPresent = 0;
        var showDocumentsAdded = 0;
        var foundStoredIds = new HashSet<long>();

        foreach (var mailbox in mailboxes)
        {
            var mailboxRows = stored
                .Where(x => string.Equals(x.MailboxAddress, mailbox.Username, StringComparison.OrdinalIgnoreCase))
                .ToList();
            if (mailboxRows.Count == 0) continue;

            var byExternal = mailboxRows
                .Where(x => !string.IsNullOrWhiteSpace(x.ExternalMessageId))
                .GroupBy(x => x.ExternalMessageId!, StringComparer.OrdinalIgnoreCase)
                .ToDictionary(g => g.Key, g => g.First(), StringComparer.OrdinalIgnoreCase);

            using var client = new ImapClient();
            await client.ConnectAsync(mailbox.Host, mailbox.Port, mailbox.UseSsl, ct);
            await client.AuthenticateAsync(mailbox.Username, mailbox.Password, ct);
            var inbox = client.Inbox;
            await inbox.OpenAsync(FolderAccess.ReadOnly, ct);

            var ids = await inbox.SearchAsync(SearchQuery.All, ct);
            // Current-mail intake intentionally uses a recent-message safety window.
            // Scan a wider window for backfill so previously imported messages remain reachable.
            foreach (var uid in ids.TakeLast(750))
            {
                ct.ThrowIfCancellationRequested();
                var msg = await inbox.GetMessageAsync(uid, ct);
                scanned++;

                var rawExternal = !string.IsNullOrWhiteSpace(msg.MessageId) ? msg.MessageId : $"imap:{uid.Id}";
                var external = $"{mailbox.Username.ToLowerInvariant()}|{rawExternal}";
                if (!byExternal.TryGetValue(external, out var row) && !byExternal.TryGetValue(rawExternal, out row))
                    continue;

                foundStoredIds.Add(row.Id);
                var attachments = msg.Attachments.ToList();
                if (attachments.Count == 0) continue;
                messagesWithAttachments++;

                var names = new List<string>();
                var messageKey = StableMessageKey(mailbox.Username, rawExternal);
                var msgDate = msg.Date == DateTimeOffset.MinValue ? DateTimeOffset.UtcNow : msg.Date.ToUniversalTime();
                var dir = Path.Combine(uploadRoot, msgDate.ToString("yyyy"), msgDate.ToString("MM"));
                Directory.CreateDirectory(dir);

                for (var i = 0; i < attachments.Count; i++)
                {
                    var attachment = attachments[i];
                    var fileName = SafeFileName(attachment.ContentDisposition?.FileName ?? attachment.ContentType.Name ?? $"attachment-{i + 1}");
                    names.Add(fileName);
                    var storedName = $"{messageKey}-{i + 1:00}-{fileName}";
                    var path = Path.Combine(dir, storedName);
                    var webPath = "/uploads/brain-email/" + Path.GetRelativePath(uploadRoot, path).Replace('\\', '/');

                    if (File.Exists(path) && new FileInfo(path).Length > 0)
                    {
                        filesAlreadyPresent++;
                    }
                    else
                    {
                        await using var fs = File.Create(path);
                        if (attachment is MimePart part)
                            await part.Content.DecodeToAsync(fs, ct);
                        else if (attachment is MessagePart mp)
                            await mp.Message.WriteToAsync(fs, ct);
                        filesSaved++;
                    }

                    if (row.Route == "SHOW")
                    {
                        var exists = await db.ShowDocuments.AnyAsync(x => x.StoredPath == webPath, ct);
                        if (!exists)
                        {
                            db.ShowDocuments.Add(new ShowDocumentEntity
                            {
                                ShowEditionId = row.ShowEditionId,
                                DocumentType = GuessDocumentType(fileName),
                                Title = fileName,
                                StoredPath = webPath,
                                AppliesToYear = row.MessageDate?.Year,
                                Notes = $"Backfilled from current email: {row.Subject}. Email intake #{row.Id}."
                            });
                            showDocumentsAdded++;
                        }
                    }
                }

                var summary = string.Join(", ", names);
                if (!string.Equals(row.AttachmentSummary, summary, StringComparison.Ordinal))
                    row.AttachmentSummary = summary;

                await db.SaveChangesAsync(ct);
            }

            await client.DisconnectAsync(true, ct);
        }

        var candidateWithAttachmentSummary = stored.Count(x => !string.IsNullOrWhiteSpace(x.AttachmentSummary));
        var foundAttachmentRows = stored.Count(x => foundStoredIds.Contains(x.Id) && !string.IsNullOrWhiteSpace(x.AttachmentSummary));
        var notFound = Math.Max(0, candidateWithAttachmentSummary - foundAttachmentRows);

        return new(
            stored.Count, scanned, messagesWithAttachments, filesSaved, filesAlreadyPresent, showDocumentsAdded, notFound,
            $"Attachment backfill complete: scanned {scanned:N0} live mailbox messages; found {messagesWithAttachments:N0} stored message(s) with attachments; saved {filesSaved:N0} file(s), skipped {filesAlreadyPresent:N0} already present, registered {showDocumentsAdded:N0} Show Document(s). {notFound:N0} previously-known attachment email(s) were outside the live mailbox scan window or no longer present. Historical archives were not touched.");
    }

    static string StableMessageKey(string mailbox, string rawExternal)
    {
        var bytes = SHA256.HashData(Encoding.UTF8.GetBytes($"{mailbox.ToLowerInvariant()}|{rawExternal}"));
        return Convert.ToHexString(bytes)[..16].ToLowerInvariant();
    }

    public async Task<BrainEmailImportResult> ImportAsync(BrainEmailImportScope scope = BrainEmailImportScope.BusinessInboxes, CancellationToken ct = default)
    {
        var mailboxes = GetMailboxes(scope);
        if (mailboxes.Count == 0)
            return new(0, 0, 0, scope == BrainEmailImportScope.DirectBrainAddress
                ? "The primary business mailbox is not configured yet."
                : "No monitored business mailbox is configured yet.");

        var intakeAddress = config["BrainEmail:IntakeAddress"]
            ?? Environment.GetEnvironmentVariable("BRAIN_EMAIL_INTAKE_ADDRESS")
            ?? "ai-brain@ancient-innovations.com";

        // IMPORTANT: use a private DbContext scope for the importer. Blazor components
        // have their own scoped DbContext; sharing that instance with a long-running
        // IMAP import causes EF Core's "second operation" concurrency exception.
        await using var importScope = scopeFactory.CreateAsyncScope();
        var db = importScope.ServiceProvider.GetRequiredService<ShowArmDbContext>();

        var totalFound = 0;
        var totalImported = 0;
        var totalDuplicates = 0;
        var editions = await db.ShowEditions.Include(x => x.ShowEvent).ToListAsync(ct);
        var uploadRoot = Path.Combine(env.WebRootPath, "uploads", "brain-email");
        Directory.CreateDirectory(uploadRoot);

        foreach (var mailbox in mailboxes)
        {
            using var client = new ImapClient();
            await client.ConnectAsync(mailbox.Host, mailbox.Port, mailbox.UseSsl, ct);
            await client.AuthenticateAsync(mailbox.Username, mailbox.Password, ct);
            var inbox = client.Inbox;
            await inbox.OpenAsync(FolderAccess.ReadOnly, ct);

            var ids = await inbox.SearchAsync(SearchQuery.All, ct);
            var recent = ids.TakeLast(250).ToList();

            foreach (var uid in recent)
            {
                var msg = await inbox.GetMessageAsync(uid, ct);
                if (scope == BrainEmailImportScope.DirectBrainAddress && !WasDeliveredTo(msg, intakeAddress))
                    continue;

                totalFound++;
                var rawExternal = !string.IsNullOrWhiteSpace(msg.MessageId) ? msg.MessageId : $"imap:{uid.Id}";
                var external = $"{mailbox.Username.ToLowerInvariant()}|{rawExternal}";

                if (await db.ShowEmailIntakes.AnyAsync(x => x.ExternalMessageId == external || x.ExternalMessageId == rawExternal, ct))
                {
                    totalDuplicates++;
                    continue;
                }

                var body = msg.TextBody ?? msg.HtmlBody ?? string.Empty;
                var combined = $"{msg.Subject} {body}";
                var intelligence = Analyze(msg, combined);

                // Pass 9.10: existing show identity is evidence too.  A message such as
                // "Frankfort PDFs / map and instructions" should be recognized as show mail
                // even when it does not literally contain the word festival/fair/vendor.
                var match = BestShowMatch(editions, msg.Subject ?? string.Empty, body, msg.Date.Year)
                    ?? BestCurrentShowHintMatch(editions, msg.Subject ?? string.Empty, body, msg.Date.Year);
                if (match is not null && (intelligence.Route is "UNKNOWN" or "OPERATIONS") && LooksLikeShowLogistics(combined))
                {
                    var actionSignal = FindActionSignal(combined);
                    intelligence = intelligence with
                    {
                        Route = "SHOW",
                        Status = actionSignal is null ? "BRAIN_HANDLED" : "NEEDS_YOU",
                        BrainSummary = $"Show Arm: recognized as {match.ShowEvent.Name} · {match.Year} from show identity/logistics evidence.",
                        ActionSummary = actionSignal is null ? null : $"Decision/action signal: ‘{actionSignal}’."
                    };
                }
                if (intelligence.Route != "SHOW") match = null;

                var status = intelligence.Status;
                if (intelligence.Route == "SHOW" && match is null && status != "IGNORED")
                    status = FindActionSignal(combined) is null ? "BRAIN_HANDLED" : "NEEDS_REVIEW";

                var attachNames = new List<string>();
                foreach (var attachment in msg.Attachments)
                {
                    var fileName = SafeFileName(attachment.ContentDisposition?.FileName ?? attachment.ContentType.Name ?? "attachment");
                    var now = DateTime.UtcNow;
                    var dir = Path.Combine(uploadRoot, now.ToString("yyyy"), now.ToString("MM"));
                    Directory.CreateDirectory(dir);
                    var path = Path.Combine(dir, $"{Math.Abs(mailbox.Username.GetHashCode())}-{uid.Id}-{fileName}");

                    await using var fs = File.Create(path);
                    if (attachment is MimePart part)
                        await part.Content.DecodeToAsync(fs, ct);
                    else if (attachment is MessagePart mp)
                        await mp.Message.WriteToAsync(fs, ct);

                    attachNames.Add(fileName);
                    if (intelligence.Route == "SHOW")
                    {
                        db.ShowDocuments.Add(new ShowDocumentEntity
                        {
                            ShowEditionId = match?.Id,
                            DocumentType = GuessDocumentType(fileName),
                            Title = fileName,
                            StoredPath = "/uploads/brain-email/" + Path.GetRelativePath(uploadRoot, path).Replace('\\', '/'),
                            AppliesToYear = match?.Year,
                            Notes = $"Imported from {mailbox.Name}: {msg.Subject}"
                        });
                    }
                }

                var toAddress = string.Join(", ", msg.To.Mailboxes.Select(x => x.Address));
                if (string.IsNullOrWhiteSpace(toAddress))
                    toAddress = HeaderValue(msg, "Delivered-To") ?? string.Empty;

                db.ShowEmailIntakes.Add(new ShowEmailIntakeEntity
                {
                    ShowEditionId = match?.Id,
                    ExternalMessageId = external,
                    ToAddress = toAddress,
                    FromAddress = string.Join(", ", msg.From.Mailboxes.Select(x => x.Address)),
                    Subject = msg.Subject,
                    BodyText = body,
                    MessageDate = msg.Date.ToUniversalTime(),
                    Route = intelligence.Route,
                    Status = status,
                    MatchNotes = match is not null
                        ? $"Automatically matched to {match.ShowEvent.Name} · {match.Year}"
                        : intelligence.Route == "SHOW"
                            ? "Show-related email; no confident show/year match."
                            : $"{mailbox.Name} → Brain route: {intelligence.Route}",
                    AttachmentSummary = attachNames.Count == 0 ? null : string.Join(", ", attachNames),
                    MailboxAddress = mailbox.Username,
                    BrainSummary = intelligence.BrainSummary,
                    ActionSummary = intelligence.ActionSummary,
                    IsProtectedSender = intelligence.IsProtected,
                    UnsubscribeUrl = intelligence.UnsubscribeUrl,
                    UnsubscribeRecommended = intelligence.UnsubscribeRecommended,
                    ReceivedAt = DateTimeOffset.UtcNow
                });

                totalImported++;

                // Save each message independently. This is intentionally conservative:
                // one malformed email cannot roll back a full inbox scan, and this is
                // fast enough for the current 250-message safety window.
                await db.SaveChangesAsync(ct);
                db.ChangeTracker.Clear();
            }

            await client.DisconnectAsync(true, ct);
        }

        // Reclassify previously imported mail using the current trust-building rules.
        // This cleans up older "everything goes to Needs Review" imports without
        // touching the source email in Gmail.
        _ = await ReclassifyStoredAsync(db, ct);

        var label = scope == BrainEmailImportScope.DirectBrainAddress ? intakeAddress : "business inbox feed";
        return new(totalFound, totalImported, totalDuplicates,
            $"Checked {label}: found {totalFound} message(s). Imported {totalImported}; skipped {totalDuplicates} already stored.");
    }

    List<MailboxConfig> GetMailboxes(BrainEmailImportScope scope)
    {
        var result = new List<MailboxConfig>();
        void Add(string name, string? user, string? pass, string? host = null, int port = 993, bool ssl = true)
        {
            if (!string.IsNullOrWhiteSpace(user) && !string.IsNullOrWhiteSpace(pass)
                && !result.Any(x => x.Username.Equals(user, StringComparison.OrdinalIgnoreCase)))
                result.Add(new(name, user, pass, string.IsNullOrWhiteSpace(host) ? "imap.gmail.com" : host, port, ssl));
        }

        Add("Primary business email",
            config["BrainEmail:Username"] ?? Environment.GetEnvironmentVariable("BRAIN_EMAIL_USERNAME"),
            config["BrainEmail:AppPassword"] ?? Environment.GetEnvironmentVariable("BRAIN_EMAIL_APP_PASSWORD"));

        if (scope == BrainEmailImportScope.BusinessInboxes)
        {
            var secondHost = config["BrainEmail2:Host"] ?? Environment.GetEnvironmentVariable("BRAIN_EMAIL_2_HOST") ?? "imap.gmail.com";
            var secondPort = int.TryParse(config["BrainEmail2:Port"] ?? Environment.GetEnvironmentVariable("BRAIN_EMAIL_2_PORT"), out var p) ? p : 993;
            Add("Second business email",
                config["BrainEmail2:Username"] ?? Environment.GetEnvironmentVariable("BRAIN_EMAIL_2_USERNAME"),
                config["BrainEmail2:AppPassword"] ?? Environment.GetEnvironmentVariable("BRAIN_EMAIL_2_APP_PASSWORD"),
                secondHost, secondPort, true);
        }

        return result;
    }

    static bool WasDeliveredTo(MimeMessage msg, string address)
    {
        bool Match(IEnumerable<MailboxAddress> values) => values.Any(x => x.Address.Equals(address, StringComparison.OrdinalIgnoreCase));
        if (Match(msg.To.Mailboxes) || Match(msg.Cc.Mailboxes) || Match(msg.Bcc.Mailboxes)) return true;

        foreach (var headerName in new[] { "Delivered-To", "X-Original-To", "Envelope-To", "X-Forwarded-To", "Resent-To", "X-Envelope-To", "Original-Recipient" })
        {
            var value = HeaderValue(msg, headerName);
            if (!string.IsNullOrWhiteSpace(value) && value.Contains(address, StringComparison.OrdinalIgnoreCase))
                return true;
        }
        // Gmail aliases are not always preserved in To after delivery. Search all raw
        // recipient-style headers as a final read-only fallback.
        if (msg.Headers.Any(h => h.Value?.Contains(address, StringComparison.OrdinalIgnoreCase) == true)) return true;
        return false;
    }

    public async Task<HistoricalEmailPreviewResult> PreviewHistoricalAsync(CancellationToken ct = default)
    {
        await using var importScope = scopeFactory.CreateAsyncScope();
        var db = importScope.ServiceProvider.GetRequiredService<ShowArmDbContext>();
        var editions = await db.ShowEditions.Include(x => x.ShowEvent).ToListAsync(ct);
        var total = 0; var show = 0; var matched = 0; var financial = 0; var operations = 0; var junk = 0; var uncertain = 0;

        foreach (var item in EnumerateHistoricalMessages())
        {
            ct.ThrowIfCancellationRequested();
            total++;
            var msg = item.Message;
            var body = msg.TextBody ?? msg.HtmlBody ?? string.Empty;
            var intelligence = Analyze(msg, $"{msg.Subject} {body}");
            if (intelligence.Route == "SHOW")
            {
                show++;
                var m = BestShowMatch(editions, msg.Subject ?? string.Empty, body, msg.Date.Year);
                if (m is not null) matched++; else uncertain++;
            }
            else if (intelligence.Route == "TAX_FINANCIAL") financial++;
            else if (intelligence.Route == "OPERATIONS") operations++;
            else if (intelligence.Route == "IGNORE") junk++;
        }

        return new(total, show, matched, financial, operations, junk, uncertain,
            $"Historical preview: {total:N0} emails found across the TS Artisans and pre-Gmail Ancient Innovations archives. {show:N0} look show-related; {matched:N0} already match an existing show/year; {uncertain:N0} show messages need a historical match review. Nothing was added to your daily Needs Review queue.");
    }

    public async Task<HistoricalEmailImportResult> ImportHistoricalAsync(CancellationToken ct = default)
    {
        await using var importScope = scopeFactory.CreateAsyncScope();
        var db = importScope.ServiceProvider.GetRequiredService<ShowArmDbContext>();
        var editions = await db.ShowEditions.Include(x => x.ShowEvent).ToListAsync(ct);
        var uploadRoot = Path.Combine(env.WebRootPath, "uploads", "brain-email", "historical");
        Directory.CreateDirectory(uploadRoot);
        var total = 0; var imported = 0; var duplicates = 0; var matchedCount = 0; var review = 0;

        foreach (var item in EnumerateHistoricalMessages())
        {
            ct.ThrowIfCancellationRequested();
            total++;
            var msg = item.Message;
            var rawId = !string.IsNullOrWhiteSpace(msg.MessageId) ? msg.MessageId : item.Hash;
            var external = $"archive:{item.Source}:{rawId}";
            if (await db.ShowEmailIntakes.AnyAsync(x => x.ExternalMessageId == external, ct)) { duplicates++; continue; }

            var body = msg.TextBody ?? msg.HtmlBody ?? string.Empty;
            var combined = $"{msg.Subject} {body}";
            var intelligence = Analyze(msg, combined);
            ShowEditionEntity? match = intelligence.Route == "SHOW" ? BestShowMatch(editions, msg.Subject ?? string.Empty, body, msg.Date.Year) : null;
            if (match is not null) matchedCount++;
            var historicalStatus = intelligence.Route == "SHOW" && match is null ? "HISTORICAL_REVIEW" : "HISTORICAL";
            if (historicalStatus == "HISTORICAL_REVIEW") review++;

            var attachmentNames = new List<string>();
            foreach (var attachment in msg.Attachments)
            {
                var fileName = SafeFileName(attachment.ContentDisposition?.FileName ?? attachment.ContentType.Name ?? "attachment");
                attachmentNames.Add(fileName);
                if (intelligence.Route == "SHOW")
                {
                    var dir = Path.Combine(uploadRoot, item.Source, (msg.Date.Year > 1900 ? msg.Date.Year : DateTime.UtcNow.Year).ToString());
                    Directory.CreateDirectory(dir);
                    var stored = Path.Combine(dir, $"{item.Hash[..12]}-{fileName}");
                    await using var fs = File.Create(stored);
                    if (attachment is MimePart part) await part.Content.DecodeToAsync(fs, ct);
                    else if (attachment is MessagePart mp) await mp.Message.WriteToAsync(fs, ct);
                    db.ShowDocuments.Add(new ShowDocumentEntity
                    {
                        ShowEditionId = match?.Id,
                        DocumentType = GuessDocumentType(fileName),
                        Title = fileName,
                        StoredPath = "/uploads/brain-email/historical/" + Path.GetRelativePath(uploadRoot, stored).Replace('\\','/'),
                        AppliesToYear = match?.Year ?? (msg.Date.Year > 1900 ? msg.Date.Year : null),
                        Notes = $"Historical email evidence from {item.Source}: {msg.Subject}"
                    });
                }
            }

            db.ShowEmailIntakes.Add(new ShowEmailIntakeEntity
            {
                ShowEditionId = match?.Id,
                ExternalMessageId = external,
                ToAddress = string.Join(", ", msg.To.Mailboxes.Select(x => x.Address)),
                FromAddress = string.Join(", ", msg.From.Mailboxes.Select(x => x.Address)),
                Subject = msg.Subject,
                BodyText = body,
                MessageDate = msg.Date.ToUniversalTime(),
                Route = intelligence.Route,
                Status = historicalStatus,
                MatchNotes = match is not null ? $"Historical evidence matched to {match.ShowEvent.Name} · {match.Year}" : $"Historical archive: {item.Source}",
                AttachmentSummary = attachmentNames.Count == 0 ? null : string.Join(", ", attachmentNames),
                MailboxAddress = item.Source,
                BrainSummary = intelligence.BrainSummary,
                ActionSummary = null,
                IsProtectedSender = intelligence.IsProtected,
                UnsubscribeUrl = intelligence.UnsubscribeUrl,
                UnsubscribeRecommended = false,
                ReceivedAt = DateTimeOffset.UtcNow
            });
            imported++;
            await db.SaveChangesAsync(ct);
            db.ChangeTracker.Clear();
        }

        return new(total, imported, duplicates, matchedCount, review,
            $"Historical intelligence import complete: {imported:N0} added, {duplicates:N0} duplicates skipped, {matchedCount:N0} show emails attached to existing show/year records, {review:N0} uncertain show matches held in Historical Match Review. Daily Email Hub queues were not flooded.");
    }

    sealed record HistoricalMessage(string Source, MimeMessage Message, string Hash);
    IEnumerable<HistoricalMessage> EnumerateHistoricalMessages()
    {
        var root = Path.Combine(env.ContentRootPath, "HistoricalEmailArchives");
        if (!Directory.Exists(root)) yield break;
        foreach (var zipPath in Directory.EnumerateFiles(root, "*.zip"))
        {
            var source = Path.GetFileNameWithoutExtension(zipPath);
            using var zip = ZipFile.OpenRead(zipPath);
            foreach (var entry in zip.Entries.Where(e => e.FullName.EndsWith(".eml", StringComparison.OrdinalIgnoreCase) && !e.FullName.StartsWith("__MACOSX/", StringComparison.OrdinalIgnoreCase) && !Path.GetFileName(e.FullName).StartsWith("._")))
            {
                using var es = entry.Open();
                using var ms = new MemoryStream();
                es.CopyTo(ms);
                var bytes = ms.ToArray();
                var hash = Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant();
                using var parse = new MemoryStream(bytes);
                MimeMessage msg;
                try { msg = MimeMessage.Load(parse); }
                catch { continue; }
                yield return new HistoricalMessage(source, msg, hash);
            }
        }
    }

    static RoutedMessage Analyze(MimeMessage msg, string combined)
    {
        var text = combined.ToLowerInvariant();
        var subject = (msg.Subject ?? string.Empty).ToLowerInvariant();
        var from = string.Join(" ", msg.From.Mailboxes.Select(x => x.Address)).ToLowerInvariant();
        var protectedSender = IsProtectedSender(from, text);
        var unsubscribe = ExtractUnsubscribeUrl(msg);
        return ClassifyCurrent(text, subject, from, protectedSender, unsubscribe);
    }

    static RoutedMessage ClassifyCurrent(string text, string subject, string from, bool protectedSender, string? unsubscribe)
    {
        var action = FindActionSignal(text);
        var problem = FindProblemSignal(text);
        var humanRequest = LooksLikeHumanRequest(text, subject, from);
        var noReply = IsNoReplySender(from);

        var newsletterSignals = new[] { "unsubscribe", "view in browser", "manage preferences", "email preferences", "special offer", "limited time offer", "daily digest", "newsletter", "shop now", "sale ends", "promo code" }
            .Count(text.Contains);
        var routineSystem = new[] { "security alert", "verification code", "sign-in", "login code", "delivery update", "delivered", "password reset", "launch code", "one-time code", "two-step verification", "2-step verification" }.Any(text.Contains);
        var showSignals = new[] { "festival", "vendor", "booth", "craft show", "art fair", "arts fair", "application", "promoter", "exhibitor", "vendor packet", "jury", "load in", "load-in", "show map", "booth assignment", "vendor instructions", "setup instructions" }.Any(text.Contains);
        var financialSignals = new[] { "receipt", "invoice", "expense", "1099", "tax", "payment receipt", "statement", "transaction", "charge receipt", "paid invoice" }.Any(text.Contains);
        var storeSignals = new[] { "wholesale", "consignment", "commission store", "stockist", "retail partner", "retailer inquiry", "carry your products" }.Any(text.Contains);
        var operationsSignals = new[] { "order", "shipping", "shipment", "tracking", "fulfillment", "inventory", "production", "purchase order", "supplier", "backorder", "delivery status" }.Any(text.Contains)
            || from.Contains("squareup") || from.Contains("woocommerce") || from.Contains("pirateship") || from.Contains("shipstation");
        var marketingSignals = new[] { "marketing", "advertising", "social media", "campaign", "sponsorship", "promote your", "grow your business", "seo", "boost your", "advertise with" }.Any(text.Contains);
        var customerSignals = problem is not null
            || new[] { "custom artwork", "personalization", "personalisation", "proof", "where is my order", "order question", "can you make", "could you make", "custom order", "name on", "engrave", "replacement", "return request" }.Any(text.Contains);

        string route;
        if (routineSystem && (protectedSender || noReply)) route = "SYSTEM_FYI";
        else if (showSignals) route = "SHOW";
        else if (financialSignals) route = "TAX_FINANCIAL";
        else if (storeSignals) route = "STORE";
        else if (customerSignals) route = "CUSTOMER";
        else if (operationsSignals) route = "OPERATIONS";
        else if (marketingSignals) route = "MARKETING";
        else if (newsletterSignals >= 1 && !protectedSender && !humanRequest) route = "IGNORE";
        else route = "UNKNOWN";

        // The Brain's operating rule: information is not a task.  Only clear decisions,
        // exceptions, or unresolved human requests interrupt Jaime.  Everything remains
        // preserved and auditable in Email Hub even when marked Brain Handled.
        var status = route switch
        {
            "IGNORE" => "IGNORED",
            "SYSTEM_FYI" => "BRAIN_HANDLED",
            "SHOW" when action is not null => "NEEDS_YOU",
            "CUSTOMER" when problem is not null || humanRequest => "NEEDS_YOU",
            "STORE" when action is not null || humanRequest => "NEEDS_YOU",
            "UNKNOWN" when action is not null || humanRequest => "NEEDS_REVIEW",
            _ when problem is not null || action is not null => "NEEDS_YOU",
            _ => "BRAIN_HANDLED"
        };

        var summary = route switch
        {
            "SHOW" => "Show Arm: show-related information; attach to the show/year when identity is confident.",
            "TAX_FINANCIAL" => "Tax & Financial Records: receipt, invoice, expense, payment, statement, or tax-related evidence.",
            "MARKETING" => "Marketing: promotional/advertising information preserved without creating a decision task.",
            "STORE" => "Commission-store / wholesale communication; surfaced only when a response or decision is evident.",
            "OPERATIONS" => "Operations: order, shipping, purchasing, supplier, inventory, or fulfillment information.",
            "IGNORE" => "Low-value recurring promotional/newsletter email; kept out of your decision queue.",
            "SYSTEM_FYI" => "Routine system/security/delivery notice; preserved without creating work.",
            "CUSTOMER" => "Customer communication; surfaced only when a request, problem, approval, or judgment is evident.",
            _ when status == "BRAIN_HANDLED" => "Unclassified informational email preserved by the Brain; no decision signal detected.",
            _ => "The Brain detected a human request/action signal but could not confidently choose a destination."
        };

        string? actionSummary = null;
        if (action is not null) actionSummary = $"Decision/action signal: ‘{action}’.";
        else if (problem is not null) actionSummary = $"Customer/operations exception: ‘{problem}’.";
        else if (status == "NEEDS_YOU" && route == "CUSTOMER") actionSummary = "Customer appears to be asking for a response or judgment.";
        else if (status == "NEEDS_YOU" && route == "STORE") actionSummary = "Wholesale / commission-store contact appears to need a response.";
        else if (status == "NEEDS_REVIEW") actionSummary = "A human request was detected, but the Brain is not yet confident where it belongs.";

        var recommendUnsubscribe = route == "IGNORE" && !protectedSender && !string.IsNullOrWhiteSpace(unsubscribe);
        return new(route, status, summary, actionSummary, protectedSender, unsubscribe, recommendUnsubscribe);
    }

    static string? FindActionSignal(string value)
    {
        var text = value.ToLowerInvariant();
        return new[] { "action required", "response required", "please respond", "please confirm", "confirm by", "due by", "deadline", "payment due", "past due", "expires", "needs approval", "approval required", "reply by", "respond by", "signature required", "please sign", "complete by" }.FirstOrDefault(text.Contains);
    }

    static string? FindProblemSignal(string value)
    {
        var text = value.ToLowerInvariant();
        return new[] { "problem with", "issue with", "damaged", "wrong item", "missing item", "refund", "cancel order", "chargeback", "dispute", "failed payment", "not received", "never arrived", "replacement needed" }.FirstOrDefault(text.Contains);
    }

    static bool IsNoReplySender(string from)
        => new[] { "no-reply", "noreply", "donotreply", "do-not-reply", "mailer-daemon", "notifications@", "notification@", "automated@" }.Any(from.Contains);

    static bool LooksLikeHumanRequest(string text, string subject, string from)
    {
        if (IsNoReplySender(from)) return false;
        var requestTerms = new[] { "can you", "could you", "would you", "will you", "please let me know", "let me know if", "please send", "please reply", "please respond", "i need", "we need", "i would like", "i'd like", "interested in", "are you able", "do you have", "is it possible", "what is", "when can", "how much", "availability", "question for you" };
        if (requestTerms.Any(text.Contains)) return true;
        // A question in a short/non-newsletter subject is useful evidence of a human request.
        return subject.Contains('?') && !text.Contains("unsubscribe");
    }

    static bool LooksLikeShowLogistics(string value)
    {
        var text = value.ToLowerInvariant();
        return new[] { "map", "pdf", "instructions", "load in", "load-in", "setup", "set up", "parking", "booth", "vendor", "exhibitor", "festival", "fair", "market", "show", "application", "jury", "acceptance", "accepted", "waitlist", "registration", "register", "packet", "check-in", "check in", "electric", "camping", "site map" }.Any(text.Contains);
    }

    static bool IsProtectedSender(string from, string text)
    {
        var protectedDomainSignals = new[]
        {
            "squareup", "woocommerce", "stripe", "paypal", "pirateship", "usps", "ups.com", "fedex",
            "google.com", "microsoft.com", "amazon.com", "bank", "credit", "insurance", "irs.gov", ".gov"
        };
        if (protectedDomainSignals.Any(x => from.Contains(x))) return true;

        var protectedBusinessSignals = new[]
        {
            "order", "receipt", "invoice", "shipment", "tracking", "vendor", "festival", "application", "booth",
            "customer", "payment", "security alert", "verification", "tax", "1099", "utility"
        };
        return protectedBusinessSignals.Any(text.Contains);
    }

    static string? ExtractUnsubscribeUrl(MimeMessage msg)
    {
        var raw = HeaderValue(msg, "List-Unsubscribe");
        if (string.IsNullOrWhiteSpace(raw)) return null;
        var start = raw.IndexOf('<');
        var end = raw.IndexOf('>');
        var candidate = start >= 0 && end > start ? raw[(start + 1)..end] : raw.Split(',')[0].Trim();
        return candidate.StartsWith("http", StringComparison.OrdinalIgnoreCase) ? candidate : null;
    }

    static string? HeaderValue(MimeMessage msg, string name)
        => msg.Headers.FirstOrDefault(x => x.Field.Equals(name, StringComparison.OrdinalIgnoreCase))?.Value;

    static async Task<CurrentEmailReprocessResult> ReclassifyStoredAsync(ShowArmDbContext db, CancellationToken ct)
    {
        // Re-score current business mail after every inbox check so routing improvements
        // apply to already-imported messages. Historical intelligence remains frozen.
        var editions = await db.ShowEditions.Include(x => x.ShowEvent).ToListAsync(ct);
        var items = await db.ShowEmailIntakes
            .Where(x => x.Status != "HISTORICAL" && x.Status != "HISTORICAL_REVIEW")
            .OrderByDescending(x => x.MessageDate ?? x.ReceivedAt)
            .Take(1000)
            .ToListAsync(ct);

        var changed = 0;
        foreach (var item in items)
        {
            var beforeRoute = item.Route;
            var beforeStatus = item.Status;
            var beforeShowEditionId = item.ShowEditionId;
            var beforeBrainSummary = item.BrainSummary;
            var beforeActionSummary = item.ActionSummary;
            var beforeUnsubscribe = item.UnsubscribeRecommended;

            var text = $"{item.Subject} {item.BodyText}".ToLowerInvariant();
            var subject = (item.Subject ?? string.Empty).ToLowerInvariant();
            var from = (item.FromAddress ?? string.Empty).ToLowerInvariant();
            var protectedSender = IsProtectedSender(from, text);
            var decision = ClassifyCurrent(text, subject, from, protectedSender, item.UnsubscribeUrl);

            var year = item.MessageDate?.Year ?? item.ReceivedAt.Year;
            var match = BestShowMatch(editions, item.Subject ?? string.Empty, item.BodyText ?? string.Empty, year)
                ?? BestCurrentShowHintMatch(editions, item.Subject ?? string.Empty, item.BodyText ?? string.Empty, year);
            if (match is not null && (decision.Route is "UNKNOWN" or "OPERATIONS") && LooksLikeShowLogistics(text))
            {
                var actionSignal = FindActionSignal(text);
                decision = decision with
                {
                    Route = "SHOW",
                    Status = actionSignal is null ? "BRAIN_HANDLED" : "NEEDS_YOU",
                    BrainSummary = $"Show Arm: recognized as {match.ShowEvent.Name} · {match.Year} from show identity/logistics evidence.",
                    ActionSummary = actionSignal is null ? null : $"Decision/action signal: ‘{actionSignal}’."
                };
            }
            if (decision.Route != "SHOW") match = null;

            item.Route = decision.Route;
            item.Status = decision.Route == "SHOW" && match is null && decision.Status != "IGNORED"
                ? (FindActionSignal(text) is null ? "BRAIN_HANDLED" : "NEEDS_REVIEW")
                : decision.Status;
            if (decision.Route == "SHOW" && item.ShowEditionId is null && match is not null)
                item.ShowEditionId = match.Id;
            item.IsProtectedSender = decision.IsProtected;
            item.BrainSummary = decision.BrainSummary;
            item.ActionSummary = decision.ActionSummary;
            item.UnsubscribeRecommended = decision.UnsubscribeRecommended;
            if (!string.IsNullOrWhiteSpace(decision.UnsubscribeUrl)) item.UnsubscribeUrl = decision.UnsubscribeUrl;
            item.MatchNotes = match is not null
                ? $"Pass 9.10 triage matched to {match.ShowEvent.Name} · {match.Year}."
                : decision.Route == "SHOW"
                    ? "Pass 9.10 triage: show-related information; no confident show/year match yet."
                    : item.MatchNotes;

            if (beforeRoute != item.Route || beforeStatus != item.Status || beforeShowEditionId != item.ShowEditionId
                || beforeBrainSummary != item.BrainSummary || beforeActionSummary != item.ActionSummary
                || beforeUnsubscribe != item.UnsubscribeRecommended)
                changed++;
        }

        if (items.Count > 0) await db.SaveChangesAsync(ct);

        var needsYou = items.Count(x => x.Status == "NEEDS_YOU");
        var needsReview = items.Count(x => x.Status == "NEEDS_REVIEW");
        var handled = items.Count(x => x.Status == "BRAIN_HANDLED");
        var ignored = items.Count(x => x.Status == "IGNORED");
        return new CurrentEmailReprocessResult(
            items.Count, changed, needsYou, needsReview, handled, ignored,
            $"Reprocessed {items.Count} stored current email(s): {changed} classification(s) changed. " +
            $"Now: {needsYou} Need You, {needsReview} Needs Review, {handled} Brain Handled, {ignored} Ignored. Historical email was not touched.");
    }

    static ShowEditionEntity? BestCurrentShowHintMatch(List<ShowEditionEntity> editions, string subject, string body, int messageYear)
    {
        if (!LooksLikeShowLogistics($"{subject} {body}")) return null;
        var normalizedSubject = NormalizeMatchText(subject);
        var eventScores = editions.GroupBy(e => e.ShowEventId).Select(g =>
        {
            var ev = g.First().ShowEvent;
            var words = NormalizeMatchText(ev.Name).Split(' ', StringSplitOptions.RemoveEmptyEntries)
                .Where(x => x.Length >= 5 && x is not "festival" and not "market" and not "craft" and not "show" and not "fair" and not "annual" and not "event" and not "arts")
                .Distinct().ToList();
            var hits = words.Count(normalizedSubject.Contains);
            var score = hits * 12;
            if (hits > 0 && !string.IsNullOrWhiteSpace(ev.City) && normalizedSubject.Contains(NormalizeMatchText(ev.City).Trim())) score += 3;
            return new { Editions = g.ToList(), Score = score };
        }).Where(x => x.Score >= 12).OrderByDescending(x => x.Score).ToList();

        if (eventScores.Count == 0) return null;
        if (eventScores.Count > 1 && eventScores[0].Score - eventScores[1].Score < 6) return null;
        var chosen = eventScores[0].Editions;
        var targetYear = ExtractYear(subject) ?? ExtractYear(body) ?? messageYear;
        return chosen.FirstOrDefault(e => e.Year == targetYear) ?? (chosen.Count == 1 ? chosen[0] : null);
    }

    static ShowEditionEntity? BestShowMatch(List<ShowEditionEntity> editions, string subject, string body, int messageYear)
    {
        var normalizedSubject = NormalizeMatchText(subject);
        var normalizedCombined = NormalizeMatchText($"{subject} {body}");
        var subjectNamesShow = SubjectLooksLikeNamedShow(subject);

        // Identify the SHOW EVENT first.  If the subject names a show, body-only mentions
        // of other events (newsletters, recommendation lists, etc.) cannot steal identity.
        var eventScores = editions.GroupBy(e => e.ShowEventId).Select(g => new
        {
            Editions = g.ToList(),
            Score = ShowIdentityScore(g.First().ShowEvent, normalizedSubject, normalizedCombined, subjectNamesShow)
        }).Where(x => x.Score > 0).OrderByDescending(x => x.Score).ToList();

        if (eventScores.Count == 0 || eventScores[0].Score < 14) return null;
        if (eventScores.Count > 1 && eventScores[0].Score - eventScores[1].Score < 5) return null;

        var chosen = eventScores[0].Editions;
        // Only now use year to choose the edition of the already-identified event.
        var explicitYear = ExtractYear(subject) ?? ExtractYear(body);
        var targetYear = explicitYear ?? messageYear;
        return chosen.FirstOrDefault(e => e.Year == targetYear)
            ?? (chosen.Count == 1 ? chosen[0] : null);
    }

    static int ShowIdentityScore(ShowEventEntity show, string normalizedSubject, string normalizedCombined, bool subjectNamesShow)
    {
        var name = NormalizeMatchText(show.Name);
        if (string.IsNullOrWhiteSpace(name)) return 0;
        if (normalizedSubject.Contains(name)) return 34;

        var meaningful = name.Split(' ', StringSplitOptions.RemoveEmptyEntries)
            .Where(x => x.Length >= 4 && x is not "festival" and not "market" and not "craft" and not "show" and not "fair" and not "arts" and not "art" and not "annual" and not "event")
            .Distinct().ToList();
        var subjectHits = meaningful.Count(normalizedSubject.Contains);
        var bodyHits = meaningful.Count(normalizedCombined.Contains);
        var score = subjectHits >= 2 ? 22 + Math.Min(subjectHits, 4)
            : (subjectHits == 1 && meaningful.Count == 1 ? 18 : subjectHits == 1 ? 9 : 0);

        // Subject-named show wins identity. Body-only show names are considered only when
        // the subject is generic (for example "Vendor Application" or "Acceptance").
        if (!subjectNamesShow && score == 0)
        {
            if (normalizedCombined.Contains(name)) score = 26;
            else if (bodyHits >= 2) score = 12 + Math.Min(bodyHits, 3);
            else if (bodyHits == 1 && meaningful.Count == 1) score = 10;
        }

        if (score >= 7 && !string.IsNullOrWhiteSpace(show.PromoterName) && normalizedCombined.Contains(NormalizeMatchText(show.PromoterName))) score += 5;
        if (score >= 7 && !string.IsNullOrWhiteSpace(show.City) && normalizedCombined.Contains(NormalizeMatchText(show.City))) score += 3;
        return score;
    }

    static bool SubjectLooksLikeNamedShow(string? subject)
    {
        if (string.IsNullOrWhiteSpace(subject)) return false;
        var normalized = NormalizeMatchText(subject);
        var stop = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            "festival","market","craft","show","fair","arts","art","annual","event","vendor","application","registration","reminder","register","information","info","update","updates","weekend","accepted","acceptance","invoice","paid","payment","pre","post","fwd","fw","re"
        };
        return normalized.Split(' ', StringSplitOptions.RemoveEmptyEntries)
            .Any(x => x.Length >= 4 && !stop.Contains(x) && !System.Text.RegularExpressions.Regex.IsMatch(x, @"^20\d{2}$"));
    }

    static int? ExtractYear(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var m = System.Text.RegularExpressions.Regex.Match(value, @"\b(20\d{2})\b");
        return m.Success && int.TryParse(m.Groups[1].Value, out var year) ? year : null;
    }

    static string NormalizeMatchText(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return " ";
        var chars = value.ToLowerInvariant().Select(c => char.IsLetterOrDigit(c) ? c : ' ').ToArray();
        return " " + string.Join(' ', new string(chars).Split(' ', StringSplitOptions.RemoveEmptyEntries)) + " ";
    }

    static string SafeFileName(string s) => string.Concat(Path.GetFileName(s).Select(c => Path.GetInvalidFileNameChars().Contains(c) ? '_' : c));
    static string GuessDocumentType(string f)
    {
        var x = f.ToLowerInvariant();
        return x.Contains("map") ? "MAP" : x.Contains("contract") ? "CONTRACT" : x.Contains("packet") ? "VENDOR_PACKET" : "EMAIL_ATTACHMENT";
    }
}
