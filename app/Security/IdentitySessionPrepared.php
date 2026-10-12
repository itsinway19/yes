<?php

namespace App\Security;

/** S1 completed; this event is not a statement that S2 or cookie delivery occurred. */
final readonly class IdentitySessionPrepared
{
    public function __construct(public string $lineage, public string $boundary, public int $epoch, public string $nonce) {}
}
