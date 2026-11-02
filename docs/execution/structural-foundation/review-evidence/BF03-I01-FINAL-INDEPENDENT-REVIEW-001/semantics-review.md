# BF03-I01 independent semantic and API-contract subreview

Date: 2026-11-01. Scope: Track B and relevant generated API contracts. This is a same-model delegated review, not a distinct-provider assessment. No BF03 implementation participation is claimed. Root owns the full candidate manifest, evidence reconciliation, and final disposition.

## Result

**One MATERIAL semantic-contract finding: SEM-001.** The implementation preserves assertion assurance, numbers/nulls, revision bytes, and issuer attribution at the inspected boundaries, but it cannot preserve the actual meaning of an operator-defined acceptance criterion. This is a present declaration/readback defect, not a demand to implement Case A or a universal criterion registry.

No authority-bypass finding was substantiated by this semantic subreview; detailed authority qualification and database execution belong to the parallel security review. No HTTP/SQL runtime was executed here.

## SEM-001 — Preserve the criterion definition, not only its version number

**Severity:** MATERIAL.

**Affected locations:**

- `database/sql/inquiry_evidence/validation.sql:45`–48: the exact acceptance key set has count, pending count, criterion code/version, cutoff, maturity, and coverage only; custom definitions/references are rejected.
- `scripts/inquiry-evidence-openapi.mjs:12`: the generated contract has the same omission.
- `resources/js/inquiry-evidence/InquiryEvidencePanel.vue:145`,149,161: the form claims a stated criterion but captures only a generic `operator_defined` option and numeric version.
- `resources/js/inquiry-evidence/DeclarationDetails.vue:35`–36: readback can show only “Business-defined criterion” and a version counter.

**Required contract:** `NEXT_IMPLEMENTATION_PACKAGE.md:42` includes accepted count plus criterion/ref/version and stable cohort semantics. K02 (§8.1) requires preserved historical criterion meaning rather than silent latest-criterion substitution. The Owner authorization attachment `541193bb-a684-4b7f-b4c9-aa1e66e8bc3d/Pasted text.txt:85` requires preserved cohort semantics and criterion revisions; line106 adopts K01–K07 as binding design/test inputs.

**Scenario and observed source/schema behavior:** A creator records 10 accepted inquiries under a private rule “within service area AND budget at least SAR500,” choosing the default `operator_defined`, version1. There is no field or resolvable immutable reference through which that rule can be saved. Later the creator changes the rule to “budget at least SAR1000,” version2. Current/history readback preserves version numbers and count snapshots but cannot recover either rule. Distinct actual rules have identical permitted representations apart from an uninterpretable counter. Attempting to include the rule or a criterion reference fails the generated schema's closed-property contract; inspected SQL has the same exact-key restriction. No resolver or stored criterion definition exists in the BF03 source envelope examined.

**Independent check:** `semantics-contract-probe.mjs` compiles the actual generated `InquiryDeclarationValue` schema using the installed Ajv. An ordinary operator-defined payload validates, while addition of `criterion_definition` or `criterion_ref` is rejected as an additional property. Output: `semantics-contract-probe.json`. This establishes representational inability, not a new HTTP/SQL execution result. The full production envelope source search and SQL inspection establish that this is not merely an omitted presentation field.

**Impact:** The current authorized user cannot record or later understand what “accepted” meant for their own versioned declaration. Append-only bytes cannot preserve meaning that the entry contract never admitted. A future consumer should withhold unsupported analysis, but that does not satisfy the current record/readback contract's requirement to retain the criterion. The code does not currently miscalculate Case A or claim verified outcomes; those stronger defects are not alleged.

**Smallest correction boundary:** Admit a bounded non-PII criterion description or stable reference to an immutable definition, scoped to the declared Business/issuer and retained with the exact revision. Require a usable definition for `operator_defined`, and expose it in current/history readback. Update the closed SQL validation, generated API, form and details consistently. A small versioned snapshot is sufficient; no shared registry, new authority model, external fetch, or analytical consumer is necessary. Existing unsupported records need an honest “definition unavailable” handling choice rather than fabricated retroactive meaning.

**Proof of correction:** Record two genuinely different custom rules; read current and history after a rule/version correction and verify each original definition/reference resolves to its exact immutable meaning. Confirm unknown legacy definition is not presented as known, extra commercial/PII fields remain rejected, and stale-revision/replay behavior is unchanged. Browser proof should show an Arabic user can state the criterion and recover its historical meaning.

**Related bounded observation:** Cohort `definition_version` similarly has no descriptive definition/ref beyond channel/country and fixed population/counting policy. The same probe rejects a cohort definition. This strengthens the need to reconcile `NEXT_IMPLEMENTATION_PACKAGE.md:42`, but this subreview does not assert that arbitrary cohort modeling must be added: a genuinely fixed, documented cohort definition could be sufficient. SEM-001 rests on the explicitly supported custom criterion, for which a fixed definition cannot supply the missing meaning.

## K01–K07 implementation trace and counterexamples

| Result | Actual inspected implementation | Discriminating counterexample / supported conclusion |
| --- | --- | --- |
| K01 inquiry, delivery, and qualification are distinct | Closed top-level/value shape in `validation.sql:13`–18; fixed `recorded_inquiries` and `distinct_inquiries`; receipt/read assurance constants in `declarations.sql:36`,71; human wording in Panel/Details. | Add `verified`, customer phone, revenue, or a new truth-kind field: exact-key source checks reject these. Inspected admission test variants cover these cases. A dishonest human can still assert a false number; the implementation correctly labels it unverified rather than claiming real-world verification. |
| K02 criterion/version/maturity/history | SQL stores whole value per append-only revision; stale expected revision conflicts at `declarations.sql:21`; acceptance cutoff/count/maturity constraints at `validation.sql:45`–55. | Counter-only versioning cannot recover a custom criterion definition: **SEM-001**. Otherwise the inspected history path reads stored revision values rather than substituting current values. |
| K03 outcome authority separate | No supported sale/payment/revenue/profit/customer-content payload fields; fixed source-assurance labels. | Adding `revenue` is rejected; no sale or profit value is computed in the inspected module. Goal labels do not become outcome evidence. |
| K04 independent rights and source optionality | `InquiryEvidence` invokes separate SQL commands; write selects record vs correct, read independently requires read at `declarations.sql:10`–11,47,70. Missing cost/inquiry/acceptance are nullable independently. | Acceptance-only known zero with cost/inquiries null remains representable; admission test explicitly asserts null/null/0. No CRM/provider/account/goal dependency appears in this source path. Authority enforcement execution is deferred to the security subreview. |
| K05 assessment/decision/effect separation | Module and SQL perform custody/readback only; Details explains no acquisition-cost/channel/budget conclusion. | A saved assertion does not expose a calculator, recommendation, messaging action, or shared-market publication. No requirement to implement these future capabilities is inferred. |
| K06 legitimate source routes | Human assertion path, original/correcting actor attribution, fixed SOURCE_ASSERTED/false assurance, no transport dependency in `InquiryEvidence`. | `channel_kind=crm` labels the operator's declaration; it cannot supply a verified-CRM flag or trigger source fetch. Future adapters require separate accepted source/rights semantics; this module's present limits are not a permanent ban on them. |
| K07 aggregate/history disclosure | Read scopes rows to actual Business and issuer, authorizes before read and again before return; receipts contain identifiers/state/assurance but no aggregate value (`declarations.sql:35`–36). | A known declaration ID or write-only action is not a content grant in inspected source. Cross-actor/Business, history and post-revocation cases are represented in admission tests; this subreview does not independently execute them or invent a universal k threshold. |

## Generated API contract assessment

The OpenAPI input shape is closed, keeps nullable axes and exact decimal cost strings, constrains counts to 0..10^12, uses explicit precision0..8, and fixes assurance/readback mode in outputs. The generated API carries the same custom-criterion omission as its generator; this is not generator drift. Controller outer-key checking and SQL exact-key checking preserve a closed payload rather than accepting an arbitrary JSON escape hatch.

Some OpenAPI syntactic constraints are looser than authoritative validation (for example positive definition/criterion versions have no schema maximum, whereas SQL permits at most9digits; date/timezone text is only structurally string-typed in OpenAPI). No material behavioral failure is asserted from these differences in this review; full contract validators must not be treated as a complete semantic authorization or SQL-validation substitute.

## Sources inspected and checks

Read directly: review assignment; NEXT_IMPLEMENTATION_PACKAGE; CASE_A_CONTRACT relevant accepted distinctions; both BF03 Owner authorization attachments identified by BF03_IMPLEMENTATION; app/Commercial/InquiryEvidence.php; InquiryEvidenceController.php; inquiry_evidence schema/validation/declarations SQL; inquiry-evidence-openapi generator; actual generated InquiryDeclarationValue schema; inquiry-evidence API/editor/Details and relevant Panel/form passages; InquiryEvidenceFixture; admission test implementation; relevant JS test definitions and progress/surface wording.

Reused the approved §8.1 K01–K07 source summaries for this bounded custody contract. Did not claim a fresh review of the full original research archive or validate historical test outcomes from assertions in progress prose. Root handles original logs, manifest equivalence and the complete gate record.

Executed one read-only generated-schema probe (exit0); no PHP suite, live database, browser, Foundation, build, application edits, or process server. Only this subreview and its two probe artifacts were written. No long-running processes were started.
