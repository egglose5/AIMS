# Ancient Innovations Brain Core B3 — Controlled Agent & Tool Gateway

B3 establishes the permanent boundary between Ancient Innovations-owned intelligence and replaceable autonomous runtimes such as Hermes.

## Non-negotiable rule

An external runtime never receives database credentials and never calls Control App business services directly. It receives only a governed tool client.

Every tool request follows this order:

1. Runtime identifies its `AgentKey`.
2. Runtime requests a registered `ToolKey`.
3. Brain Gateway maps the tool to one `CapabilityKey` and target Arm.
4. Brain Core authorizes the agent/capability pair using deny-by-default grants.
5. `DENY` stops permanently before handler execution. A human cannot turn an absolute DENY into agent permission.
6. A future non-DENY capability marked human-gated creates a specific approval request.
7. Only after authorization (and any explicit approval) does the handler run.
8. Request, decision, result/failure, and correlation are written to the Brain execution/audit ledgers.

## B3 registered tools

- `scout.controlled-context` — small SHOW_ARM context slice for Scout.
- `show-brain.memory-context` — durable SHOW_ARM Brain memory for the Show Brain.
- `show-brain.propose-learning` — stages a lesson for review; it cannot create durable learning directly.
- `blocked.application-submit` — diagnostic denied tool whose handler must never be reached.
- `blocked.money-spend` — diagnostic denied tool whose handler must never be reached.

## Human approval semantics

`DENY` means the agent cannot perform the action. Human approval does not override DENY. The approval queue exists for future capabilities that are allowed in principle but require approval for a specific execution.

## Runtime neutrality

`IBrainRuntimeGatewayAdapter` is the adapter contract. B3 ships only a local contract-test adapter. Hermes and Microsoft Agent Framework remain unconnected until later passes. This keeps the Brain independent of either runtime.
