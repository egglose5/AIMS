# AIMS — Phase 0 Product Spec (summary)

Goal
- Establish repository-level processes, continuous integration, and a concise prioritized epic list so development can proceed safely.

Phase 0 scope
- CI baseline (GitHub Actions) that installs dependencies and runs PHPUnit + static analysis.
- Product-spec short summary stored in docs/.
- Prioritized Phase 0 epics document and checklist to seed the backlog.
- Branch strategy and PR policy recommendation.

Deliverables
- .github/workflows/ci.yml
- docs/product-spec.md (this file)
- docs/phase0-epics.md
- Phase 0 checklist and scripts to run tests locally

Constraints & rules
- Do not modify core runtime or database schema in Phase 0.
- Keep changes small and reviewable; one PR for Phase 0 artifacts is preferred.
- CI must be safe and not publish secrets.

Acceptance criteria
- CI runs on PRs and shows PHPUnit result (pass or failing with documented reasons).
- Docs added under docs/ approved by product owner.
- Phase 0 branch created and PR opened for review.
