namespace MustaineAI.Data;

/// <summary>
/// Durable Brain governance records. These records belong to Ancient Innovations, not to any
/// model provider or agent runtime. Hermes, Microsoft Agent Framework, OpenAI, local models, or
/// future runtimes must obey these boundaries rather than owning them.
/// </summary>
public sealed class BrainAgentProfileEntity
{
    public long Id { get; set; }
    public string AgentKey { get; set; } = string.Empty;
    public string DisplayName { get; set; } = string.Empty;
    public string Purpose { get; set; } = string.Empty;
    public string ArmScope { get; set; } = string.Empty;
    public string RuntimeKind { get; set; } = "UNBOUND";
    public string AutonomyLevel { get; set; } = "ADVISORY";
    public bool Enabled { get; set; } = true;
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class BrainCapabilityGrantEntity
{
    public long Id { get; set; }
    public string AgentKey { get; set; } = string.Empty;
    public string CapabilityKey { get; set; } = string.Empty;
    public string AccessMode { get; set; } = "DENY"; // READ, WRITE_STAGED, EXECUTE, DENY
    public bool RequiresHumanApproval { get; set; }
    public string? BoundaryNote { get; set; }
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
}

public sealed class BrainAuditEventEntity
{
    public long Id { get; set; }
    public string AgentKey { get; set; } = "SYSTEM";
    public string EventType { get; set; } = string.Empty;
    public string TargetArm { get; set; } = string.Empty;
    public string ActionKey { get; set; } = string.Empty;
    public string Outcome { get; set; } = string.Empty;
    public string? Rationale { get; set; }
    public string? CorrelationId { get; set; }
    public DateTimeOffset OccurredAt { get; set; } = DateTimeOffset.UtcNow;
}
