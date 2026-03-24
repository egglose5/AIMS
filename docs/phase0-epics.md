# Phase 0 — Epics & Initial issues

Epic 1: CI Baseline
- Add GitHub Actions workflow to run composer install and PHPUnit on PRs/push.
- Add caching for composer.
- Add phpstan/phpstan baseline (optional; allow failing to start then harden later).

Epic 2: Branch & PR strategy
- Decide naming convention (feature/*, fix/*, hotfix/*).
- Define merge rules (require review, passing CI, 1 approver).
- Add branch protection for main.

Epic 3: Product-spec & prioritized epics
- Add docs/product-spec.md with acceptance criteria for Phase 0.
- Add docs/phase0-epics.md (this file).

Epic 4: Quick developer setup
- Add scripts/setup-local.sh for quick dev setup (composer install + tests).
- Document typical dev workflow in docs/README-developer.md.

Optional Epic 5: Issue seeding
- Create the Phase 0 GitHub issues from this file for triage and assignment.
