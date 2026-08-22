using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public interface IBrainDecisionLearningService
{
    Task<BrainLearningCandidateEntity?> ProposeFromOutcomeAsync(long decisionId, CancellationToken ct = default);
}

/// <summary>
/// B7 closes the decision loop without silently teaching the Brain. Once an owner decision and an
/// outcome exist, the live model may propose one narrow lesson. The proposal still goes through the
/// existing Brain Learning Review queue and does not become durable memory without review.
/// </summary>
public sealed class BrainDecisionLearningService(
    ApplicationDbContext db,
    IBrainModelRouter modelRouter,
    IBrainMemoryService memory,
    IBrainCoreService core) : IBrainDecisionLearningService
{
    public async Task<BrainLearningCandidateEntity?> ProposeFromOutcomeAsync(long decisionId, CancellationToken ct = default)
    {
        var row = await db.BrainDecisionRecords.AsNoTracking().FirstOrDefaultAsync(x => x.Id == decisionId, ct)
            ?? throw new InvalidOperationException("Decision record not found.");
        if (string.IsNullOrWhiteSpace(row.HumanDecision)) throw new InvalidOperationException("Record the owner decision before proposing learning.");
        if (string.IsNullOrWhiteSpace(row.Outcome)) throw new InvalidOperationException("Record the outcome before proposing learning.");

        var status = modelRouter.GetStatus();
        if (!status.Configured) return null;

        var system = """
You are the Ancient Innovations Brain learning reviewer. You receive one decision episode: the Brain recommendation, the owner's decision/reason, and the eventual outcome.
Do not turn one event into a sweeping rule. Propose ONE narrow, testable lesson only when this episode supports one. If it does not support durable learning, reply exactly NO_LESSON.
Never invent facts. The owner remains final authority. Return only the proposed lesson sentence or NO_LESSON.
""";
        var user = $"""
ARM: {row.ArmScope}
SUBJECT: {row.SubjectType ?? "GENERAL"} / {row.SubjectKey ?? "GENERAL"}
BRAIN RECOMMENDATION: {row.Recommendation}
BRAIN REASONING: {row.RecommendationReasoning}
OWNER DECISION: {row.HumanDecision}
OWNER REASON: {row.HumanReasoning}
OUTCOME: {row.Outcome}
OUTCOME NOTES: {row.OutcomeNotes}
""";

        var proposed = (await modelRouter.ReasonAsync("LEARNING_FROM_OUTCOME", system, user, ct)).Trim();
        if (string.Equals(proposed, "NO_LESSON", StringComparison.OrdinalIgnoreCase))
        {
            await core.RecordAuditAsync("SHOW_BRAIN", "LEARNING_NOT_PROPOSED", row.ArmScope, "B7.OUTCOME.LEARNING", "NO_LESSON", "Outcome reviewed; evidence did not support a durable lesson.", row.DecisionKey, ct);
            return null;
        }

        var candidate = await memory.ProposeLearningAsync(
            "SHOW_BRAIN",
            row.ArmScope,
            proposed,
            $"Proposed from decision {row.DecisionKey}: Brain recommendation, owner reasoning, and recorded outcome were compared. One episode is not automatically a rule.",
            .55m,
            row.SubjectType,
            row.SubjectKey,
            $"decision:{row.DecisionKey}",
            ct);
        return candidate;
    }
}
