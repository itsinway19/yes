# Native SPA lifecycle contract

Implementation baseline: `5281634071b0a91520255a0b1ee4812b134dc76f`.

Laravel owns authentication, canonical deadlines, revocation, action grants and
Workspace/RLS admission. Signed document evidence is request evidence, not authority.
The client never adopts a new Principal in an existing document.

## Owners

- `transport.ts`: immutable document binding, response admission, terminal closure,
  bounded request delivery and one document lease scheduler. Route preparation cannot
  cancel or postpone renewal. A terminal document cannot reopen.
- `router.ts`: one abortable, bounded navigation candidate; route commitment follows
  successful preparation. Ordinary push preparation retains the source URL and admitted
  view. Native history changes the address first, so its retained source is concealed
  synchronously until admission. No second router or navigation runtime.
- Feature loaders: prepare a resource and a matching current server witness, then
  stage publication for the owning candidate. Direct feature refreshes use the same
  admission checks. No destination data publishes before route commitment.
- `presentation.ts`: admitted view, temporary concealment, candidate progress/error.
  Concealment is synchronous; it does not itself destroy a mounted editor or renew a
  session. Document closure clears feature private state and removes the view.
- `browserLifecycle.ts`: one installation/disposal lifetime for departure concealment,
  passive restoration and cross-tab hints. Hidden pages defer restoration. Equivalent
  pending restoration events share one preparation; hints grant no authority.
- Features: human drafts, originating versions and frozen/unknown commands. Passwords
  and authenticator material are never persisted as ordinary drafts. No unknown
  mutation or assertion is automatically replayed.

## Transitions

| Event | Presentation | Requests/evidence | Drafts/lease |
| --- | --- | --- | --- |
| Same document/Workspace | Keep the admitted source while preparing target; source URL stays current for ordinary push | Cancel superseded candidate; read + witness before commit | Source stays mounted; lease independent |
| Different Workspace/permission | Never label source as target; publish target only after current authorization | Existing Workspace/route guards and exact witness | Workspace drafts isolated; no authority from retained fields |
| Rejected/aborted/duplicate candidate | Keep authorized source; show destination error when relevant | Obsolete candidate cannot commit; duplicate without pending work does nothing | No temporary unmount or write replay |
| A-B-A / Back-Forward | Only current candidate commits; passive history conceals the source as the address changes | Abort and invalidate obsolete candidate reads | Feature draft rules retained; no authority in history |
| Hidden/pagehide | Conceal immediately, retain editor lifetime | Cancel candidate; no hidden publication | Lease remains bounded by server contract |
| Visible/persisted pageshow/hint | Concealed until fresh passive resource+witness | Coalesce pending restoration; hidden hints defer work | No command replay; expired document requires native recovery |
| Login/identity switch | Close old document immediately after valid transition ACK; neutral handoff UI | Native server-selected destination; no Principal adoption | Existing closure cleanup; new document admission |
| Logout/expiry/revocation/conflict | Conceal immediately; terminal closure never reopens | No new requests from retiring/closed document; late responses rejected | No renewal after closure; unknown result is not failure/success |
| Pending write | Feature owns outcome; transport abort is not server rollback | One delivery; ACK and admitted readback remain distinct | Preserve original command/version; explicit review only |

The witness remains part of acceptance. Shell/witness responses retain authorization
but omit unused business projection work. The added customer-directory witness function calls the existing private SQL authority
owner and omits the directory projection. It does not duplicate authority policy.
RLS, cookie publication, canonical timeouts and passive idle semantics are preserved.
Migration 000390 is exercised only in the disposable environment; it is not applied
to the owner preview database by this task.

Browser restoration evidence must distinguish JavaScript concealment, native BFCache
and pre-JavaScript compositor confidentiality. One browser's evidence is not universal.

## Acceptance

Actual production-build browser flows, virtual WebAuthn, document/route/renewal races,
real component draft behavior, guarded isolated Laravel/security suites, response query
checks, API/build/retirement/architecture guards and a final structural review must all
refer to the final candidate. A failed or unexecuted required gate is not PASS.

## Retirement inventory and current consumers

| Retired surface | Current owner / preserved verification |
| --- | --- |
| Inertia and proof-only configuration, second goals shell and `/spa/*` aliases | `spa/main.ts`, application bootstrap and canonical `/workspaces/*` routes; API parity and runtime retirement guards |
| N1 official/client-visibility harnesses and N1 fault/server fixtures | Vue Router candidate tests, presentation and browser-lifecycle tests; current production-asset restoration/browser harnesses |
| Legacy logout cookie/native/terminal/review runners and browser fixtures | `IdentityRenewalTest`, `IdentityDocumentTest`, `ProductionIdentityTest`, transport logout tests and `goals-spa-browser.mjs` closure/restoration cases |
| Old Current Intent / advertiser-goals UI runner, browser and fixture chain | `business-context-spa-browser.mjs`, Goals SPA browser and feature draft/command tests; no Inertia response expectations |
| Proof event mutator in production Goals state | `goals-spa/proof.ts`, imported dynamically only in proof mode |
| Pages/Workspaces Source capability widget | `Components/Sources/SourceCapabilityStatus.vue`, used by current Source registry |

Historical governance and evidence documents retain their original claims and dates.
`CurrentIntentDurabilityProof.php` is retained as an explicit standalone domain/SQL
proof, not as a browser/runtime entry point; this cleanup does not claim to rerun it.
`InertiaHistorySecurityTest` remains a negative protocol/security regression and does
not install or execute Inertia. Retirement scripts remain active guards. Production
middleware still rejects unsupported Inertia protocol input deliberately.

Feature leave decisions also guard same-record parameter/query updates. Goals uses
the same asynchronous persistence callback for leave and update. A denied history
transition stays concealed until a fresh admitted read or explicit new document.
