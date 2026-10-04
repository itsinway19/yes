# OWNER-APPROVED PRODUCT GOVERNANCE

**NOT CURRENT FEATURE IMPLEMENTATION**

This file is the single canonical authority for the Owner-approved Core Execution governance, its Market Evidence extension and the External Evidence Providers supplement, supplied on 2026-10-04. These verbatim directives govern future product direction and do not authorize expansion of the current approved DTM implementation. Market Evidence extends the existing Core governance; the provider supplement extends that evidence discipline. Neither replaces Core governance or authorizes provider integration, ingestion or new frameworks now.

BUILDERAD — OWNER PRODUCT GOVERNANCE DIRECTIVE
CORE EXECUTION SYSTEM BEFORE INTEGRATION EXPANSION

==================================================
0. OWNER DECISION
==================================================

This directive is APPROVED.

Its purpose is to establish a durable product/architecture sequencing rule for
Builderad.

It is a GOVERNANCE DIRECTIVE.

It is NOT authorization to expand the currently executing implementation scope.

==================================================
1. CURRENT EXECUTION MUST NOT CHANGE
==================================================

The currently authorized work remains:

1. execute and prove the already-approved:

   BOUNDED_FOUNDATION_LOCK_ORDER_REPAIR

2. if that repair passes its required gate:

   resume the already-approved Declared Target Markets implementation
   from the earliest incomplete task and continue through Tasks 2–13.

Do NOT pause or restart the current work because of this directive.

Do NOT reopen:

- DTM product semantics
- DTM specification
- DTM implementation plan
- Business Context ownership
- Workspace identity
- current authority semantics
- shared business_context_version semantics
- accepted concurrency semantics
- accepted DTM UX contract

Do NOT begin implementing because of this directive:

- Goal / Current Intent
- Targeting system
- Spend system
- Outcome system
- Decision Synthesis
- Execution Engine
- Learning system
- Salla
- Zid
- Shopify
- CRM integrations
- Builderad SDK
- any speculative future framework

This directive governs subsequent product work.

It does not enlarge the current DTM execution authorization.

After recording this governance directive, immediately return to the current
authorized lock-order repair / DTM execution.

Do not request another Owner approval merely to continue the already-approved
current work.

==================================================
2. PRODUCT DIRECTION
==================================================

Builderad must be built from the inside out.

Builderad is intended to become a continuously evolving system that helps an
advertiser improve advertising execution and results over time.

The product must not be architected around:

- a specific advertising provider
- a commerce platform
- an SDK
- a CRM
- campaigns as the product center
- dashboards as the product center
- any external API representation

External systems are evidence sources and/or execution destinations.

They must not own Builderad's product semantics.

The long-term product loop is conceptually:

ADVERTISER INTENT
→ OBSERVE REALITY
→ REASON
→ SYNTHESIZE
→ DECIDE
→ EXECUTE
→ OBSERVE EFFECT
→ VERIFY
→ LEARN
→ IMPROVE THE NEXT EXECUTION

This does NOT mean all of those capabilities must be implemented now.

==================================================
3. CORE-FIRST RULE
==================================================

Before substantial expansion into new business/commerce integrations such as:

- Salla
- Zid
- Shopify
- CRM systems
- custom tracking integrations
- Builderad SDK
- similar future systems

Builderad must first establish a usable Core Execution System.

This does NOT mean:

- development stops afterward
- every future capability is finished
- every future Decision Domain exists
- Targeting is permanently complete
- Spend is permanently complete
- every future advertiser use case is known

It means Builderad has a stable product spine into which future capabilities
and integrations can be added coherently.

--------------------------------------------------
CORE-FIRST SCOPE CLARIFICATION
--------------------------------------------------

This rule governs NEW integration expansion.

It does NOT revoke, delay or reinterpret any already-approved provider
connectivity or evidence path that is part of Builderad's existing product /
release scope or required to make the Core Execution System actually usable.

In particular:

CORE-FIRST must not be interpreted as:

"build the Core without the provider evidence required by the Core."

The intended sequencing is:

BUILD THE BUILDERAD PRODUCT CORE
using already-approved required provider evidence/connectivity where needed

THEN

expand into additional business/commerce/custom-tracking integrations such as:

- Salla
- Zid
- Shopify
- CRM systems
- Builderad SDK
- similar new external systems

Do not use this clarification to start any currently unauthorized integration
work.

==================================================
4. CORE EXECUTION SYSTEM GATE
==================================================

Integration expansion becomes appropriate only after the following product
contracts exist in usable form.

1. BUSINESS / DECISION CONTEXT

Builderad has canonical ownership for business facts that materially affect
decision interpretation.

2. CURRENT INTENT / GOAL

Builderad can represent what the advertiser is currently trying to achieve.

Advertiser intent must remain distinct from:

- provider configuration
- observed performance
- inferred opportunity
- historical behavior

3. CANONICAL OUTCOME MEANING

Builderad can represent the business result that actually matters.

Provider metrics such as clicks, impressions or platform conversions do not
automatically define business success.

4. SHARED CANONICAL REALITY

A shared fact has one semantic owner.

Do not duplicate canonical truths separately inside Targeting, Spend,
providers or future domains.

5. FIRST REAL DECISION DOMAINS

For the currently intended first Core slice, Targeting and Spend are the
minimum initial Decision Domains expected to demonstrate the model.

This is a CURRENT PRODUCT SEQUENCING DECISION.

It is NOT:

- a permanent taxonomy
- a claim that Targeting and Spend are always the two most important domains
- a prohibition on evidence later showing that another domain should be added
  or prioritized

The gate requires enough real domain behavior to prove the shared
decision/execution architecture.

It does not freeze Builderad's future domain structure.

6. DECISION SYNTHESIS

Builderad can combine evidence across domains into coherent prioritized
decisions.

Do not force the advertiser to reconcile isolated recommendations from
multiple engines.

7. ACTION / EXECUTION CONTRACT

A decision can become a bounded action carrying appropriate execution
semantics such as:

- reason
- supporting evidence
- target
- intended change
- expected effect
- confidence
- risk
- authority
- guardrails
- execution state
- reversibility when meaningful
- observation window when meaningful
- success / stop criteria when meaningful

Do not require fields where they have no legitimate product meaning.

8. EXECUTION AUTHORITY LEVELS

Builderad can distinguish execution modes such as:

OBSERVE
ADVISE
PREPARE
APPROVE_AND_EXECUTE
BOUNDED_AUTOMATION

Not every action should have the same execution authority.

--------------------------------------------------
EXECUTION LEVELS ARE SEMANTIC, NOT DECORATIVE
--------------------------------------------------

Execution authority levels must not become a display-only enum.

Where a level is implemented, its meaning must be reflected appropriately in:

- authorization
- user control
- audit
- guardrails
- execution capability
- reversibility / compensation where applicable
- verification

For example:

OBSERVE
must not gain mutation authority.

ADVISE
must not execute the recommended external effect.

PREPARE
may construct an action but must not cross the required approval boundary.

APPROVE_AND_EXECUTE
requires the appropriate explicit authority before effect.

BOUNDED_AUTOMATION
must remain constrained by an explicit approved policy / guardrail boundary.

Do not create every future level before it is needed.

9. OBSERVATION / VERIFICATION

After a material action, Builderad can represent what happened.

It must be able to distinguish, where supported:

SUCCESSFUL
FAILED
INCONCLUSIVE

Do not overstate causality.

Observed-after-action is not automatically incremental or causal effect.

10. LEARNING

Evidence produced by execution can improve future reasoning.

Learning must not silently overwrite declared advertiser intent.

Declared truth, observed truth, learned evidence and discovered opportunity
must remain distinguishable.

--------------------------------------------------
OUTCOME / SYNTHESIS / LEARNING BOUNDARIES
--------------------------------------------------

CANONICAL OUTCOME MEANING does NOT mean one universal outcome value for every
business.

Builderad must support different economically meaningful outcomes according to
the actual business / goal context.

Examples may include:

qualified prospect
booking
order
sale
contract
other legitimate business result

The canonical contract should stabilize the MEANING AND OWNERSHIP of outcomes,
not force every business into one outcome definition.

DECISION SYNTHESIS means bounded reconciliation and prioritization of available
decision evidence.

It is NOT authorization to build a generic planner, generic reasoning
framework or universal workflow engine.

LEARNING means retained evidence from observations, decisions, experiments
and outcomes that can materially improve later reasoning.

It does NOT require:

- a generic ML platform
- autonomous self-modifying policies
- an agent framework
- opaque model-generated truth

Learning must remain traceable enough to distinguish:

DECLARED
OBSERVED
INFERRED
DISCOVERED
and
LEARNED EVIDENCE

11. EXTERNAL ADAPTER BOUNDARY

There is a stable boundary through which an external system can:

- contribute source evidence/facts
- receive a permitted execution action

without owning Builderad product semantics.

When these contracts are usable and coherent:

CORE_EXECUTION_SYSTEM = READY_FOR_INTEGRATION_EXPANSION

--------------------------------------------------
CORE GATE READINESS SEMANTICS
--------------------------------------------------

`READY_FOR_INTEGRATION_EXPANSION` means:

USABLE
+
COHERENT
+
ACTUALLY PROVEN ENOUGH TO EXTEND

It does NOT mean:

COMPLETE
FINAL
EXHAUSTIVE
FULLY AUTOMATED
or
MATURE ACROSS EVERY FUTURE DOMAIN

Each required Core contract must have sufficient real implementation and
evidence to prove that an external integration can extend the system without
requiring a new parallel architecture.

Do not hold integration expansion indefinitely merely because additional Core
depth is still possible.

Conversely, do not declare the gate open based only on architecture documents,
schemas or mocked flows.

CORE_GATE_READY requires actual usable product behavior.

==================================================
5. DECISION DOMAINS ARE BROAD EXECUTION SPACES
==================================================

Builderad must not reduce concepts such as Targeting or Spend into a small
fixed feature list.

Examples:

TARGETING may reason across:

- declared market
- desired customer
- intent
- audience
- exclusions
- geography
- search intent
- campaign configuration
- observed responders
- qualified outcomes
- discovered audience opportunities
- historical learning

SPEND may reason across:

- total budget
- allocation
- channels
- campaigns
- markets
- audiences
- creative
- timing
- marginal efficiency
- saturation
- underinvestment
- waste
- outcome quality
- economic viability
- experiment value

These domains intersect.

They must not become isolated silos.

A campaign, advertisement, audience, market, keyword or channel is not
automatically a top-level Builderad product domain.

The same real-world object may be analyzed by multiple decision domains.

Example:

one campaign can simultaneously be relevant to:

- Targeting
- Spend
- Creative
- Channel
- Outcome
- Conversion

Domains own reasoning/execution behavior.

They do NOT own duplicate versions of shared canonical truth.

==================================================
6. INTEGRATION MODEL AFTER THE CORE GATE
==================================================

After:

CORE_EXECUTION_SYSTEM = READY_FOR_INTEGRATION_EXPANSION

the normal integration architecture is:

EXTERNAL SYSTEM
        ↓
SOURCE / ADAPTER
        ↓
NORMALIZATION
        ↓
CANONICAL BUILDERAD FACTS / EVIDENCE
        ↓
DECISION DOMAINS
        ↓
DECISION SYNTHESIS
        ↓
ACTION / EXECUTION CONTRACT
        ↓
EXTERNAL ADAPTER
when an external effect is required

An integration must not create a parallel product architecture.

==================================================
7. PROVIDER SEMANTICS MUST NOT OWN BUILDERAD
==================================================

A field, event, state, metric or object is NOT automatically a canonical
Builderad concept merely because:

Meta has it,
Google has it,
Snapchat has it,
TikTok has it,
X has it,
Salla has it,
Zid has it,
Shopify has it,
a CRM has it,
or an SDK can collect it.

For every integration fact, ask first:

WHAT REAL-WORLD FACT DOES THIS REPRESENT?

Then:

DOES BUILDERAD ALREADY OWN THAT FACT CANONICALLY?

If YES:

map the external representation into the existing Builderad meaning.

If NO:

determine whether it represents a genuinely new Builderad product truth.

Do not silently redesign canonical semantics merely to fit an API.

==================================================
8. INTEGRATIONS ARE ADAPTERS, NOT SEMANTIC OWNERS
==================================================

For example:

Shopify Order
Salla Order
Zid Order

may each provide evidence toward a Builderad business outcome.

Builderad should not require three unrelated meanings of "sale" merely because
three providers represent orders differently.

Likewise:

Meta geography
Google geography
provider account country
commerce-store country

must not overwrite:

Declared Target Markets

unless a future explicit Owner-approved semantic contract says otherwise.

The external system owns its native representation.

Builderad owns the canonical interpretation used by Builderad decisions.

==================================================
9. KEEP INTEGRATION COMPLEXITY LOCAL
==================================================

Provider-specific implementation complexity should remain inside its adapter
where reasonably possible.

Examples include:

- authentication details
- webhook signatures
- pagination
- API rate limits
- provider identifiers
- provider-specific statuses
- retry behavior
- webhook replay
- API versioning
- provider-specific order states
- provider-specific campaign objects
- transport quirks

These details must not leak into Builderad core contracts unless they expose a
genuinely cross-provider product truth.

==================================================
10. NO INTEGRATION-DRIVEN REDESIGN
==================================================

When an integration exposes information that does not fit the current
canonical model, classify it first.

A. SOURCE-SPECIFIC DETAIL

Keep it local to the integration.

B. EXISTING CANONICAL FACT

Map it to the current Builderad owner.

C. NEW GENUINE PRODUCT TRUTH

Evaluate it independently as a product/semantic addition.

Only C may justify changing the product model.

An external provider's API shape alone is never sufficient justification for
restructuring Builderad.

==================================================
11. SDK LAST-RESORT RULE
==================================================

Do not build or expand a Builderad SDK merely because custom tracking is
technically possible.

Prefer evidence from authoritative or existing sources where sufficient,
including as appropriate:

1. native APIs
2. webhooks
3. server-side integrations
4. provider/business-system evidence

A Builderad SDK should be introduced only when it solves a demonstrated,
material evidence gap that cannot be reliably solved through the preferable
available sources.

The SDK must serve Builderad's product model.

Builderad must not accidentally become a generic analytics/tracking platform
merely because an SDK exists.

==================================================
12. EVOLVABILITY / EXTENSIBILITY PRINCIPLE
==================================================

Builderad development is continuous.

"Core complete" does NOT mean "architecture frozen forever."

The desired property is that future development becomes increasingly bounded,
predictable and inexpensive.

Prefer:

STABLE SEMANTIC CONTRACTS
+
CLEAR OWNERSHIP
+
LOCALIZED CHANGE
+
EXTENDABLE IMPLEMENTATIONS
+
BACKWARD-COMPATIBLE EVOLUTION
+
STRONG TESTABLE BOUNDARIES

where they create real engineering value.

Future capabilities should normally extend:

- canonical facts
- Decision Domains
- synthesis
- execution contracts
- observation
- verification
- learning

rather than introducing parallel architectures.

Desired evolution:

NEW CAPABILITY
→ bounded addition
→ explicit owner
→ integration with existing contracts
→ focused proof
→ minimal unrelated change

Avoid:

NEW CAPABILITY
→ duplicate truth
→ new parallel framework
→ widespread redesign

However:

DO NOT pre-build speculative extensibility.

Do NOT create merely for imagined future flexibility:

- generic plugin engines
- generic registries
- generic Decision Context frameworks
- EAV models
- generic rule DSLs
- premature polymorphic ownership
- generic workflow engines
- generic adapter abstractions without actual variation

Extensibility must follow demonstrated variation and real product needs.

The architecture should be highly evolvable without becoming abstract for the
sake of abstraction.

==================================================
13. CUSTOMIZATION PRINCIPLE
==================================================

Builderad should be capable of substantial future customization where real
advertiser/product differences require it.

Customization must not destroy canonical meaning.

Prefer:

stable core meaning
+
explicit configurable policy
+
bounded domain-specific behavior

over:

copying the product model for every industry/provider/customer.

Different business types may require different:

- outcome interpretation
- qualification logic
- decision thresholds
- execution choices
- evidence weighting
- useful domains
- action policies

but shared truths should remain shared where their real-world meaning is the
same.

Do not invent customization infrastructure until concrete variation requires
it.

==================================================
14. VALUE-FIRST PRODUCT PRINCIPLE
==================================================

Architecture is not the product goal.

Integrations are not the product goal.

AI is not the product goal.

Feature count is not the product goal.

Builderad must earn continued advertiser use by delivering repeated,
understandable and actionable value.

A capability is materially valuable when it helps the advertiser do one or
more of the following:

- improve an advertising/business outcome
- protect spend
- improve allocation
- improve targeting quality
- improve execution quality
- identify a real actionable opportunity
- avoid a weak or costly decision
- reduce meaningful uncertainty before action
- execute an improvement safely
- verify whether an intervention helped
- generate reusable learning that improves future execution

If a capability does none of these in a meaningful way:

DO NOT BUILD IT merely because it is technically possible.

==================================================
15. COMPOUNDING VALUE PRINCIPLE
==================================================

Builderad does not need one spectacular intervention to justify its value.

Repeated small improvements may compound.

Conceptually:

PROTECT
→ REDIRECT
→ IMPROVE
→ VERIFY
→ LEARN
→ REPEAT

Examples of potential value include:

- preventing avoidable waste
- protecting spend that is already working
- avoiding premature scaling
- reallocating money more intelligently
- improving targeting
- identifying an underfunded opportunity
- avoiding unnecessary intervention
- discovering a useful market/audience/channel hypothesis
- learning from a bounded experiment

Do not claim financial savings or causal lift without adequate evidence.

Distinguish:

observed change
estimated effect
incremental effect
causally demonstrated effect

==================================================
16. ADVERTISER EXPERIENCE PRINCIPLE
==================================================

The internal system may become sophisticated.

The advertiser experience should remain simple.

Builderad should not expose internal architecture merely because it exists.

The product should primarily help the advertiser answer:

WHAT IS WORKING?

WHERE IS VALUE / MONEY BEING LOST?

WHAT SHOULD I DO NOW?

and, once execution occurs:

WHAT HAPPENED AFTER WE ACTED?

The product should minimize advertiser effort while preserving sufficient
control and transparency.

Builderad should evolve toward being a persistent companion for improving
advertising execution, not a heavy dashboard the advertiser must operate.

"Persistent companion" describes repeated product value.

It is NOT a mandate to build:

- a chat shell
- activity feed
- notification center
- social-style engagement mechanics

unless those surfaces demonstrably improve the advertiser's ability to act.

Do not treat this wording as final marketing copy.

It is a product-value principle.

==================================================
17. POST-GATE INTEGRATION DEVELOPMENT MODEL
==================================================

After the Core Execution System gate, a normal new integration should look
approximately like:

CONNECT
→ AUTHENTICATE
→ INGEST / RECEIVE
→ VALIDATE
→ NORMALIZE
→ MAP TO CANONICAL BUILDERAD FACTS
→ PROVE SECURITY / ISOLATION / DURABILITY
→ CONNECT TO EXISTING REASONING
→ CONNECT TO EXISTING EXECUTION CONTRACTS IF SUPPORTED

not:

CONNECT
→ REDESIGN BUILDERAD
→ CREATE NEW PARALLEL PRODUCT MODEL
→ DUPLICATE EXISTING TRUTHS

Expected direction:

Adding Salla
≈ Salla adapter + mappings + proofs + supported execution hooks

Adding Zid
≈ Zid adapter + mappings + proofs + supported execution hooks

Adding Shopify
≈ Shopify adapter + mappings + proofs + supported execution hooks

Adding another supported external source
≈ bounded adapter + mapping + proof

This is a direction and architectural expectation, not a promise that every
future integration will require zero core change.

If a genuinely new product truth is discovered, evaluate it explicitly.

==================================================
18. GOVERNING OWNERSHIP PRINCIPLE
==================================================

BUILDERAD OWNS:

- advertiser-declared intent
- canonical business interpretation
- shared semantic truths
- decision reasoning
- cross-domain synthesis
- execution semantics
- execution authority/guardrails
- verification semantics
- learning semantics

EXTERNAL SYSTEMS OWN:

- their credentials
- their API transport
- their native object representation
- their provider-specific identifiers
- their provider-specific operational semantics

The boundary must remain explicit.

==================================================
19. FINAL OWNER SEQUENCING DECISION
==================================================

CURRENT AUTHORIZED EXECUTION:

BOUNDED LOCK-ORDER REPAIR
→ prove affected Foundation behavior
→ resume and complete approved DTM Tasks 2–13

CURRENT IMPLEMENTATION SCOPE EXPANSION:
NO

NEXT PRODUCT PRIORITY AFTER CURRENT DTM OWNER HANDOFF:

Complete Builderad's usable Core Execution System in coherent, separately
approved increments.

INTEGRATION EXPANSION:

DEFER substantial new Salla / Zid / Shopify / SDK / CRM-style integration
work until:

CORE_EXECUTION_SYSTEM = READY_FOR_INTEGRATION_EXPANSION

CORE COMPLETION DOES NOT MEAN DEVELOPMENT STOPS.

It means future product development has a stable spine.

AFTER THE CORE GATE:

Integrations should be additive adapters wherever reasonably possible.

New product capabilities should extend existing coherent contracts rather than
create parallel architectures.

Architecture should NOT be reopened merely because:

- a new provider is added
- a new commerce platform is added
- a new Decision Domain is added
- a new source exposes a different API shape

Reopen canonical product semantics only when a genuinely new product truth or
material architectural contradiction requires it.

==================================================
20. WHAT TO DO WITH THIS DIRECTIVE NOW
==================================================

1. Inspect the repository's existing governance/product-architecture
   documentation.

2. Record this directive in the most appropriate existing canonical governance
   location.

3. Do not duplicate the directive across multiple competing governance files.

4. If no appropriate canonical governance document exists, create one bounded
   document:

   docs/BUILDERAD_CORE_EXECUTION_GOVERNANCE.md

5. Clearly mark it as:

   OWNER-APPROVED PRODUCT GOVERNANCE
   NOT CURRENT FEATURE IMPLEMENTATION

6. Do not derive implementation artifacts, schemas, generic frameworks or new
   tasks from it during the current DTM work.

7. Record only the governance needed to preserve the Owner decision.

8. Then immediately resume the currently authorized bounded lock-order repair
   and DTM execution.

==================================================
21. CURRENT SOURCE-CONTROL / SAFETY RULES REMAIN
==================================================

DO NOT COMMIT.
DO NOT PUSH.
DO NOT MERGE.

DO NOT CONNECT TO OR MODIFY PRIMARY BUILDERAD.

Do not reset or discard current authorized worktree changes.

Use only the existing authorized disposable execution environment for current
DTM proof.

==================================================
22. REQUIRED ACKNOWLEDGMENT
==================================================

After recording the directive, report only:

GOVERNANCE_DIRECTIVE_RECORDED =
YES / NO

CANONICAL_GOVERNANCE_LOCATION =
<exact path>

CURRENT_DTM_SCOPE_CHANGED =
NO expected

NEW_PRODUCT_IMPLEMENTATION_STARTED =
NO expected

NEW_INTEGRATION_WORK_STARTED =
NO expected

CURRENT_EXECUTION_RESUMED =
YES / NO

NEXT_CURRENT_ACTION =
BOUNDED_LOCK_ORDER_REPAIR expected

Do not stop after this acknowledgment.

Continue the already-authorized current execution unless an existing explicit
stop condition is reached.

---

## Market Evidence and Opportunity Intelligence — approved extension

**OWNER-APPROVED PRODUCT GOVERNANCE / NOT CURRENT FEATURE IMPLEMENTATION**

Relationship: EXTENDS_EXISTING_CORE_GOVERNANCE. This extension preserves shared evidence versus declared intent, decision-delta value, source rights/access, bounded future implementation gates, and current DTM execution without expansion. The source directive is retained verbatim below, once in this canonical file.

BUILDERAD — OWNER PRODUCT GOVERNANCE DIRECTIVE
MARKET EVIDENCE & OPPORTUNITY INTELLIGENCE
FINAL REFINED VERSION

==================================================
0. OWNER DECISION
==================================================

This directive is APPROVED as future Builderad product governance.

It defines how Builderad may use external market knowledge before and after
advertiser-specific data becomes available.

This is PRODUCT GOVERNANCE.

It is NOT authorization to implement Market Intelligence now.

CURRENT AUTHORIZED EXECUTION REMAINS:

BOUNDED FOUNDATION LOCK-ORDER REPAIR
→ prove affected Foundation behavior
→ resume and complete approved Declared Target Markets Tasks 2–13

Do NOT interrupt, restart, redesign or expand the current DTM work because of
this directive.

Do NOT begin implementing because of this directive:

- Market Intelligence
- Market Evidence ingestion
- Opportunity Engine
- REGA integration
- Google Trends integration
- competitive-ad collection
- Goal
- Targeting
- Spend
- Decision Synthesis
- Execution Engine
- Real Estate intelligence
- Salla
- Zid
- Shopify
- CRM integrations
- Builderad SDK
- generic Market framework
- generic evidence framework
- opportunity scoring
- generic rules / DSL

Record the governance.

Then immediately return to the already-authorized current execution.

==================================================
1. PRODUCT THESIS
==================================================

Builderad should be capable of creating useful intelligence before it knows
the advertiser's historical advertising performance.

Connecting advertising accounts must NOT be a prerequisite for Builderad to
begin creating useful value.

Conceptually:

ADVERTISER-DECLARED INTENT
+
EXTERNAL MARKET EVIDENCE
↓
MARKET-SUPPORTED HYPOTHESES
↓
OPPORTUNITIES / RISKS
↓
BOUNDED ACTIONABLE TESTS

Then, as advertiser-specific evidence becomes available:

EXTERNAL MARKET EVIDENCE
+
ADVERTISER-DECLARED INTENT
+
ADVERTISER OBSERVED EVIDENCE
+
BUSINESS OUTCOMES
+
EXECUTION HISTORY
↓
PERSONALIZED DECISION
↓
EXECUTION
↓
VERIFICATION
↓
LEARNING

Market Intelligence and Advertiser Intelligence are NOT two separate
Builderad products.

They are different evidence inputs into the same decision/execution system.

==================================================
2. MARKET EVIDENCE IS SHARED EVIDENCE
==================================================

External market knowledge should primarily enter Builderad as:

SHARED MARKET EVIDENCE

It must NOT automatically become a standalone top-level product module or
isolated research subsystem.

Relevant market evidence may contribute to multiple Decision Domains, for
example:

- Targeting
- Spend
- Channel
- Creative / Message
- Timing
- Outcome interpretation
- other future justified decision areas

The same external fact must not be duplicated as competing canonical truths
inside different domains.

Decision Domains own reasoning and intervention logic.

They do not each own private copies of shared reality.

==================================================
3. OPPORTUNITY SEMANTICS
==================================================

Do NOT assume Opportunity is necessarily a permanent top-level Decision
Domain.

For governance purposes:

OPPORTUNITY

means a candidate condition, mismatch, possibility or hypothesis that may be
worth investigating or acting upon.

Opportunity is NOT automatically:

- a recommendation
- an action
- a proven advantage
- a verified better option
- a guaranteed economic benefit

Conceptually:

SIGNAL
→ INTERPRETATION
→ HYPOTHESIS
→ OPPORTUNITY CANDIDATE
→ DECISION
→ ACTION / TEST

Whether future implementation represents Opportunity as:

- a synthesis output
- a bounded product concept
- a Decision Domain
- another structure

must be decided from real product needs later.

Do NOT create an Opportunity framework now.

==================================================
4. MARKET EVIDENCE MUST NOT OWN ADVERTISER INTENT
==================================================

External market evidence must remain semantically separate from advertiser
declarations.

It must NOT silently overwrite:

- Declared Target Markets
- advertiser Goal
- desired customer
- declared outcome
- business identity
- other advertiser-declared canonical facts

Example:

Declared Target Markets:
Saudi Arabia

Market evidence later suggests:
UAE may contain an interesting opportunity.

Builderad must NOT silently modify DTM.

The correct conceptual behavior is:

"We found a potential UAE opportunity worth evaluating or testing."

Maintain clear semantic separation between:

DECLARED
OBSERVED
INFERRED
DISCOVERED
LEARNED

==================================================
5. EPISTEMIC STATES — NOT A MATURITY LADDER
==================================================

Builderad must distinguish materially different evidence states.

Useful conceptual evidence states include:

SINGLE SIGNAL

Example:
search interest increased.

CORROBORATED SIGNAL

Multiple independent or meaningfully different signals point in a compatible
direction.

RESEARCH-SUPPORTED HYPOTHESIS

Available evidence justifies investigating or testing a proposition.

ADVERTISER-OBSERVED EVIDENCE

The advertiser's actual execution produced relevant supporting or
contradicting evidence.

STRONG COMPARATIVE EVIDENCE

Relevant repeated or sufficiently strong evidence supports one option against
an appropriate comparison or baseline.

CAUSAL / INCREMENTAL EVIDENCE

Only where the evaluation or experiment genuinely supports causal or
incremental interpretation.

IMPORTANT:

These are NOT a mandatory linear progression.

Do NOT assume:

1 → 2 → 3 → 4 → 5 → 6

and do NOT assume one evidence state always contains or dominates all prior
states.

Examples:

- strong advertiser evidence may exist with limited external corroboration
- a narrow causal experiment may establish one effect without proving broad
  market knowledge
- strong market evidence may remain weak evidence about one specific
  advertiser

These states are epistemic distinctions, not a universal maturity ladder.

Do NOT create:

- evidence_level enum
- scoring table
- maturity engine
- generic evidence framework

during the current work.

==================================================
6. NO FALSE CERTAINTY
==================================================

External market evidence may support statements such as:

- this signal is strengthening
- this area appears more active
- multiple sources align
- this hypothesis deserves investigation
- this opportunity deserves a bounded test
- this market appears crowded
- this message space appears repetitive
- this timing may be relevant

External evidence alone must NOT support claims such as:

- this is definitely the best market
- this audience will buy
- this campaign will succeed
- this area will achieve the highest ROAS
- exact future CAC
- exact future revenue
- guaranteed demand
- guaranteed profit
- proven causal lift

Builderad must distinguish:

OBSERVED CHANGE

ESTIMATED EFFECT

INCREMENTAL EFFECT

CAUSALLY DEMONSTRATED EFFECT

Do not collapse them.

==================================================
7. CONFIDENCE MUST NOT BE DECORATIVE
==================================================

Do not generate arbitrary confidence values such as:

83%
91%
HIGH = 0.87

unless there is a defensible quantitative interpretation.

Evidence quality should instead be understood through properties such as:

- source authority
- freshness
- coverage
- geographic resolution
- semantic proximity to the desired outcome
- corroboration
- known bias / limitations
- advertiser-specific confirmation
- experimental quality where applicable

Confidence must be evidence-derived.

It must not exist merely because the UI looks more intelligent with a number.

==================================================
8. EXTERNAL EVIDENCE SOURCE CLASSES
==================================================

External evidence may originate from different source families.

Conceptually these may include:

A. STRUCTURAL / OFFICIAL MARKET EVIDENCE

Examples:
official statistics
government datasets
registries
transaction records
sector datasets

B. DEMAND / INTENT SIGNALS

Examples:
search movement
related intent
seasonality
local interest patterns

C. COMPETITION / CREATIVE SIGNALS

Examples:
public advertising
creative patterns
offers
positioning
message repetition

D. CHANNEL EVIDENCE

Evidence relevant to whether and how a particular type of intent or audience
can be reached.

E. TEMPORAL / LOCAL CONTEXT

Examples:
location
season
trend
time-dependent context
relevant local events

F. SECTOR-SPECIFIC ECONOMIC / SUPPLY EVIDENCE

Examples:
prices
transactions
inventory
supply
competitive density
sector economics

These categories are conceptual.

Do NOT implement generic source classes before real variation requires them.

==================================================
9. CHANNEL EVIDENCE MUST BE DECOMPOSED CORRECTLY
==================================================

Do not collapse all channel-related evidence into:

"the audience exists on this platform."

Preserve conceptual distinctions between:

AUDIENCE PRESENCE
Does the relevant audience appear to use or exist within the environment?

ADVERTISER ACCESSIBILITY
Can this advertiser realistically reach that audience through the available
product surface?

PAID TARGETING AVAILABILITY
Does the platform actually expose targeting or capture mechanisms relevant to
the intended audience / intent?

INTENT CAPTURE SUITABILITY
Is the channel suited to capturing this type and stage of intent?

ECONOMIC SUITABILITY
Can this channel plausibly deliver the desired outcome under the advertiser's
economics and constraints?

These are different facts.

For example:

Audience presence on TikTok
≠
TikTok is the best paid acquisition channel.

Availability of a targeting option
≠
high-intent audience capture.

Search intent existing on Google
≠
profitable economics for this advertiser.

Do NOT implement this decomposition now as schema or scoring.

Preserve it as future research/decision discipline.

==================================================
10. SOURCE QUALITY / PROVENANCE IS A REAL GATE
==================================================

A publicly visible source is not automatically a valid Builderad production
source.

Before future automated production ingestion, verify as applicable:

- source identity
- authority
- retrieval method
- revision / date
- update cadence
- geographic resolution
- coverage
- known limitations
- known bias
- extraction stability
- API availability
- license
- commercial reuse rights
- automation / crawling permission
- attribution requirements
- reproducibility

Do not assume:

PUBLIC PAGE
=
AUTOMATED INGESTION ALLOWED

Do not assume:

VISIBLE UI
=
OFFICIAL API AVAILABLE

Do not assume:

COUNTRY PARAMETER
=
COMPLETE COUNTRY DATA

Rights, access and reproducibility are production gates, not documentation
checkboxes.

If a source cannot be used lawfully or reproducibly:

do not build the production capability around it.

==================================================
11. SIGNALS ARE NOT BUSINESS OUTCOMES
==================================================

Preserve semantic distance between market signals and business outcomes.

Examples:

Search interest
≠ buyers

Population
≠ commercial demand

Healthcare activity
≠ demand for one clinic procedure

Transactions
≠ profitable advertiser acquisition

High advertising activity
≠ attractive opportunity

Low advertising activity
≠ low competition

Creative repetition
≠ proven differentiation opportunity

Audience presence
≠ profitable reach

Builderad's value is not in exaggerating the signal.

It is in interpreting multiple pieces of evidence carefully and turning them
into economically meaningful hypotheses or actions.

==================================================
12. MARKET DISEQUILIBRIUM REASONING
==================================================

Builderad should not search only for:

"where is demand high?"

Important value may exist in relationships, imbalances and contradictions.

Conceptual examples:

DEMAND ↑
+
COMPETITION STABLE
→ possible opportunity

DEMAND STABLE
+
COMPETITION ↑↑
→ possible crowding / cost pressure

SEARCH INTENT ↑
+
TRANSACTIONS FLAT
→ investigate mismatch

TRANSACTIONS ↑
+
SEARCH FLAT
→ investigate behavior / timing / source differences

MARKET ATTRACTIVE
+
ADVERTISER ECONOMICS WEAK
→ do not scale automatically

MARKET ATTRACTIVE
+
ADVERTISER CAPACITY LOW
→ avoid creating demand the business cannot handle

HIGH RESPONSE
+
LOW QUALIFIED OUTCOME
→ apparent demand may be low-value

HIGH ACTIVITY
+
HOMOGENEOUS COMPETITOR MESSAGING
→ possible message differentiation hypothesis

These are reasoning examples.

They are NOT rules.

Do NOT encode them now as:

- rule engine
- DSL
- scoring system
- generic inference framework

==================================================
13. DECISION-DELTA VALUE TEST
==================================================

Market data must not enter Builderad merely because it is interesting.

Before a future Market Evidence source or capability is promoted into product
scope, ask:

WHAT DECISION DOES THIS EVIDENCE CHANGE?

Conceptually compare:

WITHOUT THIS EVIDENCE
→ what would Builderad recommend, test, avoid or remain uncertain about?

WITH THIS EVIDENCE
→ what materially changes?

A useful evidence source should be capable of producing at least one meaningful
decision delta, such as:

- Action A becomes Action B
- Action A is avoided
- a previously unsupported test becomes justified
- a test becomes smaller / safer / better targeted
- timing changes
- channel choice changes
- spend allocation changes
- targeting changes
- confidence in a decision materially improves for defensible reasons

"Improved confidence" counts as a Decision Delta only when it changes a
meaningful decision boundary.

Examples:

- enables an action that was previously unsupported
- prevents an action that previously appeared justified
- changes test size / exposure
- changes required approval or guardrails
- changes timing
- changes the amount of evidence required before execution

A merely more confident-sounding recommendation is NOT a Decision Delta.

Do not use this clause to reintroduce decorative confidence scoring.

- uncertainty is reduced enough to enable or prevent an action
- a potential waste condition is detected
- a previously attractive hypothesis is rejected

If the evidence changes:

nothing material

and only adds:

more charts
more facts
more interesting context
more dashboard content

then the evidence likely does not belong in the product.

This is the primary anti-data-lake rule.

==================================================
14. MARKET OUTPUT MUST BE ACTION-ORIENTED
==================================================

Builderad must not become a Market Research dashboard.

The valuable result is not:

"Here are 34 charts about Riyadh."

The result should move toward:

"We found something that may deserve action."

Conceptual result states may include:

IGNORE
WATCH
INVESTIGATE
TEST
PROTECT
SCALE
STOP / REDUCE

These are thinking examples.

Do NOT create an enum because they are documented here.

The important principle is:

Market Intelligence must not always recommend spending more.

Valid conclusions may include:

- do nothing
- wait
- gather evidence
- test cheaply
- protect existing execution
- reduce exposure
- avoid an overcrowded area
- reject a hypothesis
- stop scaling

==================================================
15. OPPORTUNITY MUST CONNECT TO EXECUTION
==================================================

A useful opportunity should eventually support questions such as:

WHAT DID WE FIND?

WHY DOES IT MATTER?

WHAT EVIDENCE SUPPORTS IT?

WHAT DOES THE EVIDENCE NOT PROVE?

WHAT CHANGES BECAUSE OF THIS INFORMATION?

WHAT SHOULD WE TEST OR DO?

WHAT IS THE BOUNDED COST / EXPOSURE?

HOW WILL WE MEASURE IT?

WHEN SHOULD WE STOP?

WHAT WILL WE LEARN?

Conceptually:

MARKET EVIDENCE
↓
SIGNAL
↓
INTERPRETATION
↓
HYPOTHESIS
↓
OPPORTUNITY CANDIDATE
↓
DECISION
↓
BOUNDED TEST / ACTION
↓
EXECUTION
↓
OBSERVED RESULT
↓
VERIFICATION
↓
LEARNING

Market Intelligence therefore terminates in the same Builderad Core Execution
System.

It does not create a parallel execution architecture.

==================================================
16. BEFORE CONNECTION / AFTER CONNECTION
==================================================

Builderad should support progressively richer evidence without creating two
different products.

A. BEFORE ADVERTISER CONNECTION

Possible relevant inputs include:

- business type
- product/service
- declared market
- local area where relevant
- desired result
- price/value band where relevant
- approximate budget where relevant

Then:

DECLARED INTENT
+
EXTERNAL MARKET EVIDENCE
↓
HYPOTHESIS
↓
OPPORTUNITY
↓
FIRST BOUNDED TEST

Useful value should be possible without forcing OAuth first.

B. AFTER ADVERTISER CONNECTION

Add relevant advertiser evidence such as:

- campaigns
- spend
- targeting/configuration
- audience response
- leads/outcomes
- business/economic evidence
- execution history

Then:

MARKET PRIOR
+
ADVERTISER-SPECIFIC EVIDENCE
↓
PERSONALIZED DECISION
↓
EXECUTION
↓
VERIFICATION
↓
LEARNING

Do not create separate reasoning architectures for the two states.

==================================================
17. MARKET PRIOR, NOT MARKET VERDICT
==================================================

External market evidence should normally act as a prior.

Example:

"This appears worth testing."

Advertiser-specific evidence then updates that prior.

Before advertiser execution:

"Available evidence suggests this Riyadh segment deserves testing."

After execution:

"For this advertiser, the segment produced stronger qualified outcomes."

After stronger repeated evidence:

"Advertiser-specific evidence increasingly supports this allocation under
these observed conditions."

Do not preserve an external-market hypothesis against contradictory
advertiser evidence merely because the original market story was compelling.

Reality overrides narrative.

==================================================
18. LEARNING WITHOUT MIXING TRUTH
==================================================

Execution can improve Builderad's understanding of:

- targeting
- spend
- channel
- creative/message
- timing
- opportunity quality
- advertiser-specific fit

However:

ADVERTISER-SPECIFIC LEARNING

must remain distinguishable from:

GENERAL MARKET EVIDENCE.

Future cross-advertiser aggregated learning may be valuable.

But it introduces separate requirements concerning:

- privacy
- aggregation
- tenant isolation
- data rights
- representativeness
- sample bias
- provenance
- statistical validity

Do NOT implement or assume cross-advertiser market learning now.

It requires separate future governance.

==================================================
19. SAUDI ARABIA IS THE CURRENT PROOF ENVIRONMENT
==================================================

Saudi Arabia may be the first Market Evidence proof environment because
useful official and sector-specific evidence exists for some sectors.

Do NOT hardcode:

SaudiMarketEngine

into Builderad's core architecture.

Also do NOT prematurely build:

GenericGlobalMarketFramework

Current product sequencing:

CURRENT PROOF ENVIRONMENT:
Saudi Arabia

This is a product sequencing choice.

It is NOT a permanent architecture assumption.

==================================================
20. FIRST PROOF VERTICAL
==================================================

When Market Evidence implementation is separately authorized in the future,
do not attempt to model:

all Saudi sectors
all cities
all neighborhoods
all products
all channels

at once.

Start with one evidence-rich proof vertical.

Current preferred proof:

COUNTRY:
Saudi Arabia

SECTOR:
Real Estate

LOCAL PROOF:
Riyadh

The purpose is not to prove Real Estate is permanently the most important
Builderad sector.

The purpose is to prove the complete value loop:

MARKET EVIDENCE
→ INTERPRETATION
→ HYPOTHESIS
→ OPPORTUNITY
→ DECISION DELTA
→ ACTIONABLE TEST
→ EXECUTION
→ RESULT
→ VERIFICATION
→ LEARNING

If this loop does not create real advertiser value:

do not scale the Market capability merely because the data infrastructure
works.

==================================================
21. NO PREMATURE MARKET ONTOLOGY
==================================================

Do not assume every sector follows:

Country
→ Sector
→ City
→ Neighborhood

Different sectors may require materially different dimensions.

Real Estate may involve:

property type
× price band
× city
× district
× transaction activity
× buyer intent

Healthcare may eventually involve:

service
× patient need
× geography
× accessibility
× timing
× intent

Commerce may eventually involve:

category
× product
× price
× demand
× inventory
× competition
× season

Do NOT design a generic multidimensional Market Model now.

Start with concrete evidence requirements.

Extract abstractions only when repeated real implementations expose stable
shared structure.

==================================================
22. VALUE TEST FOR MARKET CAPABILITIES
==================================================

A Market capability is not valuable because it provides interesting
information.

It must materially improve at least one of:

- opportunity discovery
- targeting decision
- spend allocation
- channel choice
- timing
- creative/message decision
- experiment design
- avoidance of waste
- avoidance of a bad decision
- reduction of meaningful uncertainty
- execution quality
- reusable learning

Then apply the stricter test:

DID IT CHANGE THE DECISION?

If not, ask whether the information is actually worth:

- collecting
- storing
- maintaining
- refreshing
- presenting
- governing

Do not build Market capabilities merely to increase perceived intelligence.

==================================================
23. ADVERTISER EXPERIENCE
==================================================

Do not expose internal architectural language unless there is a demonstrated
UX reason.

Avoid making the user operate:

- Market Evidence Graph
- Evidence Fusion
- Source Intelligence
- Market Engine
- Opportunity Scoring
- Sector Evidence Pack

The user should see simple outcomes.

Example:

"وجدنا 3 فرص تستحق الاختبار."

For an individual opportunity, Builderad may progressively explain:

WHY?

WHAT SUPPORTS THIS?

WHAT DOES THIS NOT PROVE?

WHAT CHANGES BECAUSE OF THIS?

WHAT DO YOU RECOMMEND?

WHAT WILL THE TEST COST?

HOW LONG WILL WE WATCH IT?

WHAT COUNTS AS SUCCESS?

WHEN DO WE STOP?

The product remains:

ANSWER FIRST
EVIDENCE ON DEMAND
ACTION ORIENTED

not:

DASHBOARD FIRST.

==================================================
24. RELATIONSHIP TO CORE EXECUTION GOVERNANCE
==================================================

This directive extends the already-approved:

CORE EXECUTION SYSTEM BEFORE INTEGRATION EXPANSION

governance.

It does NOT replace it.

Conceptually:

EXTERNAL MARKET EVIDENCE
+
SHARED CANONICAL BUILDERAD FACTS
+
ADVERTISER INTENT
+
ADVERTISER EVIDENCE WHEN AVAILABLE
        ↓
DECISION DOMAINS
        ↓
DECISION SYNTHESIS
        ↓
ACTION / EXECUTION CONTRACT
        ↓
EXECUTION
        ↓
OBSERVATION
        ↓
VERIFICATION
        ↓
LEARNING

IMPORTANT:

External evidence is NOT automatically canonical Builderad truth.

Preserve the distinction between:

CANONICAL FACT
A fact whose meaning and ownership Builderad has explicitly established.

SOURCE OBSERVATION
What an external source actually reports or exposes.

INTERPRETATION
What Builderad reasonably derives from one or more observations.

HYPOTHESIS
A proposition that may justify investigation or testing.

DISCOVERED OPPORTUNITY
A candidate possibility identified through reasoning.

LEARNED ADVERTISER EVIDENCE
What actual advertiser execution/results teach Builderad.

Even an authoritative official source does not automatically convert an
observation into a different semantic fact.

Canonical ownership depends on meaning, not source prestige.

Builderad remains the owner of:

- advertiser intent
- canonical interpretation
- decision reasoning
- synthesis
- execution semantics
- verification
- learning

External market systems provide evidence.

They do not define Builderad's product semantics.

==================================================
25. CURRENT IMPLEMENTATION CONSTRAINT
==================================================

THIS DIRECTIVE DOES NOT AUTHORIZE MARKET IMPLEMENTATION.

Do NOT now:

- create Market tables
- create Market schemas
- create Market APIs
- create Market services
- create Market adapters
- download datasets
- scrape sources
- add dependencies
- add REGA code
- add Google Trends code
- add competitive-ad ingestion
- implement Opportunity
- implement evidence states
- create confidence scoring
- create generic Market classes
- create Real Estate intelligence
- change DTM to support future Market functionality
- refactor current architecture speculatively

Current DTM implementation remains bounded to its approved specification and
implementation plan.

==================================================
26. FUTURE IMPLEMENTATION GATE
==================================================

When Market Evidence implementation is separately authorized later:

do NOT jump directly to coding.

The future sequence should be:

DEFINE THE VALUE / DECISION DELTA TO PROVE
↓
BOUND ONE VERTICAL
↓
IDENTIFY REQUIRED EVIDENCE
↓
VERIFY REAL SOURCES
↓
VERIFY RIGHTS / ACCESS / LICENSE / AUTOMATION
↓
IDENTIFY WHAT EACH SIGNAL ACTUALLY PROVES
↓
IDENTIFY WHAT IT DOES NOT PROVE
↓
DEFINE MINIMAL CANONICAL EVIDENCE CONTRACT
↓
DEFINE INTERACTION WITH EXISTING DECISION DOMAINS
↓
DEFINE BOUNDED OPPORTUNITY / TEST BEHAVIOR
↓
DESIGN
↓
OWNER REVIEW
↓
IMPLEMENT
↓
PROVE END-TO-END DECISION VALUE
↓
ONLY THEN EXPAND

Do not infer future schemas from this governance document.

==================================================
27. GOVERNANCE RECORDING
==================================================

Record this directive in the existing canonical Builderad product-governance
location.

Prefer coherent integration with the existing:

CORE EXECUTION SYSTEM BEFORE INTEGRATION EXPANSION

governance.

Do not create competing doctrines.

Do not duplicate the full directive across multiple files.

There should be one clear canonical governing source for this product
direction.

If governance is currently split for a legitimate structural reason, preserve
one explicit canonical authority/reference path.

==================================================
28. FINAL OWNER PRODUCT DECISION
==================================================

MARKET_EVIDENCE_DIRECTION =
APPROVED

MARKET_EVIDENCE_ROLE =
SHARED_EVIDENCE_FOR_DECISION_AND_EXECUTION

USEFUL_BEFORE_OAUTH =
YES

SAME_REASONING_SYSTEM_BEFORE_AND_AFTER_OAUTH =
YES

MARKET_DATA_CAN_PROVE_ADVERTISER_RESULT_BY_ITSELF =
NO

MARKET_HYPOTHESES_CAN_DRIVE_BOUNDED_TESTS =
YES

MARKET_EVIDENCE_CAN_OVERWRITE_DECLARED_INTENT =
NO

MARKET_INTELLIGENCE_AS_ISOLATED_RESEARCH_PRODUCT =
NO

OPPORTUNITY_IS_AUTOMATICALLY_A_DECISION_DOMAIN =
NO

OPPORTUNITY_IS_AUTOMATICALLY_A_RECOMMENDATION =
NO

OPPORTUNITY_IS_AUTOMATICALLY_A_PROVEN_ADVANTAGE =
NO

EVIDENCE_STATES_ARE_A_LINEAR_MATURITY_LADDER =
NO

DECORATIVE_CONFIDENCE_SCORES =
NO

SOURCE_RIGHTS_AND_AUTOMATION_ARE_REAL_GATES =
YES

MARKET_CAPABILITY_REQUIRES_DECISION_DELTA =
YES

MARKET_DATA_LAKE_WITHOUT_DECISION_VALUE =
NO

SAUDI_ARABIA =
CURRENT_PROOF_ENVIRONMENT

FIRST_PREFERRED_PROOF_VERTICAL =
REAL_ESTATE_RIYADH

GENERIC_GLOBAL_MARKET_FRAMEWORK_NOW =
NO

GENERIC_MARKET_ONTOLOGY_NOW =
NO

GENERIC_EVIDENCE_FRAMEWORK_NOW =
NO

GENERIC_RULE_ENGINE_NOW =
NO

CROSS_ADVERTISER_AGGREGATED_LEARNING_NOW =
NO

CURRENT_DTM_SCOPE_CHANGED =
NO

CURRENT_IMPLEMENTATION_SCOPE_EXPANDED =
NO

==================================================
29. CURRENT SOURCE-CONTROL / SAFETY RULES
==================================================

Existing current-work safety rules remain unchanged.

DO NOT COMMIT.
DO NOT PUSH.
DO NOT MERGE.

DO NOT CONNECT TO OR MODIFY PRIMARY BUILDERAD.

Do not reset or discard authorized worktree changes.

Do not convert this governance directive into implementation work.

==================================================
30. REQUIRED ACKNOWLEDGMENT
==================================================

After recording this directive, report:

MARKET_GOVERNANCE_RECORDED =
YES / NO

CANONICAL_GOVERNANCE_LOCATION =
<exact path>

CORE_GOVERNANCE_RELATIONSHIP =
EXTENDS_EXISTING_CORE_GOVERNANCE expected

CURRENT_DTM_SCOPE_CHANGED =
NO expected

MARKET_IMPLEMENTATION_STARTED =
NO expected

GENERIC_MARKET_FRAMEWORK_CREATED =
NO expected

GENERIC_EVIDENCE_FRAMEWORK_CREATED =
NO expected

NEW_DATA_SOURCE_INGESTION_STARTED =
NO expected

DECISION_DELTA_RULE_RECORDED =
YES expected

CURRENT_EXECUTION_RESUMED =
YES / NO

NEXT_CURRENT_ACTION =
already-authorized lock-order repair / DTM execution

Do not stop merely after reporting this acknowledgment.

Continue the already-authorized current execution unless an existing explicit
Owner stop condition is reached.

## External Evidence Providers — governance supplement

The following Owner-supplied directive supplements the future evidence/provider governance above. It leaves current DTM scope and all source-control/primary-database restrictions unchanged.

==================================================
EXTERNAL EVIDENCE PROVIDERS — AUTHORITY,
SUBSTITUTABILITY & CAPABILITY UTILIZATION
==================================================

External providers may deliver substantial value to Builderad.

Builderad should use valuable provider capabilities deeply when this is
economically, legally and technically justified.

However:

PROVIDER VALUE
does not imply
PROVIDER SEMANTIC OWNERSHIP.

--------------------------------------------------
1. AUTHORITY AND SUBSTITUTABILITY ARE SEPARATE
--------------------------------------------------

Evaluate two independent properties:

SOURCE AUTHORITY
and
SOURCE SUBSTITUTABILITY.

Do not treat them as one category.

AUTHORITATIVE / PRIMARY SOURCE

A source that directly creates, records, governs or authoritatively publishes
a particular underlying fact.

Authority is FACT-RELATIVE, not provider-global.

Example:

a provider may be authoritative for:

its own account configuration
its own reported spend
its own native object state

while being merely evidentiary for:

true market demand
business outcomes
customer value
economic opportunity.

A source may also be authoritative while the technical acquisition path
remains replaceable.

Therefore:

SEMANTIC AUTHORITY
≠
TECHNICAL DELIVERY PATH.

--------------------------------------------------
2. REPLACEABLE COMMERCIAL EVIDENCE PROVIDERS
--------------------------------------------------

Commercial providers that:

- estimate
- aggregate
- enrich
- transform
- survey
- model
- index
- collect
- simplify access to

market evidence may be used extensively.

Their identity must remain provenance.

Their provider-native meaning must not silently become canonical Builderad
meaning.

Examples of provider-native artifacts include:

- scores
- taxonomies
- categories
- rankings
- embeddings
- audience segments
- model outputs
- derived features
- proprietary indexes

These must remain traceable to their source and methodology.

A provider-derived feature must not silently become a universal Builderad
truth.

--------------------------------------------------
3. OPTIONAL ENRICHMENT PROVIDERS
--------------------------------------------------

Some sources may improve:

- depth
- freshness
- corroboration
- audience understanding
- channel understanding
- decision quality

without being required for the Builderad Core to operate.

Their removal may reduce evidence quality or available reasoning.

It must not automatically invalidate unrelated Builderad capabilities.

--------------------------------------------------
4. CAPABILITY-FIRST PROVIDER UTILIZATION
--------------------------------------------------

Do NOT evaluate or integrate a provider only as one monolithic product.

Evaluate the provider capability by capability.

For every available provider function ask:

WHAT EVIDENCE NEED DOES THIS CAPABILITY SERVE?

WHAT REAL-WORLD FACT OR SIGNAL DOES IT OBSERVE OR ESTIMATE?

WHICH BUILDERAD DECISION MAY IT CHANGE?

WHAT DOES IT NOT PROVE?

Examples conceptually:

keyword search volume
→ search-interest evidence

CPC / paid competition
→ paid-search market pressure evidence

competitor traffic sources
→ digital acquisition-pattern evidence

geographic traffic distribution
→ geographic digital-presence evidence

audience survey behavior
→ audience prior / media behavior evidence

social conversation monitoring
→ public conversation / topic evidence

market-size statistics
→ structural market context

Provider capabilities should be mapped to evidence needs.

They should NOT define the evidence ontology merely because the provider
offers them.

--------------------------------------------------
5. USE PROVIDER FUNCTIONS FULLY — BUT SELECTIVELY
--------------------------------------------------

Builderad should not intentionally underuse a valuable provider merely to
appear independent.

If a provider exposes several capabilities that materially improve decisions,
Builderad may use all justified capabilities.

However:

DO NOT ingest an entire provider catalog merely because access exists.

Apply the Decision Delta test independently to meaningful capability groups.

Conceptually:

PROVIDER
    ↓
AVAILABLE CAPABILITIES
    ↓
EVIDENCE NEED MAPPING
    ↓
DECISION DELTA
    ↓
ADOPT / REJECT / DEFER

The unit of product value is not:

"we integrated Provider X."

The unit of product value is:

"Provider capability Y supplies evidence that improves Decision Z."

--------------------------------------------------
6. REPLACEABILITY IS DECISION-LEVEL
--------------------------------------------------

Replaceability does NOT require:

- identical schemas
- identical fields
- identical scores
- identical taxonomies
- identical methodology
- identical historical continuity

The desired property is:

PROVIDER A
→ evidence

may later become:

PROVIDER B
→ independently sufficient evidence
   for the same Builderad decision need

while Builderad's core product meaning remains stable.

Replaceability is evaluated at the level of the evidence required for the
decision.

It is NOT evaluated by forcing different providers into artificially
identical data models.

--------------------------------------------------
7. SOURCE FAILURE ≠ EVIDENCE ABSENCE ≠ FACT ABSENCE
--------------------------------------------------

Distinguish:

SOURCE FAILURE

The provider could not deliver evidence.

EVIDENCE ABSENCE

The source returned no relevant observation.

REAL-WORLD FACT ABSENCE

The underlying phenomenon is actually absent.

These are NOT equivalent.

For example:

provider returned no demand data

does NOT automatically mean:

there is no demand.

ABSENCE OF PROVIDER EVIDENCE
must not automatically be interpreted as
ABSENCE OF THE UNDERLYING REAL-WORLD FACT.

--------------------------------------------------
8. DEGRADATION MUST BE EPISTEMICALLY HONEST
--------------------------------------------------

If a provider becomes unavailable:

Builderad must not fabricate missing evidence.

Builderad may, where appropriate:

- use another valid source
- operate with reduced evidence
- require additional direct observation
- require an advertiser test
- reduce claim strength
- withhold the affected conclusion
- mark the conclusion unsupported

Do NOT silently substitute weaker evidence while preserving the same claim
strength.

Loss of stronger evidence must be reflected in the reasoning where it
materially affects support for the decision.

Unrelated Builderad capabilities should continue operating where possible.

--------------------------------------------------
9. NO UNNECESSARY SEMANTIC LOCK-IN
--------------------------------------------------

The goal is NOT zero provider dependency.

The goal is:

NO UNNECESSARY SEMANTIC OR ARCHITECTURAL LOCK-IN.

Builderad may depend commercially or operationally on a provider where the
value justifies it.

But removal of that provider should primarily affect:

evidence acquisition
or
evidence strength

rather than require redefining:

- advertiser intent
- canonical business facts
- Decision Domains
- action semantics
- execution authority
- verification meaning
- learning semantics.

--------------------------------------------------
10. PROVIDER EVALUATION
--------------------------------------------------

For every provider or material provider capability considered, evaluate:

1. DECISION DELTA

2. SOURCE AUTHORITY / QUALITY

3. REQUIRED MARKET COVERAGE

4. GRANULARITY

5. FRESHNESS

6. METHODOLOGY / ESTIMATION BIAS

7. API / DELIVERY RELIABILITY

8. EMBEDDING / DERIVED-INSIGHT / REDISTRIBUTION RIGHTS

9. COST

10. REPLACEABILITY AT THE DECISION LEVEL

11. DEGRADATION BEHAVIOR IF REMOVED

12. SEMANTIC DEPENDENCY RISK

13. UNIQUE DECISION DEPENDENCY

14. EXIT / MIGRATION / DATA-RETENTION COST

15. CAPABILITY UTILIZATION VALUE

For CAPABILITY UTILIZATION VALUE ask:

Which provider functions would we actually use?

Which Builderad decisions would each function affect?

What functions are valuable but redundant?

What functions add information but no Decision Delta?

What capabilities should remain unused?

--------------------------------------------------
11. BUILD / BUY / CONNECT PRINCIPLE
--------------------------------------------------

Prefer:

BUY / CONNECT

when external collection infrastructure is expensive, specialized, mature or
commodity relative to Builderad's differentiation.

Prefer:

BUILD / OWN

where Builderad's durable differentiated value exists.

Builderad should primarily own:

- evidence meaning
- evidence limitations
- cross-source synthesis
- advertiser fit
- opportunity interpretation
- Decision Delta
- action selection
- execution semantics
- verification
- learning

External providers may own or specialize in:

- large-scale data acquisition
- panels
- clickstream collection
- keyword databases
- social firehoses
- commercial market datasets
- specialized source collection

where using them is superior.

==================================================
GOVERNING PRINCIPLE
==================================================

BUILDERAD SHOULD OWN THE INTELLIGENCE.

BUY / CONNECT THE EVIDENCE WHERE THIS IS ECONOMICALLY,
LEGALLY AND TECHNICALLY SUPERIOR.

USE PROVIDERS DEEPLY WHERE THEY CREATE REAL DECISION VALUE.

DO NOT LET PROVIDER CAPABILITIES DEFINE BUILDERAD'S PRODUCT MEANING.