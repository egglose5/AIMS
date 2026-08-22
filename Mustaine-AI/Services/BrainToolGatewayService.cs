using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record BrainToolDescriptor(
    string ToolKey,
    string DisplayName,
    string AgentKey,
    string CapabilityKey,
    string TargetArm,
    bool Consequential,
    string Boundary,
    bool IsDiagnostic = false);

public sealed record BrainToolRequest(
    string AgentKey,
    string ToolKey,
    string? PayloadJson = null,
    string? CorrelationId = null,
    long? ApprovedRequestId = null);

public sealed record BrainToolResult(
    bool Succeeded,
    string State,
    string Message,
    string? ResultJson,
    long ExecutionId,
    long? ApprovalRequestId = null);

public sealed record BrainGatewayDashboard(
    int RegisteredTools,
    int Executions,
    int DeniedExecutions,
    int PendingApprovals);

public interface IBrainToolGatewayService
{
    IReadOnlyList<BrainToolDescriptor> GetRegisteredTools();
    Task<BrainGatewayDashboard> GetDashboardAsync(CancellationToken ct = default);
    Task<IReadOnlyList<BrainToolExecutionEntity>> GetRecentExecutionsAsync(int take = 30, CancellationToken ct = default);
    Task<IReadOnlyList<BrainApprovalRequestEntity>> GetPendingApprovalsAsync(int take = 30, CancellationToken ct = default);
    Task<BrainToolResult> ExecuteAsync(BrainToolRequest request, CancellationToken ct = default);
    Task ReviewApprovalAsync(long approvalId, bool approve, string reviewedBy, string? reason = null, CancellationToken ct = default);
    Task<IReadOnlyList<BrainToolResult>> RunSafetySelfTestAsync(CancellationToken ct = default);
}

/// <summary>
/// B3 controlled gateway. Agent runtimes receive only this contract, never ApplicationDbContext,
/// ShowArmDbContext, connection strings, or raw business services. Every request is authorized and audited first.
/// </summary>
public sealed class BrainToolGatewayService(
    ApplicationDbContext db,
    IBrainCoreService core,
    IBrainMemoryService memory) : IBrainToolGatewayService
{
    private static readonly BrainToolDescriptor[] Tools =
    [
        new("scout.controlled-context", "Scout controlled context", "SCOUT", "SHOW.RESEARCH.READ_CONTROLLED_SLICE", "SHOW_ARM", false,
            "Returns only a deliberately small Brain-owned context snapshot. No raw database access."),
        new("show-brain.memory-context", "Show Brain memory context", "SHOW_BRAIN", "SHOW.INTELLIGENCE.READ", "SHOW_ARM", false,
            "Reads durable SHOW_ARM Brain memory through a governed service, never through direct SQL."),
        new("show-brain.show-arm-context", "Show Brain operational evidence", "SHOW_BRAIN", "SHOW.INTELLIGENCE.READ", "SHOW_ARM", false,
            "Returns a bounded read-only snapshot of real Show Arm operational evidence. No raw database credentials or write authority."),
        new("show-brain.propose-learning", "Propose Show Brain lesson", "SHOW_BRAIN", "SHOW.LEARNING.PROPOSE", "SHOW_ARM", false,
            "Writes only to the staged learning review queue; it cannot create durable lessons directly."),
        new("blocked.application-submit", "Application submission boundary test", "SCOUT", "SHOW.APPLICATION.SUBMIT", "SHOW_ARM", true,
            "Diagnostic denied tool. Handler must never execute because governance denies the capability.", true),
        new("blocked.money-spend", "Money-spend boundary test", "SHOW_BRAIN", "SHOW.MONEY.SPEND", "SHOW_ARM", true,
            "Diagnostic denied tool. Handler must never execute because governance denies the capability.", true)
    ];

    public IReadOnlyList<BrainToolDescriptor> GetRegisteredTools() => Tools;

    public async Task<BrainGatewayDashboard> GetDashboardAsync(CancellationToken ct = default)
    {
        var executions = await db.BrainToolExecutions.CountAsync(ct);
        var denied = await db.BrainToolExecutions.CountAsync(x => x.State == "DENIED", ct);
        var pending = await db.BrainApprovalRequests.CountAsync(x => x.Status == "PENDING", ct);
        return new(Tools.Length, executions, denied, pending);
    }

    public async Task<IReadOnlyList<BrainToolExecutionEntity>> GetRecentExecutionsAsync(int take = 30, CancellationToken ct = default)
        => await db.BrainToolExecutions.AsNoTracking().OrderByDescending(x => x.RequestedAt).Take(Math.Clamp(take, 1, 200)).ToListAsync(ct);

    public async Task<IReadOnlyList<BrainApprovalRequestEntity>> GetPendingApprovalsAsync(int take = 30, CancellationToken ct = default)
        => await db.BrainApprovalRequests.AsNoTracking().Where(x => x.Status == "PENDING").OrderByDescending(x => x.RequestedAt).Take(Math.Clamp(take, 1, 200)).ToListAsync(ct);

    public async Task<BrainToolResult> ExecuteAsync(BrainToolRequest request, CancellationToken ct = default)
    {
        var agentKey = Clean(request.AgentKey);
        var toolKey = request.ToolKey?.Trim() ?? string.Empty;
        var descriptor = Tools.FirstOrDefault(x => string.Equals(x.ToolKey, toolKey, StringComparison.OrdinalIgnoreCase));

        if (descriptor is null)
        {
            return await DenyUnknownToolAsync(agentKey, toolKey, request, ct);
        }

        if (!string.Equals(descriptor.AgentKey, agentKey, StringComparison.OrdinalIgnoreCase))
        {
            return await DenyDescriptorMismatchAsync(agentKey, descriptor, request, ct);
        }

        var execution = new BrainToolExecutionEntity
        {
            AgentKey = agentKey,
            ToolKey = descriptor.ToolKey,
            CapabilityKey = descriptor.CapabilityKey,
            TargetArm = descriptor.TargetArm,
            Consequential = descriptor.Consequential,
            InputSummary = Summarize(request.PayloadJson),
            CorrelationId = request.CorrelationId,
            State = "REQUESTED"
        };
        db.BrainToolExecutions.Add(execution);
        await db.SaveChangesAsync(ct);

        var auth = await core.AuthorizeAsync(agentKey, descriptor.CapabilityKey, false, ct);
        if (!auth.Allowed)
        {
            // Base authorization failed: this is an absolute deny/no-grant condition.
            // Human approval never overrides a DENY grant.
            execution.State = "DENIED";
            execution.DenialReason = auth.Reason;
            execution.CompletedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            await core.RecordAuditAsync(agentKey, "TOOL_DENIED", descriptor.TargetArm, descriptor.ToolKey, "DENIED", auth.Reason, execution.ExecutionKey, ct);
            return new(false, "DENIED", auth.Reason, null, execution.Id);
        }

        // If a future capability is approval-gated but the caller has a reviewed approval, require that exact execution linkage.
        if (auth.RequiresHumanApproval && descriptor.Consequential)
        {
            var approval = request.ApprovedRequestId.HasValue
                ? await db.BrainApprovalRequests.AsNoTracking().FirstOrDefaultAsync(x => x.Id == request.ApprovedRequestId.Value && x.Status == "APPROVED" && x.AgentKey == agentKey && x.ToolKey == descriptor.ToolKey, ct)
                : null;
            if (approval is null)
            {
                execution.State = "APPROVAL_REQUIRED";
                execution.DenialReason = "A valid approved human-gate request is required.";
                var pending = new BrainApprovalRequestEntity
                {
                    BrainToolExecutionId = execution.Id,
                    AgentKey = agentKey,
                    ToolKey = descriptor.ToolKey,
                    CapabilityKey = descriptor.CapabilityKey,
                    TargetArm = descriptor.TargetArm,
                    RequestReason = auth.Reason
                };
                db.BrainApprovalRequests.Add(pending);
                await db.SaveChangesAsync(ct);
                await core.RecordAuditAsync(agentKey, "TOOL_APPROVAL_REQUIRED", descriptor.TargetArm, descriptor.ToolKey, "PENDING", pending.RequestReason, execution.ExecutionKey, ct);
                return new(false, execution.State, execution.DenialReason, null, execution.Id, pending.Id);
            }
        }

        try
        {
            var resultJson = await InvokeHandlerAsync(descriptor, request.PayloadJson, ct);
            execution.State = "COMPLETED";
            execution.OutputSummary = Summarize(resultJson);
            execution.CompletedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            await core.RecordAuditAsync(agentKey, "TOOL_EXECUTED", descriptor.TargetArm, descriptor.ToolKey, "COMPLETED", descriptor.Boundary, execution.ExecutionKey, ct);
            return new(true, "COMPLETED", $"{descriptor.DisplayName} completed through the controlled gateway.", resultJson, execution.Id);
        }
        catch (Exception ex)
        {
            execution.State = "FAILED";
            execution.OutputSummary = ex.Message;
            execution.CompletedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            await core.RecordAuditAsync(agentKey, "TOOL_FAILED", descriptor.TargetArm, descriptor.ToolKey, "FAILED", ex.Message, execution.ExecutionKey, ct);
            return new(false, "FAILED", ex.Message, null, execution.Id);
        }
    }

    public async Task ReviewApprovalAsync(long approvalId, bool approve, string reviewedBy, string? reason = null, CancellationToken ct = default)
    {
        var row = await db.BrainApprovalRequests.FirstOrDefaultAsync(x => x.Id == approvalId, ct)
            ?? throw new InvalidOperationException("Approval request not found.");
        if (row.Status != "PENDING") throw new InvalidOperationException("Approval request has already been reviewed.");
        row.Status = approve ? "APPROVED" : "REJECTED";
        row.ReviewedBy = reviewedBy;
        row.ReviewReason = reason;
        row.ReviewedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync(reviewedBy, "TOOL_APPROVAL_REVIEWED", row.TargetArm, row.ToolKey, row.Status, reason, row.ApprovalKey, ct);
    }

    public async Task<IReadOnlyList<BrainToolResult>> RunSafetySelfTestAsync(CancellationToken ct = default)
    {
        // 1) allowed Scout read; 2) allowed Show Brain read; 3) denied application action; 4) denied money action.
        // No test changes canonical business data or creates durable learning.
        var correlation = $"B3-SELFTEST-{DateTimeOffset.UtcNow:yyyyMMddHHmmss}";
        var results = new List<BrainToolResult>
        {
            await ExecuteAsync(new("SCOUT", "scout.controlled-context", null, correlation), ct),
            await ExecuteAsync(new("SHOW_BRAIN", "show-brain.memory-context", null, correlation), ct),
            await ExecuteAsync(new("SCOUT", "blocked.application-submit", "{\"test\":true}", correlation), ct),
            await ExecuteAsync(new("SHOW_BRAIN", "blocked.money-spend", "{\"test\":true}", correlation), ct)
        };
        return results;
    }

    private async Task<string> InvokeHandlerAsync(BrainToolDescriptor descriptor, string? payloadJson, CancellationToken ct)
    {
        switch (descriptor.ToolKey)
        {
            case "scout.controlled-context":
            {
                var memories = await db.BrainMemoryItems.AsNoTracking()
                    .Where(x => x.ArmScope == "SHOW_ARM" && x.Status == "ACTIVE")
                    .OrderByDescending(x => x.UpdatedAt)
                    .Take(12)
                    .Select(x => new { x.MemoryType, x.Title, x.Content, x.Confidence, x.SourceType })
                    .ToListAsync(ct);
                return JsonSerializer.Serialize(new
                {
                    contract = "SCOUT_CONTROLLED_SLICE_V1",
                    sourceOfTruth = "Control App / Show Arm",
                    directDatabaseAccess = false,
                    durableShowMemory = memories
                });
            }
            case "show-brain.memory-context":
            {
                var memories = await db.BrainMemoryItems.AsNoTracking()
                    .Where(x => x.ArmScope == "SHOW_ARM" && x.Status == "ACTIVE")
                    .OrderByDescending(x => x.UpdatedAt)
                    .Take(25)
                    .Select(x => new { x.MemoryType, x.Title, x.Content, x.Confidence, x.SourceType, x.SourceRef })
                    .ToListAsync(ct);
                return JsonSerializer.Serialize(new { contract = "SHOW_BRAIN_MEMORY_CONTEXT_V1", memories });
            }
            case "show-brain.show-arm-context":
            {
                static Dictionary<string, object?> ScalarRow(object row)
                {
                    var d = new Dictionary<string, object?>();
                    foreach (var p in row.GetType().GetProperties())
                    {
                        var t = Nullable.GetUnderlyingType(p.PropertyType) ?? p.PropertyType;
                        if (!(t.IsPrimitive || t.IsEnum || t == typeof(string) || t == typeof(decimal) || t == typeof(DateTime) || t == typeof(DateTimeOffset) || t == typeof(Guid))) continue;
                        try
                        {
                            var value = p.GetValue(row);
                            if (value is string text && text.Length > 700) value = text[..700] + "…";
                            d[p.Name] = value;
                        }
                        catch { }
                    }
                    return d;
                }

                var requestText = string.Empty;
                string? requestedSubjectType = null;
                string? requestedSubjectKey = null;
                if (!string.IsNullOrWhiteSpace(payloadJson))
                {
                    try
                    {
                        using var requestDoc = JsonDocument.Parse(payloadJson);
                        var requestRoot = requestDoc.RootElement;
                        requestText = requestRoot.TryGetProperty("question", out var q) ? q.GetString() ?? string.Empty : string.Empty;
                        requestedSubjectType = requestRoot.TryGetProperty("subjectType", out var st) ? st.GetString() : null;
                        requestedSubjectKey = requestRoot.TryGetProperty("subjectKey", out var sk) ? sk.GetString() : null;
                    }
                    catch { }
                }

                static string[] Terms(string text) => text
                    .Split(new[] { ' ', '\t', '\r', '\n', ',', '.', ':', ';', '/', '-', '(', ')', '?', '!' }, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
                    .Where(x => x.Length >= 4)
                    .Select(x => x.ToLowerInvariant())
                    .Distinct()
                    .Take(24)
                    .ToArray();

                var terms = Terms($"{requestText} {requestedSubjectType} {requestedSubjectKey}");
                async Task<object> SliceAsync<T>(IQueryable<T> query, int take, int scan = 160) where T : class
                {
                    var rows = await query.AsNoTracking().Take(scan).ToListAsync(ct);
                    var shaped = rows.Select(x => ScalarRow(x!)).ToList();
                    if (terms.Length == 0) return shaped.Take(take).ToList();
                    return shaped
                        .Select((row, index) => new { row, index, text = string.Join(" ", row.Values.Where(v => v is not null).Select(v => v!.ToString())).ToLowerInvariant() })
                        .Select(x => new { x.row, x.index, score = terms.Count(t => x.text.Contains(t, StringComparison.OrdinalIgnoreCase)) })
                        .OrderByDescending(x => x.score)
                        .ThenBy(x => x.index)
                        .Take(take)
                        .Select(x => x.row)
                        .ToList();
                }

                var payload = new Dictionary<string, object?>
                {
                    ["contract"] = "SHOW_BRAIN_OPERATIONAL_CONTEXT_V3_B8_TARGETED",
                    ["retrieval"] = new { mode = terms.Length == 0 ? "BOUNDED_RECENT" : "QUESTION_TARGETED", termCount = terms.Length, subjectType = requestedSubjectType, subjectKey = requestedSubjectKey },
                    ["evidenceRule"] = "Calibrations/notes/email/imported statements remain evidence; they are not canonical ShowResults unless explicitly stored as ShowResults.",
                    ["sourceOfTruth"] = "Control App / Show Arm",
                    ["readOnly"] = true,
                    ["directDatabaseAccessForModel"] = false,
                    ["counts"] = new
                    {
                        shows = await db.ShowEvents.CountAsync(ct),
                        editions = await db.ShowEditions.CountAsync(ct),
                        results = await db.ShowResults.CountAsync(ct),
                        forecasts = await db.ShowForecasts.CountAsync(ct),
                        assignments = await db.ShowAssignments.CountAsync(ct),
                        applications = await db.ShowApplications.CountAsync(ct),
                        evidence = await db.ShowResearchEvidence.CountAsync(ct),
                        notes = await db.ShowNotes.CountAsync(ct),
                        emailIntake = await db.ShowEmailIntakes.CountAsync(ct),
                        calibrations = await db.ShowCalibrationRecords.CountAsync(ct),
                        discoveryLeads = await db.ShowDiscoveryLeads.CountAsync(ct)
                    },
                    ["shows"] = await SliceAsync(db.ShowEvents, 12),
                    ["editions"] = await SliceAsync(db.ShowEditions, 12),
                    ["results"] = await SliceAsync(db.ShowResults, 18),
                    ["forecasts"] = await SliceAsync(db.ShowForecasts, 10),
                    ["assignments"] = await SliceAsync(db.ShowAssignments, 10),
                    ["applications"] = await SliceAsync(db.ShowApplications, 10),
                    ["researchEvidence"] = await SliceAsync(db.ShowResearchEvidence, 14),
                    ["notes"] = await SliceAsync(db.ShowNotes, 10),
                    ["showEmailIntake"] = await SliceAsync(db.ShowEmailIntakes, 10),
                    ["calibrations"] = await SliceAsync(db.ShowCalibrationRecords, 16),
                    ["discoveryLeads"] = await SliceAsync(db.ShowDiscoveryLeads, 10)
                };
                return JsonSerializer.Serialize(payload);
            }
            case "show-brain.propose-learning":
            {
                if (string.IsNullOrWhiteSpace(payloadJson)) throw new ArgumentException("Learning payload is required.");
                using var doc = JsonDocument.Parse(payloadJson);
                var root = doc.RootElement;
                var lesson = root.TryGetProperty("lesson", out var lessonEl) ? lessonEl.GetString() : null;
                var reasoning = root.TryGetProperty("reasoning", out var reasonEl) ? reasonEl.GetString() : null;
                var confidence = root.TryGetProperty("confidence", out var confEl) && confEl.TryGetDecimal(out var c) ? c : .5m;
                if (string.IsNullOrWhiteSpace(lesson)) throw new ArgumentException("Learning payload needs a lesson.");
                var row = await memory.ProposeLearningAsync("SHOW_BRAIN", "SHOW_ARM", lesson, reasoning, confidence, ct: ct);
                return JsonSerializer.Serialize(new { row.LearningKey, row.Status, row.ProposedLesson, row.Confidence });
            }
            case "blocked.application-submit":
            case "blocked.money-spend":
                throw new InvalidOperationException("SECURITY FAILURE: a denied diagnostic handler was reached. Governance should have blocked this before invocation.");
            default:
                throw new InvalidOperationException("No handler is registered for this Brain tool.");
        }
    }

    private async Task<BrainToolResult> DenyUnknownToolAsync(string agentKey, string toolKey, BrainToolRequest request, CancellationToken ct)
    {
        var execution = new BrainToolExecutionEntity
        {
            AgentKey = agentKey,
            ToolKey = string.IsNullOrWhiteSpace(toolKey) ? "(blank)" : toolKey,
            CapabilityKey = "UNREGISTERED",
            TargetArm = "BRAIN_CORE",
            State = "DENIED",
            DenialReason = "Tool is not registered in the Ancient Innovations Brain gateway.",
            InputSummary = Summarize(request.PayloadJson),
            CorrelationId = request.CorrelationId,
            CompletedAt = DateTimeOffset.UtcNow
        };
        db.BrainToolExecutions.Add(execution);
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync(agentKey, "TOOL_DENIED", "BRAIN_CORE", execution.ToolKey, "DENIED", execution.DenialReason, execution.ExecutionKey, ct);
        return new(false, "DENIED", execution.DenialReason, null, execution.Id);
    }

    private async Task<BrainToolResult> DenyDescriptorMismatchAsync(string agentKey, BrainToolDescriptor descriptor, BrainToolRequest request, CancellationToken ct)
    {
        var execution = new BrainToolExecutionEntity
        {
            AgentKey = agentKey,
            ToolKey = descriptor.ToolKey,
            CapabilityKey = descriptor.CapabilityKey,
            TargetArm = descriptor.TargetArm,
            State = "DENIED",
            DenialReason = $"Tool {descriptor.ToolKey} is registered for {descriptor.AgentKey}, not {agentKey}.",
            InputSummary = Summarize(request.PayloadJson),
            CorrelationId = request.CorrelationId,
            CompletedAt = DateTimeOffset.UtcNow
        };
        db.BrainToolExecutions.Add(execution);
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync(agentKey, "TOOL_DENIED", descriptor.TargetArm, descriptor.ToolKey, "DENIED", execution.DenialReason, execution.ExecutionKey, ct);
        return new(false, "DENIED", execution.DenialReason, null, execution.Id);
    }

    private static string Clean(string? value) => string.IsNullOrWhiteSpace(value) ? "UNKNOWN" : value.Trim().ToUpperInvariant();

    private static string? Summarize(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return null;
        var clean = value.Replace('\n', ' ').Replace('\r', ' ').Trim();
        return clean.Length <= 1000 ? clean : clean[..1000] + "…";
    }
}

/// <summary>
/// Runtime-neutral adapter port. Hermes, Microsoft Agent Framework, or a future runtime gets a gateway client,
/// not database credentials. B3 defines the contract but does not yet install/connect Hermes.
/// </summary>
public interface IBrainRuntimeGatewayAdapter
{
    string RuntimeName { get; }
    Task<BrainToolResult> RequestToolAsync(BrainToolRequest request, CancellationToken ct = default);
}

public sealed class LocalBrainRuntimeGatewayAdapter(IBrainToolGatewayService gateway) : IBrainRuntimeGatewayAdapter
{
    public string RuntimeName => "LOCAL_CONTRACT_TEST";
    public Task<BrainToolResult> RequestToolAsync(BrainToolRequest request, CancellationToken ct = default)
        => gateway.ExecuteAsync(request, ct);
}
