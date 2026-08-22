namespace MustaineAI.Data;

/// <summary>
/// Durable institutional memory owned by Ancient Innovations. Memory is typed and provenance-aware so
/// an observation, assumption, procedure, and learned lesson never become indistinguishable blobs.
/// </summary>
public sealed class BrainMemoryItemEntity
{
    public long Id { get; set; }
    public string MemoryKey { get; set; } = Guid.NewGuid().ToString("N");
    public string MemoryType { get; set; } = "FACT"; // FACT, EPISODE, PROCEDURE, LESSON, ASSUMPTION
    public string ArmScope { get; set; } = "BRAIN_CORE";
    public string? SubjectType { get; set; }
    public string? SubjectKey { get; set; }
    public string Title { get; set; } = string.Empty;
    public string Content { get; set; } = string.Empty;
    public string Status { get; set; } = "ACTIVE"; // PROPOSED, ACTIVE, SUPERSEDED, REJECTED
    public decimal Confidence { get; set; } = 1.00m;
    public string SourceType { get; set; } = "OWNER"; // OWNER, SYSTEM, AGENT, SHOW_RESULT, IMPORT
    public string? SourceRef { get; set; }
    public string? EvidenceSummary { get; set; }
    public string CreatedBy { get; set; } = "SYSTEM";
    public long? SupersedesMemoryId { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? LastConfirmedAt { get; set; }
}

/// <summary>
/// Records recommendations, human decisions/overrides, reasons, and eventual outcomes. This is the
/// learning loop: the Brain can later compare what it predicted with what actually happened.
/// </summary>
public sealed class BrainDecisionRecordEntity
{
    public long Id { get; set; }
    public string DecisionKey { get; set; } = Guid.NewGuid().ToString("N");
    public string AgentKey { get; set; } = "SYSTEM";
    public string ArmScope { get; set; } = "BRAIN_CORE";
    public string? SubjectType { get; set; }
    public string? SubjectKey { get; set; }
    public string DecisionType { get; set; } = "GENERAL";
    public string Recommendation { get; set; } = string.Empty;
    public string? RecommendationReasoning { get; set; }
    public decimal? RecommendationConfidence { get; set; }
    public string? HumanDecision { get; set; }
    public string? HumanReasoning { get; set; }
    public string? Outcome { get; set; }
    public string? OutcomeNotes { get; set; }
    public DateTimeOffset RecommendedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? DecidedAt { get; set; }
    public DateTimeOffset? OutcomeRecordedAt { get; set; }
}

/// <summary>
/// Candidate lessons are deliberately separate from durable memory. Agents may propose lessons, but
/// a proposal is not institutional knowledge until it is reviewed/approved or a future governed rule promotes it.
/// </summary>
public sealed class BrainLearningCandidateEntity
{
    public long Id { get; set; }
    public string LearningKey { get; set; } = Guid.NewGuid().ToString("N");
    public string AgentKey { get; set; } = "SYSTEM";
    public string ArmScope { get; set; } = "BRAIN_CORE";
    public string? SubjectType { get; set; }
    public string? SubjectKey { get; set; }
    public string ProposedLesson { get; set; } = string.Empty;
    public string? Reasoning { get; set; }
    public string? EvidenceRefs { get; set; }
    public decimal Confidence { get; set; } = 0.50m;
    public string Status { get; set; } = "PROPOSED"; // PROPOSED, APPROVED, REJECTED, SUPERSEDED
    public string? ReviewReason { get; set; }
    public long? PromotedMemoryId { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? ReviewedAt { get; set; }
}

/// <summary>
/// Explicit contradiction tracking prevents the Brain from silently overwriting one fact with another.
/// Contradictions remain visible until resolved with a reason.
/// </summary>
public sealed class BrainContradictionEntity
{
    public long Id { get; set; }
    public long MemoryAId { get; set; }
    public long MemoryBId { get; set; }
    public string Description { get; set; } = string.Empty;
    public string Status { get; set; } = "OPEN"; // OPEN, RESOLVED, DISMISSED
    public string DetectedBy { get; set; } = "SYSTEM";
    public string? Resolution { get; set; }
    public string? ResolvedBy { get; set; }
    public DateTimeOffset CreatedAt { get; set; } = DateTimeOffset.UtcNow;
    public DateTimeOffset? ResolvedAt { get; set; }
}
