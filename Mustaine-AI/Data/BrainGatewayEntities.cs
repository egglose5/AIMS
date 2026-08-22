namespace MustaineAI.Data;

/// <summary>
/// Immutable-ish execution ledger for every request that crosses the Brain tool gateway.
/// The agent runtime never calls business services directly; it asks the gateway, which authorizes first.
/// </summary>
public sealed class BrainToolExecutionEntity
{
    public long Id { get; set; }
    public string ExecutionKey { get; set; } = Guid.NewGuid().ToString("N");
    public string AgentKey { get; set; } = string.Empty;
    public string ToolKey { get; set; } = string.Empty;
    public string CapabilityKey { get; set; } = string.Empty;
    public string TargetArm { get; set; } = "BRAIN_CORE";
    public string State { get; set; } = "REQUESTED"; // REQUESTED, DENIED, APPROVAL_REQUIRED, COMPLETED, FAILED
    public bool Consequential { get; set; }
    public string? InputSummary { get; set; }
    public string? OutputSummary { get; set; }
    public string? DenialReason { get; set; }
    public string? CorrelationId { get; set; }
    public DateTimeOffset RequestedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? CompletedAt { get; set; }
}

/// <summary>
/// Human approval request created only when a capability is allowed in principle but explicitly gated.
/// A DENY grant is never converted into an approval request; DENY means the agent cannot perform it.
/// </summary>
public sealed class BrainApprovalRequestEntity
{
    public long Id { get; set; }
    public string ApprovalKey { get; set; } = Guid.NewGuid().ToString("N");
    public long BrainToolExecutionId { get; set; }
    public string AgentKey { get; set; } = string.Empty;
    public string ToolKey { get; set; } = string.Empty;
    public string CapabilityKey { get; set; } = string.Empty;
    public string TargetArm { get; set; } = "BRAIN_CORE";
    public string Status { get; set; } = "PENDING"; // PENDING, APPROVED, REJECTED, EXPIRED
    public string? RequestReason { get; set; }
    public string? ReviewedBy { get; set; }
    public string? ReviewReason { get; set; }
    public DateTimeOffset RequestedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? ReviewedAt { get; set; }
}
