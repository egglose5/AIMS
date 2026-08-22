using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record BrainModelStatus(string ProviderKey, bool Configured, string? ModelName, string EndpointLabel, string Detail);
public sealed record BrainReasoningResult(bool Succeeded, string State, string Message, string? OutputText, long RunId, string ProviderKey, string? ModelName);
public sealed record BrainReasoningDashboard(int TotalRuns, int CompletedRuns, int FailedRuns, DateTimeOffset? LastRunAt);

public interface IBrainReasoningService
{
    BrainModelStatus GetModelStatus();
    Task<BrainReasoningDashboard> GetDashboardAsync(CancellationToken ct = default);
    Task<IReadOnlyList<BrainReasoningRunEntity>> GetRecentRunsAsync(int take = 25, CancellationToken ct = default);
    Task<BrainReasoningResult> ReasonForShowArmAsync(string question, string? subjectType = null, string? subjectKey = null, CancellationToken ct = default);
    Task<string> RunContractSelfTestAsync(CancellationToken ct = default);
    Task<BrainReasoningResult> RunLiveProviderSelfTestAsync(CancellationToken ct = default);
}

/// <summary>
/// Provider-neutral router. B4/B5 use an OpenAI Responses adapter because it is a current supported provider,
/// but callers only know IBrainModelRouter. Provider selection is environment configuration, not business logic.
/// </summary>
public sealed class BrainModelRouter(IHttpClientFactory httpClientFactory) : IBrainModelRouter
{
    private readonly string _provider = (Environment.GetEnvironmentVariable("BRAIN_REASONING_PROVIDER") ?? "UNCONFIGURED").Trim().ToUpperInvariant();
    private readonly string? _model = Clean(Environment.GetEnvironmentVariable("BRAIN_REASONING_MODEL"));
    private readonly string? _apiKey = Clean(Environment.GetEnvironmentVariable("BRAIN_REASONING_API_KEY")) ?? Clean(Environment.GetEnvironmentVariable("OPENAI_API_KEY"));
    private readonly string _endpoint = (Clean(Environment.GetEnvironmentVariable("BRAIN_REASONING_ENDPOINT")) ?? "https://api.openai.com/v1/responses").Trim();
    private readonly string _effort = (Clean(Environment.GetEnvironmentVariable("BRAIN_REASONING_EFFORT")) ?? "low").ToLowerInvariant();

    public BrainModelStatus GetStatus()
    {
        if (_provider is "OPENAI" or "OPENAI_RESPONSES")
        {
            var configured = !string.IsNullOrWhiteSpace(_apiKey) && !string.IsNullOrWhiteSpace(_model);
            return new("OPENAI_RESPONSES", configured, _model, "Responses API", configured
                ? "Configured through environment variables. The provider receives prompt/context only; it has no Control App credentials."
                : "Set BRAIN_REASONING_PROVIDER=OPENAI, BRAIN_REASONING_MODEL and BRAIN_REASONING_API_KEY (or OPENAI_API_KEY) to enable live reasoning.");
        }
        return new(_provider, false, _model, "No live endpoint", "No live reasoning provider is configured. B4 contract/self-tests still work without sending data outside Ancient Innovations.");
    }

    public async Task<string> ReasonAsync(string taskType, string systemContext, string userContext, CancellationToken ct = default)
    {
        var status = GetStatus();
        if (!status.Configured) throw new InvalidOperationException("No live Brain reasoning provider is configured.");
        if (status.ProviderKey != "OPENAI_RESPONSES") throw new InvalidOperationException($"Brain reasoning provider '{status.ProviderKey}' is not installed in B5.");

        using var request = new HttpRequestMessage(HttpMethod.Post, _endpoint);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _apiKey);
        var payload = new
        {
            model = _model,
            reasoning = new { effort = _effort },
            instructions = systemContext,
            input = userContext
        };
        request.Content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");

        var client = httpClientFactory.CreateClient();
        client.Timeout = TimeSpan.FromSeconds(90);
        using var response = await client.SendAsync(request, ct);
        var body = await response.Content.ReadAsStringAsync(ct);
        if (!response.IsSuccessStatusCode)
            throw new InvalidOperationException($"Reasoning provider returned {(int)response.StatusCode}: {SafeError(body)}");

        using var doc = JsonDocument.Parse(body);
        var text = ExtractOutputText(doc.RootElement);
        if (string.IsNullOrWhiteSpace(text)) throw new InvalidOperationException("Reasoning provider returned no output text.");
        return text.Trim();
    }

    public Task<string> RunContractTestAsync(CancellationToken ct = default)
    {
        ct.ThrowIfCancellationRequested();
        return Task.FromResult("B4 ROUTER CONTRACT PASS — provider-neutral port accepted a reasoning request without external network access.");
    }

    private static string? ExtractOutputText(JsonElement root)
    {
        if (root.TryGetProperty("output_text", out var direct) && direct.ValueKind == JsonValueKind.String)
            return direct.GetString();
        if (!root.TryGetProperty("output", out var output) || output.ValueKind != JsonValueKind.Array) return null;
        var parts = new List<string>();
        foreach (var item in output.EnumerateArray())
        {
            if (!item.TryGetProperty("content", out var content) || content.ValueKind != JsonValueKind.Array) continue;
            foreach (var c in content.EnumerateArray())
            {
                if (c.TryGetProperty("text", out var text) && text.ValueKind == JsonValueKind.String && !string.IsNullOrWhiteSpace(text.GetString()))
                    parts.Add(text.GetString()!);
            }
        }
        return parts.Count == 0 ? null : string.Join("\n", parts);
    }

    private static string SafeError(string value)
    {
        var clean = value.Replace('\n', ' ').Replace('\r', ' ').Trim();
        return clean.Length <= 500 ? clean : clean[..500] + "…";
    }
    private static bool IsDiagnosticSubject(string? subjectType, string? subjectKey)
    {
        var text = $"{subjectType} {subjectKey}".ToUpperInvariant();
        return text.Contains("SELF_TEST") || text.Contains("REAL_DATA_TEST") || text.Contains("CONTRACT_TEST");
    }

    private static string ExtractRecommendation(string output)
    {
        if (string.IsNullOrWhiteSpace(output)) return "No recommendation returned.";

        var lines = output.Replace("\r\n", "\n").Replace('\r', '\n').Split('\n');
        for (var i = 0; i < lines.Length; i++)
        {
            var raw = lines[i].Trim();
            var normalized = raw.TrimStart('#').Trim().Trim('*').Trim();
            if (!normalized.StartsWith("Recommendation", StringComparison.OrdinalIgnoreCase)) continue;

            // Accept inline forms such as "Recommendation: Apply" or "## Recommendation — Apply".
            var remainder = normalized["Recommendation".Length..].Trim();
            remainder = remainder.TrimStart(':', '-', '–', '—', ' ').Trim().Trim('*').Trim();
            if (!string.IsNullOrWhiteSpace(remainder)) return LimitRecommendation(remainder);

            // Markdown commonly returns a heading on one line and the actual recommendation below it.
            var body = new List<string>();
            for (var j = i + 1; j < lines.Length; j++)
            {
                var candidateRaw = lines[j].Trim();
                if (string.IsNullOrWhiteSpace(candidateRaw))
                {
                    if (body.Count > 0) break;
                    continue;
                }

                var candidate = candidateRaw.TrimStart('#').Trim().Trim('*').Trim();
                if (candidateRaw.StartsWith("#") ||
                    candidate.StartsWith("Evidence used", StringComparison.OrdinalIgnoreCase) ||
                    candidate.StartsWith("Why", StringComparison.OrdinalIgnoreCase) ||
                    candidate.StartsWith("Confidence", StringComparison.OrdinalIgnoreCase) ||
                    candidate.StartsWith("Unknowns", StringComparison.OrdinalIgnoreCase) ||
                    candidate.StartsWith("What would change", StringComparison.OrdinalIgnoreCase))
                    break;

                body.Add(candidateRaw);
            }

            if (body.Count > 0) return LimitRecommendation(string.Join(" ", body));
        }

        var first = lines.Select(x => x.Trim()).FirstOrDefault(x => !string.IsNullOrWhiteSpace(x)) ?? output.Trim();
        return LimitRecommendation(first.Trim(' ', '-', '*', '#'));
    }

    private static string LimitRecommendation(string value)
    {
        var clean = value.Trim();
        return clean.Length <= 2000 ? clean : clean[..2000] + "…";
    }

    private static string? Clean(string? value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();
}

public sealed class ShowBrainReasoningService(
    ApplicationDbContext db,
    IBrainCoreService core,
    IBrainToolGatewayService gateway,
    IBrainMemoryService memory,
    IBrainModelRouter modelRouter) : IBrainReasoningService
{
    private BrainModelRouter Router => modelRouter as BrainModelRouter
        ?? throw new InvalidOperationException("The configured Brain model router does not expose B4 status.");

    public BrainModelStatus GetModelStatus() => Router.GetStatus();

    public async Task<BrainReasoningDashboard> GetDashboardAsync(CancellationToken ct = default)
    {
        var total = await db.BrainReasoningRuns.CountAsync(ct);
        var completed = await db.BrainReasoningRuns.CountAsync(x => x.State == "COMPLETED", ct);
        var failed = await db.BrainReasoningRuns.CountAsync(x => x.State == "FAILED" || x.State == "NOT_CONFIGURED", ct);
        var last = await db.BrainReasoningRuns.OrderByDescending(x => x.StartedAt).Select(x => (DateTimeOffset?)x.StartedAt).FirstOrDefaultAsync(ct);
        return new(total, completed, failed, last);
    }

    public async Task<IReadOnlyList<BrainReasoningRunEntity>> GetRecentRunsAsync(int take = 25, CancellationToken ct = default)
        => await db.BrainReasoningRuns.AsNoTracking().OrderByDescending(x => x.StartedAt).Take(Math.Clamp(take, 1, 100)).ToListAsync(ct);

    public async Task<BrainReasoningResult> ReasonForShowArmAsync(string question, string? subjectType = null, string? subjectKey = null, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(question)) throw new ArgumentException("A reasoning question is required.", nameof(question));
        var auth = await core.AuthorizeAsync("SHOW_BRAIN", "SHOW.INTELLIGENCE.READ", false, ct);
        if (!auth.Allowed) throw new InvalidOperationException(auth.Reason);

        var provider = GetModelStatus();
        var run = new BrainReasoningRunEntity
        {
            AgentKey = "SHOW_BRAIN",
            TaskType = "SHOW_ARM_ADVISORY",
            SubjectType = Clean(subjectType),
            SubjectKey = Clean(subjectKey),
            ProviderKey = provider.ProviderKey,
            ModelName = provider.ModelName,
            State = "REQUESTED",
            UserQuestion = question.Trim()
        };
        db.BrainReasoningRuns.Add(run);
        await db.SaveChangesAsync(ct);

        try
        {
            var correlation = $"B8-{run.RunKey}";
            var memoryContext = await gateway.ExecuteAsync(new BrainToolRequest("SHOW_BRAIN", "show-brain.memory-context", null, correlation + "-MEMORY"), ct);
            if (!memoryContext.Succeeded) throw new InvalidOperationException($"Governed memory request failed: {memoryContext.Message}");
            var evidenceRequest = JsonSerializer.Serialize(new { question = question.Trim(), subjectType = run.SubjectType, subjectKey = run.SubjectKey });
            var showContext = await gateway.ExecuteAsync(new BrainToolRequest("SHOW_BRAIN", "show-brain.show-arm-context", evidenceRequest, correlation + "-SHOWDATA"), ct);
            if (!showContext.Succeeded) throw new InvalidOperationException($"Governed Show Arm evidence request failed: {showContext.Message}");

            var recentMemory = await memory.GetRecentMemoryAsync(50, ct);
            var activeShowMemoryCount = recentMemory.Count(x => x.ArmScope == "SHOW_ARM" && x.Status == "ACTIVE");
            run.ContextSummary = $"B8 governed context: durable SHOW_ARM memory + question-targeted, token-budgeted operational Show Arm evidence; {activeShowMemoryCount} active durable memory item(s). Subject={run.SubjectType ?? "GENERAL"}:{run.SubjectKey ?? "GENERAL"}.";
            await db.SaveChangesAsync(ct);

            if (!provider.Configured)
            {
                run.State = "NOT_CONFIGURED";
                run.ErrorMessage = provider.Detail;
                run.CompletedAt = DateTimeOffset.UtcNow;
                await db.SaveChangesAsync(ct);
                await core.RecordAuditAsync("SHOW_BRAIN", "REASONING_NOT_CONFIGURED", "SHOW_ARM", "B5.MODEL_REASON", "NOT_CONFIGURED", provider.Detail, run.RunKey, ct);
                return new(false, run.State, provider.Detail, null, run.Id, provider.ProviderKey, provider.ModelName);
            }

            var system = BuildSystemContext();
            var user = BuildUserContext(question.Trim(), memoryContext.ResultJson, showContext.ResultJson, run.SubjectType, run.SubjectKey);
            var output = await modelRouter.ReasonAsync(run.TaskType, system, user, ct);

            run.OutputText = output;
            run.State = "COMPLETED";
            run.CompletedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);

            // B7: successful non-diagnostic advisory reasoning becomes an Ancient Innovations-owned
            // decision record. The model still cannot act; it merely creates a recommendation that
            // waits for the owner's decision and eventual outcome.
            if (!IsDiagnosticSubject(run.SubjectType, run.SubjectKey))
            {
                // B92_DECISION_SAVE_GUARD: a decision-ledger persistence problem must not
                // discard a successful advisory model answer.
                try
                {
                    await memory.RecordRecommendationAsync(
                        "SHOW_BRAIN",
                        "SHOW_ARM",
                        "SHOW_ARM_ADVISORY",
                        ExtractRecommendation(output),
                        output,
                        null,
                        run.SubjectType,
                        run.SubjectKey,
                        ct);
                }
                catch (Exception decisionEx)
                {
                    await core.RecordAuditAsync(
                        "SHOW_BRAIN",
                        "RECOMMENDATION_STAGE_FAILED",
                        "SHOW_ARM",
                        "B9.2.DECISION_LEDGER",
                        "FAILED",
                        decisionEx.GetBaseException().Message,
                        run.RunKey,
                        ct);
                }
            }

            await core.RecordAuditAsync("SHOW_BRAIN", "REASONING_COMPLETED", "SHOW_ARM", "B8.MODEL_REASON", "COMPLETED", $"Provider={provider.ProviderKey}; Model={provider.ModelName}", run.RunKey, ct);
            return new(true, run.State, "Show Brain reasoning completed and the recommendation was staged for owner decision.", output, run.Id, provider.ProviderKey, provider.ModelName);
        }
        catch (Exception ex)
        {
            run.State = "FAILED";
            run.ErrorMessage = ex.GetBaseException().Message;
            run.CompletedAt = DateTimeOffset.UtcNow;
            await db.SaveChangesAsync(ct);
            await core.RecordAuditAsync("SHOW_BRAIN", "REASONING_FAILED", "SHOW_ARM", "B5.MODEL_REASON", "FAILED", ex.GetBaseException().Message, run.RunKey, ct);
            return new(false, run.State, ex.GetBaseException().Message, null, run.Id, provider.ProviderKey, provider.ModelName);
        }
    }

    public async Task<BrainReasoningResult> RunLiveProviderSelfTestAsync(CancellationToken ct = default)
    {
        var status = GetModelStatus();
        if (!status.Configured)
            return new(false, "NOT_CONFIGURED", status.Detail, null, 0, status.ProviderKey, status.ModelName);

        return await ReasonForShowArmAsync(
            "B5 live-provider self-test. Confirm in one short sentence that you are operating only as an advisory Show Brain and cannot submit applications, spend money, assign vendors, or alter canonical business data.",
            "B5_SELF_TEST",
            "LIVE_PROVIDER",
            ct);
    }

    public async Task<string> RunContractSelfTestAsync(CancellationToken ct = default)
    {
        var auth = await core.AuthorizeAsync("SHOW_BRAIN", "SHOW.INTELLIGENCE.READ", false, ct);
        if (!auth.Allowed) throw new InvalidOperationException("B4 contract test failed governance authorization.");
        var context = await gateway.ExecuteAsync(new BrainToolRequest("SHOW_BRAIN", "show-brain.memory-context", null, $"B4-CONTRACT-{DateTimeOffset.UtcNow:yyyyMMddHHmmss}"), ct);
        if (!context.Succeeded) throw new InvalidOperationException("B4 contract test could not retrieve governed memory context.");
        var routerResult = await Router.RunContractTestAsync(ct);
        return $"B4 PASS — governance allowed advisory read; context crossed B3 gateway; {routerResult}";
    }

    private static string BuildSystemContext() => """
You are the Ancient Innovations Show Arm Brain, an advisory reasoning worker operating under Ancient Innovations Brain Core governance.
Control App and Arm-owned data are the source of truth. Treat supplied memory as evidence with provenance/confidence, not as permission to invent missing facts.
Separate facts, assumptions, unknowns, and inferences. If evidence is insufficient, say what is missing.
You may recommend and explain. You may not submit applications, spend money, assign vendors/backers, alter canonical business data, or claim an action was performed.
When the owner disagrees, explain the reasoning rather than treating disagreement itself as proof that a new rule is true.
Use concrete Ancient Innovations evidence when it is supplied. Never pretend a field is known if it is absent or ambiguous. A calibration, note, email, or imported historical statement is evidence, but it is NOT a formal ShowResult unless the supplied evidence explicitly says it is. Do not silently upgrade evidence into canonical results.
Return concise useful reasoning with: Recommendation, Evidence used, Why, Confidence, Unknowns, and What would change the recommendation.
""";

    private static string BuildUserContext(string question, string? governedMemoryJson, string? governedShowArmJson, string? subjectType, string? subjectKey)
        => $"""
OWNER QUESTION:
{question}

SUBJECT:
{subjectType ?? "GENERAL"} / {subjectKey ?? "GENERAL"}

GOVERNED DURABLE BRAIN MEMORY (read through B3 gateway):
{governedMemoryJson ?? "No governed memory returned."}

GOVERNED OPERATIONAL SHOW ARM EVIDENCE (read-only, question-targeted, token-budgeted through B3 gateway):
{governedShowArmJson ?? "No operational Show Arm evidence returned."}
""";

    private static bool IsDiagnosticSubject(string? subjectType, string? subjectKey)
    {
        var text = $"{subjectType} {subjectKey}".ToUpperInvariant();
        return text.Contains("SELF_TEST") || text.Contains("REAL_DATA_TEST") || text.Contains("CONTRACT_TEST");
    }

    private static string ExtractRecommendation(string output)
    {
        if (string.IsNullOrWhiteSpace(output)) return "No recommendation returned.";

        var lines = output.Replace("\r\n", "\n").Replace('\r', '\n').Split('\n');
        for (var i = 0; i < lines.Length; i++)
        {
            var raw = lines[i].Trim();
            var normalized = raw.TrimStart('#').Trim().Trim('*').Trim();
            if (!normalized.StartsWith("Recommendation", StringComparison.OrdinalIgnoreCase)) continue;

            // Accept inline forms such as "Recommendation: Apply" or "## Recommendation — Apply".
            var remainder = normalized["Recommendation".Length..].Trim();
            remainder = remainder.TrimStart(':', '-', '–', '—', ' ').Trim().Trim('*').Trim();
            if (!string.IsNullOrWhiteSpace(remainder)) return LimitRecommendation(remainder);

            // Markdown commonly returns a heading on one line and the actual recommendation below it.
            var body = new List<string>();
            for (var j = i + 1; j < lines.Length; j++)
            {
                var candidateRaw = lines[j].Trim();
                if (string.IsNullOrWhiteSpace(candidateRaw))
                {
                    if (body.Count > 0) break;
                    continue;
                }

                var candidate = candidateRaw.TrimStart('#').Trim().Trim('*').Trim();
                if (candidateRaw.StartsWith("#") ||
                    candidate.StartsWith("Evidence used", StringComparison.OrdinalIgnoreCase) ||
                    candidate.StartsWith("Why", StringComparison.OrdinalIgnoreCase) ||
                    candidate.StartsWith("Confidence", StringComparison.OrdinalIgnoreCase) ||
                    candidate.StartsWith("Unknowns", StringComparison.OrdinalIgnoreCase) ||
                    candidate.StartsWith("What would change", StringComparison.OrdinalIgnoreCase))
                    break;

                body.Add(candidateRaw);
            }

            if (body.Count > 0) return LimitRecommendation(string.Join(" ", body));
        }

        var first = lines.Select(x => x.Trim()).FirstOrDefault(x => !string.IsNullOrWhiteSpace(x)) ?? output.Trim();
        return LimitRecommendation(first.Trim(' ', '-', '*', '#'));
    }

    private static string LimitRecommendation(string value)
    {
        var clean = value.Trim();
        return clean.Length <= 2000 ? clean : clean[..2000] + "…";
    }

    private static string? Clean(string? value) => string.IsNullOrWhiteSpace(value) ? null : value.Trim();
}
