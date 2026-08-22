using Microsoft.EntityFrameworkCore;
using MustaineAI.Data;

namespace MustaineAI.Services;

public sealed record BrainAuthorizationDecision(bool Allowed, bool RequiresHumanApproval, string Reason);
public sealed record BrainRuntimeStatus(string BrainCore, string Orchestration, string AutonomousAgentRuntime, string ModelRouter, string SourceOfTruth);

/// <summary>
/// Permanent governance boundary for the Ancient Innovations Brain.
/// Agent runtimes and model providers are replaceable implementation details behind this contract.
/// </summary>
public interface IBrainCoreService
{
    Task EnsureFoundationAsync(CancellationToken ct = default);
    Task<IReadOnlyList<BrainAgentProfileEntity>> GetAgentsAsync(CancellationToken ct = default);
    Task<IReadOnlyList<BrainCapabilityGrantEntity>> GetCapabilitiesAsync(string? agentKey = null, CancellationToken ct = default);
    Task<IReadOnlyList<BrainAuditEventEntity>> GetRecentAuditAsync(int take = 50, CancellationToken ct = default);
    Task<BrainAuthorizationDecision> AuthorizeAsync(string agentKey, string capabilityKey, bool isConsequentialAction = false, CancellationToken ct = default);
    Task RecordAuditAsync(string agentKey, string eventType, string targetArm, string actionKey, string outcome, string? rationale = null, string? correlationId = null, CancellationToken ct = default);
    BrainRuntimeStatus GetRuntimeStatus();
}

public sealed class BrainCoreService(ApplicationDbContext db) : IBrainCoreService
{
    private static readonly BrainAgentProfileEntity[] DefaultAgents =
    [
        new() { AgentKey = "SCOUT", DisplayName = "Scout", Purpose = "Discover, investigate and watch external show opportunities.", ArmScope = "SHOW_ARM", RuntimeKind = "HERMES_PLANNED", AutonomyLevel = "RESEARCH_AUTONOMOUS" },
        new() { AgentKey = "SHOW_BRAIN", DisplayName = "Show Arm Brain", Purpose = "Reason over Show Arm evidence, history, vendor fit and outcomes; recommend but do not commit.", ArmScope = "SHOW_ARM", RuntimeKind = "BRAIN_ORCHESTRATED", AutonomyLevel = "ADVISORY" }
    ];

    private static readonly (string Agent, string Capability, string Mode, bool Approval, string Note)[] DefaultCapabilities =
    [
        ("SCOUT", "SHOW.DISCOVERY.READ_PUBLIC_WEB", "EXECUTE", false, "Scout may search public sources for candidate shows."),
        ("SCOUT", "SHOW.DISCOVERY.WRITE_STAGING", "WRITE_STAGED", false, "Discovery stays outside the canonical show database until accepted."),
        ("SCOUT", "SHOW.RESEARCH.READ_CONTROLLED_SLICE", "READ", false, "Read only the Show Arm slice needed for comparison and deduplication."),
        ("SCOUT", "SHOW.RESEARCH.WRITE_EVIDENCE", "WRITE_STAGED", false, "Evidence is stored with provenance and does not become operational truth automatically."),
        ("SCOUT", "SHOW.APPLICATION.SUBMIT", "DENY", true, "Scout never submits applications."),
        ("SCOUT", "SHOW.MONEY.SPEND", "DENY", true, "Scout never spends money or commits fees."),
        ("SCOUT", "SHOW.VENDOR.ASSIGN", "DENY", true, "Scout never assigns a vendor or backer."),
        ("SCOUT", "INVENTORY.READ", "DENY", false, "Scout has no direct Inventory Arm access."),
        ("SCOUT", "FINANCE.READ_CANONICAL", "DENY", false, "Scout receives only approved show-economic facts, never canonical finance access."),
        ("SHOW_BRAIN", "SHOW.INTELLIGENCE.READ", "READ", false, "Read Show Arm history, evidence, forecasts, results, maps, notes and vendor fit."),
        ("SHOW_BRAIN", "SHOW.RECOMMENDATION.WRITE", "WRITE_STAGED", false, "Recommendations remain advisory until a human/business workflow acts."),
        ("SHOW_BRAIN", "SHOW.LEARNING.PROPOSE", "WRITE_STAGED", false, "May propose lessons; durable learning is handled by the Brain learning layer."),
        ("SHOW_BRAIN", "SHOW.APPLICATION.SUBMIT", "DENY", true, "Consequential application actions stay human-controlled."),
        ("SHOW_BRAIN", "SHOW.MONEY.SPEND", "DENY", true, "Brain reasoning cannot spend money."),
        ("SHOW_BRAIN", "SHOW.VENDOR.ASSIGN", "DENY", true, "Final vendor assignment remains controlled by Show Arm workflow."),
    ];

    public async Task EnsureFoundationAsync(CancellationToken ct = default)
    {
        foreach (var seed in DefaultAgents)
        {
            var existing = await db.BrainAgentProfiles.FirstOrDefaultAsync(x => x.AgentKey == seed.AgentKey, ct);
            if (existing is null)
            {
                db.BrainAgentProfiles.Add(new BrainAgentProfileEntity
                {
                    AgentKey = seed.AgentKey,
                    DisplayName = seed.DisplayName,
                    Purpose = seed.Purpose,
                    ArmScope = seed.ArmScope,
                    RuntimeKind = seed.RuntimeKind,
                    AutonomyLevel = seed.AutonomyLevel,
                    Enabled = true
                });
            }
        }

        foreach (var seed in DefaultCapabilities)
        {
            var existing = await db.BrainCapabilityGrants.FirstOrDefaultAsync(x => x.AgentKey == seed.Agent && x.CapabilityKey == seed.Capability, ct);
            if (existing is null)
            {
                db.BrainCapabilityGrants.Add(new BrainCapabilityGrantEntity
                {
                    AgentKey = seed.Agent,
                    CapabilityKey = seed.Capability,
                    AccessMode = seed.Mode,
                    RequiresHumanApproval = seed.Approval,
                    BoundaryNote = seed.Note
                });
            }
        }

        await db.SaveChangesAsync(ct);
    }

    public async Task<IReadOnlyList<BrainAgentProfileEntity>> GetAgentsAsync(CancellationToken ct = default)
        => await db.BrainAgentProfiles.AsNoTracking().OrderBy(x => x.DisplayName).ToListAsync(ct);

    public async Task<IReadOnlyList<BrainCapabilityGrantEntity>> GetCapabilitiesAsync(string? agentKey = null, CancellationToken ct = default)
    {
        var query = db.BrainCapabilityGrants.AsNoTracking().AsQueryable();
        if (!string.IsNullOrWhiteSpace(agentKey)) query = query.Where(x => x.AgentKey == agentKey);
        return await query.OrderBy(x => x.AgentKey).ThenBy(x => x.CapabilityKey).ToListAsync(ct);
    }

    public async Task<IReadOnlyList<BrainAuditEventEntity>> GetRecentAuditAsync(int take = 50, CancellationToken ct = default)
        => await db.BrainAuditEvents.AsNoTracking().OrderByDescending(x => x.OccurredAt).Take(Math.Clamp(take, 1, 200)).ToListAsync(ct);

    public async Task<BrainAuthorizationDecision> AuthorizeAsync(string agentKey, string capabilityKey, bool isConsequentialAction = false, CancellationToken ct = default)
    {
        var agent = await db.BrainAgentProfiles.AsNoTracking().FirstOrDefaultAsync(x => x.AgentKey == agentKey, ct);
        if (agent is null || !agent.Enabled) return new(false, false, "Agent is unknown or disabled.");

        var grant = await db.BrainCapabilityGrants.AsNoTracking().FirstOrDefaultAsync(x => x.AgentKey == agentKey && x.CapabilityKey == capabilityKey, ct);
        if (grant is null) return new(false, false, "No capability grant exists. Brain permissions are deny-by-default.");
        if (string.Equals(grant.AccessMode, "DENY", StringComparison.OrdinalIgnoreCase)) return new(false, grant.RequiresHumanApproval, grant.BoundaryNote ?? "Capability is denied.");
        if (isConsequentialAction && grant.RequiresHumanApproval) return new(false, true, grant.BoundaryNote ?? "Human approval is required.");
        return new(true, grant.RequiresHumanApproval, grant.BoundaryNote ?? "Capability allowed by Brain governance.");
    }

    public async Task RecordAuditAsync(string agentKey, string eventType, string targetArm, string actionKey, string outcome, string? rationale = null, string? correlationId = null, CancellationToken ct = default)
    {
        db.BrainAuditEvents.Add(new BrainAuditEventEntity
        {
            AgentKey = agentKey,
            EventType = eventType,
            TargetArm = targetArm,
            ActionKey = actionKey,
            Outcome = outcome,
            Rationale = rationale,
            CorrelationId = correlationId,
            OccurredAt = DateTimeOffset.UtcNow
        });
        await db.SaveChangesAsync(ct);
    }

    public BrainRuntimeStatus GetRuntimeStatus() => new(
        BrainCore: "ACTIVE",
        Orchestration: "GATEWAY CONTRACT ACTIVE — orchestration/runtime adapters are replaceable clients of Ancient Innovations governance.",
        AutonomousAgentRuntime: "GATEWAY ACTIVE — Hermes remains unconnected; when connected it receives tools only through the B3 controlled gateway.",
        ModelRouter: "B4 ROUTER ACTIVE — provider selection is replaceable; models receive governed context, never raw database credentials.",
        SourceOfTruth: "CONTROL APP + ARM-OWNED DATA");
}

/// <summary>Replaceable reasoning-model port. Implementations arrive after governance/memory are stable.</summary>
public interface IBrainModelRouter
{
    BrainModelStatus GetStatus();
    Task<string> ReasonAsync(string taskType, string systemContext, string userContext, CancellationToken ct = default);
    Task<string> RunContractTestAsync(CancellationToken ct = default);
}

/// <summary>Replaceable agent-runtime port. Hermes will implement this boundary for autonomous workers such as Scout.</summary>
public interface IBrainAgentRuntime
{
    Task<string> ExecuteAsync(string agentKey, string objective, CancellationToken ct = default);
}
