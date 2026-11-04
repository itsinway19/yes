<?php

namespace Tests\Support;

use Illuminate\Support\Str;

/** Finite synthetic declarations, never evidence of a real external identity or right. */
final class PurposeDeclarationFixture
{
    public static function declaration(): array
    {
        return ['meaning' => 'Introduce the business before launch.', 'qualifications' => null, 'phase' => 'PRE_LAUNCH',
            'included_objects' => [['provider' => 'opaque-provider', 'account_namespace' => 'account', 'account_id' => 'Ac-1', 'object_kind' => 'kind', 'object_id' => 'Id-A', 'incarnation' => null]],
            'excluded_objects' => [], 'provenance' => ['speaker' => null, 'statement_at' => null, 'source' => 'Owner statement', 'identity_basis' => 'Owner identifies exact objects belonging to this business', 'authority_basis' => 'Responsible Owner', 'historical_support' => null, 'effective_basis' => 'Applies on acceptance'],
            'dtm' => null, 'intent' => null, 'end_condition' => 'Until launch or withdrawal', 'limitations' => null, 'conflicts' => []];
    }

    public static function command(): array
    {
        return ['command_id' => (string) Str::uuid(), 'purpose_id' => null, 'expected_version' => 0, 'operation' => 'declare',
            'declaration' => self::declaration(), 'effective_from' => null, 'effective_until' => null,
            'supersedes_revision_id' => null, 'affected_revision_ids' => [], 'correction_reason' => null, 'withdrawal_reason' => null,
            'confirmations' => ['meaning' => true, 'scope' => true, 'authority' => true, 'identity' => true, 'standing' => true,
                'dtm' => false, 'intent' => false, 'historical' => false, 'transition' => false]];
    }
}
