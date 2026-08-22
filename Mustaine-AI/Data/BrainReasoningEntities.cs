namespace MustaineAI.Data;

/// <summary>
/// B4 ledger for model-backed reasoning. The provider/model are implementation details; the run,
/// supplied context summary, output and failure state remain Ancient Innovations-owned audit data.
/// </summary>
public sealed class BrainReasoningRunEntity
{
    public long Id { get; set; }
    public string RunKey { get; set; } = Guid.NewGuid().ToString("N");
    public string AgentKey { get; set; } = "SHOW_BRAIN";
    public string TaskType { get; set; } = "ADVISORY";
    public string? SubjectType { get; set; }
    public string? SubjectKey { get; set; }
    public string ProviderKey { get; set; } = "UNCONFIGURED";
    public string? ModelName { get; set; }
    public string State { get; set; } = "REQUESTED"; // REQUESTED, COMPLETED, FAILED, NOT_CONFIGURED
    public string UserQuestion { get; set; } = string.Empty;
    public string? ContextSummary { get; set; }
    public string? OutputText { get; set; }
    public string? ErrorMessage { get; set; }
    public DateTimeOffset StartedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? CompletedAt { get; set; }
}
