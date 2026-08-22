using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record BrainMemoryDashboard(int ActiveMemories, int ProposedLearnings, int OpenContradictions, int DecisionsAwaitingOutcome);

public interface IBrainMemoryService
{
    Task<BrainMemoryDashboard> GetDashboardAsync(CancellationToken ct = default);
    Task<IReadOnlyList<BrainMemoryItemEntity>> GetRecentMemoryAsync(int take = 50, CancellationToken ct = default);
    Task<IReadOnlyList<BrainLearningCandidateEntity>> GetLearningQueueAsync(int take = 50, CancellationToken ct = default);
    Task<IReadOnlyList<BrainDecisionRecordEntity>> GetRecentDecisionsAsync(int take = 50, CancellationToken ct = default);
    Task<IReadOnlyList<BrainContradictionEntity>> GetOpenContradictionsAsync(int take = 50, CancellationToken ct = default);
    Task<BrainMemoryItemEntity> AddOwnerMemoryAsync(string memoryType, string armScope, string title, string content, decimal confidence = 1m, string? subjectType = null, string? subjectKey = null, string? sourceRef = null, CancellationToken ct = default);
    Task<BrainLearningCandidateEntity> ProposeLearningAsync(string agentKey, string armScope, string proposedLesson, string? reasoning = null, decimal confidence = .5m, string? subjectType = null, string? subjectKey = null, string? evidenceRefs = null, CancellationToken ct = default);
    Task ReviewLearningAsync(long learningId, bool approve, string reviewedBy, string? reason = null, CancellationToken ct = default);
    Task<BrainDecisionRecordEntity> RecordRecommendationAsync(string agentKey, string armScope, string decisionType, string recommendation, string? reasoning = null, decimal? confidence = null, string? subjectType = null, string? subjectKey = null, CancellationToken ct = default);
    Task RecordHumanDecisionAsync(long decisionId, string decision, string reasoning, CancellationToken ct = default);
    Task RecordOutcomeAsync(long decisionId, string outcome, string? notes = null, CancellationToken ct = default);
    Task<BrainContradictionEntity> FlagContradictionAsync(long memoryAId, long memoryBId, string description, string detectedBy, CancellationToken ct = default);
    Task ResolveContradictionAsync(long contradictionId, string resolution, string resolvedBy, CancellationToken ct = default);
}

public sealed class BrainMemoryService(ApplicationDbContext db, IBrainCoreService core) : IBrainMemoryService
{
    private static string CleanType(string? value, string fallback)
        => string.IsNullOrWhiteSpace(value) ? fallback : value.Trim().ToUpperInvariant();

    private static decimal Clamp(decimal value) => Math.Clamp(value, 0m, 1m);

    public async Task<BrainMemoryDashboard> GetDashboardAsync(CancellationToken ct = default)
    {
        var active = await db.BrainMemoryItems.CountAsync(x => x.Status == "ACTIVE", ct);
        var proposed = await db.BrainLearningCandidates.CountAsync(x => x.Status == "PROPOSED", ct);
        var contradictions = await db.BrainContradictions.CountAsync(x => x.Status == "OPEN", ct);
        var awaiting = await db.BrainDecisionRecords.CountAsync(x => x.HumanDecision != null && x.Outcome == null, ct);
        return new(active, proposed, contradictions, awaiting);
    }

    public async Task<IReadOnlyList<BrainMemoryItemEntity>> GetRecentMemoryAsync(int take = 50, CancellationToken ct = default)
        => await db.BrainMemoryItems.AsNoTracking().OrderByDescending(x => x.UpdatedAt).Take(Math.Clamp(take, 1, 200)).ToListAsync(ct);

    public async Task<IReadOnlyList<BrainLearningCandidateEntity>> GetLearningQueueAsync(int take = 50, CancellationToken ct = default)
        => await db.BrainLearningCandidates.AsNoTracking().OrderByDescending(x => x.CreatedAt).Take(Math.Clamp(take, 1, 200)).ToListAsync(ct);

    public async Task<IReadOnlyList<BrainDecisionRecordEntity>> GetRecentDecisionsAsync(int take = 50, CancellationToken ct = default)
        => await db.BrainDecisionRecords.AsNoTracking().OrderByDescending(x => x.RecommendedAt).Take(Math.Clamp(take, 1, 200)).ToListAsync(ct);

    public async Task<IReadOnlyList<BrainContradictionEntity>> GetOpenContradictionsAsync(int take = 50, CancellationToken ct = default)
        => await db.BrainContradictions.AsNoTracking().Where(x => x.Status == "OPEN").OrderByDescending(x => x.CreatedAt).Take(Math.Clamp(take, 1, 200)).ToListAsync(ct);

    public async Task<BrainMemoryItemEntity> AddOwnerMemoryAsync(string memoryType, string armScope, string title, string content, decimal confidence = 1m, string? subjectType = null, string? subjectKey = null, string? sourceRef = null, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(title) || string.IsNullOrWhiteSpace(content)) throw new ArgumentException("Memory title and content are required.");
        var item = new BrainMemoryItemEntity
        {
            MemoryType = CleanType(memoryType, "FACT"), ArmScope = CleanType(armScope, "BRAIN_CORE"),
            SubjectType = string.IsNullOrWhiteSpace(subjectType) ? null : subjectType.Trim(), SubjectKey = string.IsNullOrWhiteSpace(subjectKey) ? null : subjectKey.Trim(),
            Title = title.Trim(), Content = content.Trim(), Status = "ACTIVE", Confidence = Clamp(confidence),
            SourceType = "OWNER", SourceRef = sourceRef, CreatedBy = "OWNER", LastConfirmedAt = DateTimeOffset.UtcNow
        };
        db.BrainMemoryItems.Add(item);
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync("OWNER", "MEMORY_CREATED", item.ArmScope, "BRAIN.MEMORY.WRITE", "ACTIVE", $"{item.MemoryType}: {item.Title}", item.MemoryKey, ct);
        return item;
    }

    public async Task<BrainLearningCandidateEntity> ProposeLearningAsync(string agentKey, string armScope, string proposedLesson, string? reasoning = null, decimal confidence = .5m, string? subjectType = null, string? subjectKey = null, string? evidenceRefs = null, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(proposedLesson)) throw new ArgumentException("A proposed lesson is required.");
        var candidate = new BrainLearningCandidateEntity
        {
            AgentKey = CleanType(agentKey, "SYSTEM"), ArmScope = CleanType(armScope, "BRAIN_CORE"), SubjectType = subjectType, SubjectKey = subjectKey,
            ProposedLesson = proposedLesson.Trim(), Reasoning = reasoning, EvidenceRefs = evidenceRefs, Confidence = Clamp(confidence), Status = "PROPOSED"
        };
        db.BrainLearningCandidates.Add(candidate);
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync(candidate.AgentKey, "LEARNING_PROPOSED", candidate.ArmScope, "BRAIN.LEARNING.PROPOSE", "STAGED", candidate.ProposedLesson, candidate.LearningKey, ct);
        return candidate;
    }

    public async Task ReviewLearningAsync(long learningId, bool approve, string reviewedBy, string? reason = null, CancellationToken ct = default)
    {
        var candidate = await db.BrainLearningCandidates.FirstOrDefaultAsync(x => x.Id == learningId, ct) ?? throw new InvalidOperationException("Learning candidate not found.");
        if (candidate.Status != "PROPOSED") throw new InvalidOperationException("Learning candidate has already been reviewed.");
        candidate.Status = approve ? "APPROVED" : "REJECTED";
        candidate.ReviewReason = reason;
        candidate.ReviewedAt = DateTimeOffset.UtcNow;
        if (approve)
        {
            var memory = new BrainMemoryItemEntity
            {
                MemoryType = "LESSON", ArmScope = candidate.ArmScope, SubjectType = candidate.SubjectType, SubjectKey = candidate.SubjectKey,
                Title = candidate.ProposedLesson.Length <= 180 ? candidate.ProposedLesson : candidate.ProposedLesson[..180], Content = candidate.ProposedLesson,
                Status = "ACTIVE", Confidence = candidate.Confidence, SourceType = "AGENT_LEARNING", SourceRef = $"learning:{candidate.LearningKey}",
                EvidenceSummary = candidate.EvidenceRefs, CreatedBy = reviewedBy, LastConfirmedAt = DateTimeOffset.UtcNow
            };
            db.BrainMemoryItems.Add(memory);
            await db.SaveChangesAsync(ct);
            candidate.PromotedMemoryId = memory.Id;
        }
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync(reviewedBy, "LEARNING_REVIEWED", candidate.ArmScope, "BRAIN.LEARNING.REVIEW", approve ? "APPROVED" : "REJECTED", reason ?? candidate.ProposedLesson, candidate.LearningKey, ct);
    }

    public async Task<BrainDecisionRecordEntity> RecordRecommendationAsync(string agentKey, string armScope, string decisionType, string recommendation, string? reasoning = null, decimal? confidence = null, string? subjectType = null, string? subjectKey = null, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(recommendation)) throw new ArgumentException("Recommendation is required.");
        var row = new BrainDecisionRecordEntity
        {
            AgentKey = CleanType(agentKey, "SYSTEM"), ArmScope = CleanType(armScope, "BRAIN_CORE"), DecisionType = CleanType(decisionType, "GENERAL"),
            SubjectType = subjectType, SubjectKey = subjectKey, Recommendation = recommendation.Trim(), RecommendationReasoning = reasoning,
            RecommendationConfidence = confidence.HasValue ? Clamp(confidence.Value) : null
        };
        db.BrainDecisionRecords.Add(row);
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync(row.AgentKey, "RECOMMENDATION_RECORDED", row.ArmScope, "BRAIN.DECISION.RECOMMEND", "STAGED", row.RecommendationReasoning, row.DecisionKey, ct);
        return row;
    }

    public async Task RecordHumanDecisionAsync(long decisionId, string decision, string reasoning, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(decision) || string.IsNullOrWhiteSpace(reasoning)) throw new ArgumentException("Human decision and reasoning are required.");
        var row = await db.BrainDecisionRecords.FirstOrDefaultAsync(x => x.Id == decisionId, ct) ?? throw new InvalidOperationException("Decision record not found.");
        row.HumanDecision = decision.Trim(); row.HumanReasoning = reasoning.Trim(); row.DecidedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync("OWNER", "HUMAN_DECISION_RECORDED", row.ArmScope, "BRAIN.DECISION.HUMAN", "RECORDED", row.HumanReasoning, row.DecisionKey, ct);
    }

    public async Task RecordOutcomeAsync(long decisionId, string outcome, string? notes = null, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(outcome)) throw new ArgumentException("Outcome is required.");
        var row = await db.BrainDecisionRecords.FirstOrDefaultAsync(x => x.Id == decisionId, ct) ?? throw new InvalidOperationException("Decision record not found.");
        row.Outcome = outcome.Trim(); row.OutcomeNotes = notes; row.OutcomeRecordedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync("SYSTEM", "OUTCOME_RECORDED", row.ArmScope, "BRAIN.DECISION.OUTCOME", "RECORDED", notes, row.DecisionKey, ct);
    }

    public async Task<BrainContradictionEntity> FlagContradictionAsync(long memoryAId, long memoryBId, string description, string detectedBy, CancellationToken ct = default)
    {
        if (memoryAId == memoryBId) throw new ArgumentException("A memory cannot contradict itself.");
        var existsA = await db.BrainMemoryItems.AnyAsync(x => x.Id == memoryAId, ct);
        var existsB = await db.BrainMemoryItems.AnyAsync(x => x.Id == memoryBId, ct);
        if (!existsA || !existsB) throw new InvalidOperationException("Both memories must exist before a contradiction can be recorded.");
        var row = new BrainContradictionEntity { MemoryAId = memoryAId, MemoryBId = memoryBId, Description = description.Trim(), DetectedBy = detectedBy, Status = "OPEN" };
        db.BrainContradictions.Add(row);
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync(detectedBy, "CONTRADICTION_FLAGGED", "BRAIN_CORE", "BRAIN.MEMORY.CONTRADICTION", "OPEN", row.Description, $"contradiction:{row.Id}", ct);
        return row;
    }

    public async Task ResolveContradictionAsync(long contradictionId, string resolution, string resolvedBy, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(resolution)) throw new ArgumentException("Resolution is required.");
        var row = await db.BrainContradictions.FirstOrDefaultAsync(x => x.Id == contradictionId, ct) ?? throw new InvalidOperationException("Contradiction not found.");
        row.Status = "RESOLVED"; row.Resolution = resolution.Trim(); row.ResolvedBy = resolvedBy; row.ResolvedAt = DateTimeOffset.UtcNow;
        await db.SaveChangesAsync(ct);
        await core.RecordAuditAsync(resolvedBy, "CONTRADICTION_RESOLVED", "BRAIN_CORE", "BRAIN.MEMORY.CONTRADICTION", "RESOLVED", row.Resolution, $"contradiction:{row.Id}", ct);
    }
}
