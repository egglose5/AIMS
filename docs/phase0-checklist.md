# Phase 0 Checklist

1. Create branch: phase0/bootstrap
2. Add .github/workflows/ci.yml (see repo file)
3. Add docs/product-spec.md and docs/phase0-epics.md
4. Add scripts/setup-local.sh
5. Open PR from phase0/bootstrap -> main:
   - Title: "Phase 0: CI baseline & docs"
   - Description: include summary and checklist
6. Wait for CI to run on PR; fix any blocker dependencies in small follow-up commits.
7. Once CI passes, request review and merge.
8. Create the minimal GitHub issues (from docs/phase0-epics.md) and assign owners.

Other constraints:
- Do not change runtime code or DB schema in this PR.
- Keep commit history minimal: create a single commit with message: "Add Phase 0 docs and setup script"

Deliverable (PR):
- Create a branch: phase0/docs (from main)
- Add the four files above
- Commit with message: "Add Phase 0 docs and setup script"
- Open a pull request titled: "Phase 0: Add docs & developer setup" with body explaining these files are Phase 0 documentation and developer setup scripts to follow the CI baseline PR.
