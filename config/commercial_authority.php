<?php

return [
    // Trusted P4-A deployment configuration only. Default deny. Never populated from HTTP input.
    // Each exact Owner-approved exceptional action specifies mandate UUID, executor/grantee public IDs,
    // action (assign/revoke), target UUID, optional Workspace/configuration, generations, expiry,
    // approval_reference and, for revocation, the exact grant ID. No real mandate is supplied by SA-01.
    'owner_mandates' => [],
];
