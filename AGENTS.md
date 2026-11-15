# BuilderAd — working instructions

Start with these three documents:

1. [Governing architecture](docs/architecture/BUILDERAD_GOVERNING_ARCHITECTURE.md) is the sole product/design authority selected by the Owner. The adjacent HTML is a reading copy.
2. [Current implementation](docs/architecture/CURRENT_IMPLEMENTATION.md) maps actual owners, contracts, source revisions and evidence. Design direction is not proof of implementation.
3. [Engineering standards](docs/engineering/ENGINEERING_STANDARDS.md) maps technical protections and verification commands to their code/test owners. [README](README.md) covers setup and operation.

## Scope and existing work

- Use the smallest sufficient execution path. Define requested behavior, acceptance criteria, affected owners and authorization before editing. Historical mandates, plans and evidence records do not authorize work or choose its order.
- Inspect the actual worktree, branch, HEAD and staged/unstaged/untracked state. Preserve unrelated work and local verified corrections. Do not reset, clean, switch worktrees or create a branch automatically.
- Current task instructions determine Git/publication, service and data authorization. Never infer commit, push, PR, merge, deployment or shared-data permission from an old execution record.
- Change only the authorized surface. For material contract changes, inspect PHP, SQL, API and consumers; preserve fact ownership, isolation, rights, history and independent validation.
- Do not generate images or screenshots without Owner approval. Interaction tests without image files remain permitted.

## Verification and closure

- Select verification by affected behavior: content/links for documentation; focused positive/negative tests for local behavior; integration, security, concurrency, compatibility and architecture checks for affected shared boundaries. Use official guarded entry points and disposable databases.
- Do not run full Foundation after every edit or for documentation-only changes. Identify required final gates for the actual candidate; focused tests do not replace them. Hosted Foundation and browser evidence are separate.
- Reuse passing evidence only when relevant source, dependencies, configuration and test conditions still match. Distinguish `MAIN_VERIFIED`, `LOCAL_VERIFIED_UNMERGED`, `SOURCE_PRESENT_UNVERIFIED`, `NOT_IMPLEMENTED` and `UNKNOWN` as defined in Current implementation.
- For failures, establish the last relevant passing revision and comparable environment, localize the boundary, and test the leading explanation with a focused probe. Two unproductive diagnostic experiments require an evidence summary and the smallest useful next step before expanding investigation.
- Never weaken assertions, guards, authority, lock ordering or timeouts merely to get PASS. Stop only task-started processes and clean only task-owned temporary resources. Close when acceptance is met; report pending gates and evidence limits honestly.

## Conflicting documents or evidence

The governing Markdown owns design; code, executable contracts and matching tests own claims about current behavior. A mismatch is a specific reconciliation issue, not permission to erase valid security protection or silently redesign the product. Record the owning boundary and impact, resolve within task authorization, and obtain the applicable decision before sensitive authority or historical-contract changes.

Retained execution reports and the older executable-architecture record are scoped evidence only. Their instructions, acceptance states, capability inventories and proposed sequences are historical; follow the current task and the three entry documents above.
