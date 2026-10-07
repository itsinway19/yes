# Builderad implementation guidance

Before material product implementation:

- Identify the accepted semantic/capability owner and applicable INV-* rules in [Executable Architecture](docs/BUILDERAD_EXECUTABLE_ARCHITECTURE.md).
- Preserve provider/source optionality; source-dependent conclusions still require their legitimate evidence.
- Keep provider implementation dependencies outside declared Core boundaries.
- Do not convert missing/failed/unknown evidence into false certainty.
- Update applicable invariant protection when accepted landed semantics change; do not invent future capabilities to obtain PASS.
- Run applicable architecture guards and Foundation gates.

Canonical governance/contracts remain authoritative. This file is operational guidance; tests, database authority boundaries and Foundation CI enforce the declared coverage.
