# BuilderAd — working instructions

Start with these three documents:

1. [Governing architecture](docs/architecture/BUILDERAD_GOVERNING_ARCHITECTURE.md) is the sole product/design authority selected by the Owner. The adjacent HTML is a reading copy.
2. [Current implementation](docs/architecture/CURRENT_IMPLEMENTATION.md) maps actual owners, contracts, source revisions and evidence. Design direction is not proof of implementation.
3. [Engineering standards](docs/engineering/ENGINEERING_STANDARDS.md) maps technical protections and verification commands to their code/test owners. [README](README.md) covers setup and operation.

## Commercial Value First — Mandatory Engineering Principle

For each commercial change, identify the advertiser's goal, the problem, the expected effect, the required evidence/capabilities/sources, the useful output, the decision or action it enables, and how its usefulness will be verified.

Use the value cycle appropriate to the task: goal → qualified evidence → diagnosis → alternatives → decision → authorized action → verification. A function need not implement every stage. Reuse existing fact owners, commands and rights. Preserve the distinction between user declarations, qualified evidence and observed commercial results; never invent business outcomes. Foundation work must enable real usage.

Acceptance must trace: **Commercial Goal → Qualified Inputs → Method/Capability → Useful Output → Enabled Decision/Action → Verification**. Apply this principle within existing engineering work, without adding a separate governance system.

### Complete Commercial Value Gate

Before implementing a new commercial value, define its commercial goal, the exact advertiser benefit, the required inputs and evidence with their sources and limits, the capabilities to reuse, and the missing capabilities required to deliver the benefit. Specify the result or judgment, the decision or action it enables, the full user journey including missing inputs and failure, and how to verify the benefit and measure its effect when the promise includes an effect. Start implementation once this scope can be designed, delivered and tested coherently.

Engineering may divide delivery into components and tasks. Approve and present the commercial value only when every capability required by the promised journey works together. If data, rights or technical prerequisites are unavailable, define a narrower benefit that can stand on its own or defer the value. Foundation work must support a specific real use.

Show advertiser-facing results, executable actions, information needed for decisions and prerequisites for available benefits. Place supporting administration in its relevant context with authorized access. Omit technical copy, simulated results and inactive actions presented as available work.

Assign `COMMERCIAL_VALUE_COMPLETE` only after a real end-to-end user journey verifies the result, permissions and failure cases. Match the evidence to the promise. A qualified insight needs evidence of its validity. A sales improvement claim requires evidence of the sales outcome and its attributable effect.

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
