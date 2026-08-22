# Research MCP Phase 2: Scout Capability Audit and MCP Tool Design

## Scope

This document audits the current Scout implementation and translates it into a future Research MCP architecture.

This is a design artifact only.

- No Research MCP tools are implemented here.
- No Control App code is modified here.
- No Scout code is modified here.
- No new database is introduced here.

The blank Research MCP foundation already exists at `/Research-MCP` and remains intentionally separate from the Control App.

## Sources Audited

Primary implementation sources reviewed:

- `Mustaine-AI/Services/ScoutDiscoveryService.cs`
- `Mustaine-AI/Services/ScoutResearchService.cs`
- `Mustaine-AI/Services/ScoutIntegrationService.cs`
- `Mustaine-AI/Services/ShowWebResearchService.cs`
- `Mustaine-AI/Components/Pages/ScoutDiscovery.razor`
- `.scout-s221-backup-20260819-150600/Pages/ScoutResearch.razor`
- `Mustaine-AI/Data/ShowArmEntities.cs`
- `Mustaine-AI/Data/ShowArmModelConfiguration.cs`
- `HYBRID-ARCHITECTURE.md`

## Executive Summary

Scout is not a single capability. It is currently a bundle of five different concerns:

1. Discovery orchestration for finding possible shows.
2. Evidence gathering from official pages, application platforms, directories, and independent sources.
3. Lightweight extraction and heuristic scoring.
4. Persistence into Show Arm and Scout-specific tables.
5. UI workflow for a human to move leads through phases.

The Research MCP should not absorb all five concerns.

The clean split is:

- Research MCP should own retrieval, extraction, evidence packaging, document inspection, and reusable fact-finding capabilities.
- Lynks or the Show Arm should remain the canonical owner of show records, applications, assignments, maps, operational history, and historical business outcomes.
- Mercury should own reasoning, prioritization, conflict resolution, recommendations, approval thresholds, monitoring schedules, and business actions.
- Scout-specific UI harnessing, queued phase status rows, and direct write-through into Show Arm become mostly obsolete once Mercury calls Research MCP directly.

## What Scout Currently Does

### Discovery

Scout discovery is implemented as a broad, date-blind search and crawl system.

Observed behaviors:

- Runs many search lanes concurrently.
- Searches broad open-web terms plus known application and directory domains.
- Seeds deterministic state directory pages.
- Crawls source pages to extract event-detail links.
- Performs state-specific recovery when a primary source yields zero accepted links.
- Suppresses canonical known shows and previously staged duplicates.
- Stages overflow into queue status rather than dropping leads.
- Stores leads in `ShowDiscoveryLeads`.

Important current characteristics:

- Discovery is intentionally broad, not authoritative.
- Discovery results are treated as leads, not facts.
- Discovery is UI-driven today, but the capability itself is reusable.

### Research and Evidence Collection

Scout research currently performs:

- Fetching the discovery source.
- Extracting linked pages from the discovered source.
- Running gap searches for missing evidence.
- Pulling evidence from official and independent domains.
- Recording fetched vs failed sources.
- Building summaries of verified facts and missing evidence.
- Calculating heuristic confidence and recommendation.

Observed source categories:

- Official event pages
- Vendor or exhibitor pages
- Application pages
- Rules, fee, packet, and FAQ pages
- Eventeny
- ZAPP
- Independent directories
- Community sources such as Facebook and Reddit

Current Scout research already distinguishes:

- official evidence
- independent evidence
- missing evidence
- multi-domain cross-checking

### Existing Show Arm Research Collection

`ShowWebResearchService` overlaps with Scout and adds another important behavior:

- Collects public-web evidence into `ShowResearchEvidence`
- Captures application platform links into `ShowApplications`
- Pulls Ancient Innovations internal history from `ShowNotes`
- Pulls historical calibration/outcome records from `ShowCalibrationRecords`

This matters because it shows the intended long-term model:

- raw research retrieval is one concern
- canonical storage of research evidence is another concern
- operational application records are another concern
- owner-known historical outcomes are another concern

### Integration Boundary

`ScoutIntegrationService` is the clearest statement of the current boundary.

It already assumes Scout is not trusted to freely mutate Control App data.

Observed boundary features:

- explicit schema for `scout_show_links`
- explicit schema for `scout_fact_changes`
- explicit schema for `scout_documents`
- allowlist of writeable factual fields
- explicit protected operational fields that Scout cannot update

That design direction is correct and should be preserved conceptually, but the write path should move from Scout-specific integration tables toward Mercury-mediated use of Research MCP outputs.

## Capability Classification

Each capability below is classified as:

- `MCP TOOL`
- `RESEARCH LOGIC`
- `PERSISTENT DATA`
- `DOMAIN DATA`
- `BRAIN RESPONSIBILITY`
- `OBSOLETE`

### Show discovery

- Classification: `MCP TOOL`
- Why: Discovery is reusable and can be called by Mercury or another client to find possible events without directly mutating the canonical system.

### Broad show search

- Classification: `RESEARCH LOGIC`
- Why: Search lane selection, source seeding, dedupe, and geography expansion are internal mechanics behind discovery tools.

### Date-independent discovery

- Classification: `RESEARCH LOGIC`
- Why: This is a design rule for discovery, not a separately useful tool.

### Event matching

- Classification: `RESEARCH LOGIC`
- Why: Matching titles, URLs, dates, and locations is a reusable internal function used by discovery and deeper research tools.

### Show aliases

- Classification: `DOMAIN DATA`
- Why: Alias truth belongs with canonical show identity in Lynks or the Show Arm, even if Research MCP can suggest aliases as evidence.

### Organizer identification

- Classification: `MCP TOOL`
- Why: Identifying likely organizer information from sources is reusable and externally callable.

### Event research

- Classification: `MCP TOOL`
- Why: A focused event research call is a natural top-level MCP operation.

### Official website research

- Classification: `RESEARCH LOGIC`
- Why: This is a retrieval and extraction mode used inside research tools, not a separate business-facing endpoint.

### Eventeny

- Classification: `RESEARCH LOGIC`
- Why: Eventeny is a source adapter or source family inside retrieval and extraction workflows.

### ZAPP

- Classification: `RESEARCH LOGIC`
- Why: Same as Eventeny. It is a source-specific adapter, not the business boundary.

### External or community evidence

- Classification: `RESEARCH LOGIC`
- Why: This is an evidence-source category used inside broader research tools.

### Historical show evidence

- Classification: `PERSISTENT DATA`
- Why: Historical business knowledge and previously validated records belong outside the MCP runtime. Research MCP can read or be given historical context later, but should not become the canonical store.

### Application information

- Classification: `DOMAIN DATA`
- Why: The canonical application record belongs in Lynks or the Show Arm. Research MCP can discover application facts and evidence, but not own the business application object.

### Deadlines

- Classification: `DOMAIN DATA`
- Why: Deadlines become canonical operational facts once accepted; MCP should return evidence and candidate values.

### Booth fees

- Classification: `DOMAIN DATA`
- Why: Same pattern as deadlines.

### Jury fees

- Classification: `DOMAIN DATA`
- Why: Same pattern as deadlines.

### Event dates

- Classification: `DOMAIN DATA`
- Why: Event dates ultimately belong on canonical show editions.

### Vendor packets

- Classification: `MCP TOOL`
- Why: Discovering, retrieving, and extracting vendor-packet information is a reusable research capability.

### Maps

- Classification: `MCP TOOL`
- Why: Discovering and retrieving maps or map-like documents is a reusable research capability, though canonical storage remains elsewhere.

### Load-in information

- Classification: `MCP TOOL`
- Why: Retrieving and extracting logistics from documents or event pages is reusable.

### Cancellation policies

- Classification: `MCP TOOL`
- Why: Policy extraction is reusable research output.

### Organizer contacts

- Classification: `MCP TOOL`
- Why: Contact discovery and extraction is reusable research output.

### PDF or document research

- Classification: `MCP TOOL`
- Why: Document retrieval and extraction is a reusable foundation capability.

### Evidence collection

- Classification: `RESEARCH LOGIC`
- Why: Evidence assembly is the internal substrate of most research tools, though outputs should expose the evidence.

### Confidence scoring

- Classification: split between `RESEARCH LOGIC` and `BRAIN RESPONSIBILITY`
- Why: retrieval-confidence and extraction-confidence can be deterministic MCP-side; decision confidence and recommendation confidence belong to Mercury.

### Show scoring

- Classification: split between `RESEARCH LOGIC`, `DOMAIN DATA`, and `BRAIN RESPONSIBILITY`
- Why: deterministic factual subscores may be computed from facts, but recommendation, apply/pass judgment, and business weighting belong to Mercury and Lynks.

### Monitoring

- Classification: split between `MCP TOOL` and `BRAIN RESPONSIBILITY`
- Why: diffing current observed facts against prior observed facts is a research capability; deciding when to run checks and what to do about changes belongs to Mercury.

### Change detection

- Classification: `MCP TOOL`
- Why: comparing observed evidence snapshots is reusable and deterministic enough to expose as a capability.

### Historical comparison

- Classification: split between `MCP TOOL` and `PERSISTENT DATA`
- Why: the comparison logic can live in MCP, but the historical record should remain outside it.

### Scout phase queue UI

- Classification: `OBSOLETE`
- Why: once Mercury calls MCP directly, Scout-specific staged statuses like `SCOUT_NEW`, `SCOUT_ACCEPTED`, and `SCOUT_QUEUED` are harness concerns rather than enduring architecture.

### Scout-specific integration tables

- Classification: mostly `OBSOLETE`
- Why: they were a safe bridge for Scout writing into Control App data, but a cleaner future state is Research MCP returns evidence and Mercury decides what to persist canonically.

## Proposed MCP Tool Boundary

The first tool set should be compact and capability-oriented.

It should not expose one huge `scout_everything` tool.

It should also avoid over-fragmenting simple retrieval into dozens of tiny public tools.

### 1. `discover_shows`

- Purpose: find candidate events for a geography, season, and optional vendor context
- Inputs: region, target year, optional target month, optional vendor profile context, search budget options
- Outputs: candidate show leads with title, URL, snippet, source family, location hints, year signal, duplicate hints
- Returns evidence: yes, lead provenance and source path
- Deterministic or reasoning-based: mostly deterministic
- External sources required: web search, directories, event pages, application platforms
- Needs persistence: no
- Future caller: Mercury

### 2. `resolve_show_identity`

- Purpose: determine whether multiple names, URLs, or pages refer to the same underlying event
- Inputs: candidate title, URLs, optional known canonical record context, optional aliases, optional year
- Outputs: normalized identity proposal, alias list, organizer clues, location clues, match confidence, conflicts
- Returns evidence: yes
- Deterministic or reasoning-based: mostly deterministic with possible later model assistance
- External sources required: pages supplied or fetched by supporting logic
- Needs persistence: no
- Future caller: Mercury, Lynks migration or review workflows

### 3. `research_show`

- Purpose: gather multi-source evidence about one event edition or candidate show
- Inputs: title, canonical URL or lead URL, optional year, optional location, optional known aliases, optional research focus flags
- Outputs: structured facts, missing facts, evidence list, source summary, conflict summary, retrieval summary
- Returns evidence: yes
- Deterministic or reasoning-based: deterministic retrieval and extraction first; optional later reasoning pass should be separate
- External sources required: official pages, application platforms, independent/community pages
- Needs persistence: no
- Future caller: Mercury

### 4. `discover_documents`

- Purpose: find documents relevant to an event such as vendor packets, maps, rules, PDFs, and applications
- Inputs: show identity, optional year, document type filters
- Outputs: document candidates with source URL, title, type guess, publisher guess, provenance
- Returns evidence: yes
- Deterministic or reasoning-based: deterministic
- External sources required: web pages and linked documents
- Needs persistence: no
- Future caller: Mercury, later Lynks workflows

### 5. `inspect_document`

- Purpose: retrieve and inspect a single document or file-like source
- Inputs: document URL or file reference, expected document type, optional extraction focus
- Outputs: extracted text, extracted facts, useful sections, provenance, file metadata, conflicts or unreadable sections
- Returns evidence: yes
- Deterministic or reasoning-based: deterministic extraction with optional later model-assisted field extraction
- External sources required: document source
- Needs persistence: no
- Future caller: Mercury

### 6. `observe_show_changes`

- Purpose: compare fresh observations against prior supplied observations and identify changes
- Inputs: show identity, prior observed fact set or prior evidence snapshot, monitoring focus flags
- Outputs: changed facts, unchanged facts, new documents, missing documents, confidence, conflict list
- Returns evidence: yes
- Deterministic or reasoning-based: deterministic
- External sources required: same families as `research_show`
- Needs persistence: no
- Future caller: Mercury scheduled tasks

### 7. `collect_application_facts`

- Purpose: focus specifically on application pathways, fees, deadlines, and platform links
- Inputs: show identity, optional year, optional platform hints
- Outputs: application URL candidates, platform detections, date candidates, fee candidates, contact or submission notes
- Returns evidence: yes
- Deterministic or reasoning-based: deterministic
- External sources required: official pages, Eventeny, ZAPP, other application platforms
- Needs persistence: no
- Future caller: Mercury, later Lynks review workflows

## Tool Composition

The public tools above should be composed internally from smaller reusable capabilities.

Internal building blocks inferred from Scout:

- search source selection
- fetch page
- crawl source seed
- extract candidate links
- normalize URL
- normalize title
- detect year signal
- classify source family
- match event identity
- gather official evidence
- gather independent evidence
- gather application-platform evidence
- discover documents
- fetch document
- extract document text
- extract structured facts
- detect conflicting facts
- compare with prior snapshot

Recommended composition:

### `discover_shows`

Composes:

- search planning
- source seeding
- crawl and link extraction
- duplicate suppression
- candidate qualification

### `research_show`

Composes:

- identity resolution
- official source discovery
- related-page crawling
- gap search
- external evidence gathering
- fact extraction
- conflict detection
- evidence assembly

### `discover_documents`

Composes:

- related-page crawl
- link classification
- document candidate detection

### `inspect_document`

Composes:

- download
- fingerprint
- text extraction
- section extraction
- fact extraction
- provenance assembly

### `observe_show_changes`

Composes:

- fresh `research_show` or `collect_application_facts`
- prior snapshot comparison
- change classification

## Evidence Model

Research MCP responses should return evidence explicitly rather than burying it in narrative summaries.

Proposed evidence record:

- `source_type`
- `source_name`
- `source_url`
- `retrieved_at`
- `published_at` when available
- `document_id` or content fingerprint when applicable
- `fact_type`
- `fact_value`
- `fact_value_raw`
- `excerpt`
- `confidence`
- `conflict_group`
- `provenance_chain`
- `is_official`
- `is_independent`

Proposed result structure:

- `facts`
- `missing_facts`
- `conflicting_facts`
- `evidence`
- `documents`
- `retrieval_summary`
- `identity_summary`

What MCP should return:

- raw and normalized facts
- provenance
- conflicts
- extraction confidence
- enough metadata for Mercury to reason about reliability

What Control App or Lynks should eventually store:

- accepted canonical field values
- approved application record
- approved map or packet attachments
- historical operational outcomes
- business notes and decisions

## Historical Information

Historical evidence is a major Scout requirement, but it should not cause Research MCP to become the historical system of record.

Keep outside MCP:

- `ShowCalibrationRecords`
- `ShowNotes`
- canonical prior show editions
- operational outcomes
- vendor relationship history

Research MCP should eventually support one of two patterns:

1. Mercury passes historical context into a research call.
2. Mercury or Lynks asks MCP to compare fresh observations against canonical historical context it already owns.

Recommended rule:

- Research MCP may compare against provided historical context.
- Research MCP should not become the permanent owner of historical business memory.

## PDF and Document Research Design

Required future MCP capabilities inferred from Scout requirements:

- discover linked PDFs and document-like files
- retrieve a document
- fingerprint the document
- preserve source URL and retrieval time
- extract text
- identify sections such as rules, fees, deadlines, maps, setup, and cancellation
- emit extracted facts with provenance
- report unreadable or ambiguous sections

Document processing should stay separate from canonical storage.

The likely future split:

- `discover_documents`
- `inspect_document`

Potential internal document source families:

- official site PDFs
- vendor packets
- maps
- rules PDFs
- platform attachments

## Monitoring Design

Monitoring should not be a long-running responsibility inside Research MCP.

Recommended split:

- Research MCP owns observation and diff capabilities.
- Mercury owns scheduling, repetition, escalation, and decision-making.
- Lynks stores the accepted canonical prior state and any approved follow-up tasks.

Recommended future pattern:

1. Mercury schedules a check.
2. Mercury calls `observe_show_changes`.
3. Research MCP returns changed facts and evidence.
4. Mercury decides whether to update Lynks, alert someone, or take action.

This keeps the MCP server stateless and reusable.

## Scoring Design

Scout currently mixes three kinds of scoring:

- retrieval confidence
- factual or heuristic fit scores
- recommendation thresholds

These should be separated.

What can stay deterministic in or near MCP:

- source coverage count
- official versus independent source coverage
- conflict count
- document presence
- extracted fee/date presence
- field completeness

What may exist as optional analytical output but should not decide the business:

- fit subscore suggestions
- evidence completeness
- extraction confidence

What belongs to Mercury:

- whether the evidence is sufficient for a recommendation
- whether the show is worth applying to
- how historical performance changes the recommendation
- prioritization across multiple shows
- conflict resolution when evidence disagrees

What belongs to Lynks:

- durable business rules and accepted canonical state
- vendor-specific preferences if they are part of the domain model

## Data Ownership Boundary

### Research MCP

Owns:

- temporary research execution
- source retrieval
- source classification
- evidence gathering
- document processing
- fact extraction
- diffing observed facts

Does not own:

- canonical show record
- application record
- assignment or vendor relationship
- historical business outcome record
- owner recommendations or actions

### Lynks or Show Arm

Owns:

- canonical show records
- show aliases once accepted
- show editions
- application objects
- operational history
- assignments
- vendor relationships
- maps and accepted documents
- approved deadlines, fees, and dates
- historical records

### Mercury

Owns:

- reasoning
- prioritization
- recommendation generation
- decision thresholds
- conflict resolution
- monitoring schedules
- follow-up actions
- approvals

### Other domain systems

Per the phase request, preserve existing boundaries:

- Stash, Dynamo, Dash, Midas, Picasso, and Sprout keep their own domain responsibilities.
- Research MCP should be a reusable layer, not a new owner of their business data.

## Reuse vs Obsolescence

### Existing Scout code likely reusable

- URL normalization
- title normalization
- source-family classification
- deterministic source seeding
- page crawling and event-link extraction
- gap-search pattern
- evidence-source packaging
- year-signal detection
- lightweight conflict heuristics
- application-platform detection

### Existing Scout code reusable with refactoring

- research scoring internals, only as optional analytical helpers
- `ScoutIntegrationService` allowlist concepts
- document registration concepts

### Existing Scout infrastructure likely obsolete

- Scout-specific UI phases as enduring architecture
- direct Scout write-through into Show Arm as the primary integration model
- Scout-specific persistence tables as the long-term coordination mechanism
- Scout as a separate research application or harness

## Recommended Phase 3

The next implementation phase should still stay narrow.

Recommended order:

1. Add a design doc for the MCP JSON schemas for the first tool set.
2. Implement only the lowest-level reusable capabilities first, likely document inspection and evidence packaging helpers.
3. Implement one public tool first, likely `research_show` or `discover_shows`.
4. Verify the tool using the same MCP handshake and client approach used in Phase 1.
5. Only after tool outputs stabilize, design Mercury-side callers.

## Final Position

Scout's useful legacy is not its UI harness or its phase queues.

Scout's useful legacy is the specification it revealed:

- discovery must be broad and date-tolerant
- evidence must preserve provenance
- official and independent sources must both matter
- documents matter
- historical context matters
- recommendations must not be confused with facts

The Research MCP should become the reusable evidence-and-retrieval layer.
Mercury should become the reasoning and orchestration layer.
Lynks should remain the canonical operational memory.
