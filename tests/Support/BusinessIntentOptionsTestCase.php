<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class BusinessIntentOptionsTestCase extends TestCase
{
    private array $currentBusiness;

    protected function business(): object
    {
        $this->actingAs(User::factory()->create())->withCredentials();
        $this->currentBusiness = $this->postJson('/api/businesses', ['command_id' => (string) Str::uuid(), 'name' => 'Independent intent timezone test'])->assertCreated()->json('data');

        return (object) ['id' => DB::connection('pgsql_migrations')->table('businesses')->where('public_id', $this->currentBusiness['business_public_id'])->value('id'),
            'public_id' => $this->currentBusiness['business_public_id']];
    }

    protected function url(object $business): string
    {
        return '/api/businesses/'.$business->public_id.'/intent';
    }

    protected function command(?array $value, int $version = 0): array
    {
        return ['command_id' => (string) Str::uuid(), 'value' => $value, 'expected_context' => $version, 'expected_intent' => $version,
            'authority_selection' => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null,
                'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (int) $this->currentBusiness['grant_refs']['business.intent.declare']]]],
            'confirmations' => $value === null ? ['declaration' => true] : ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true,
                'clarification_required' => ($value['outcome']['clarification'] ?? null) !== null, 'quantity' => true, 'horizon' => true]];
    }

    protected function value(): array
    {
        return ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => null,
            'outcome' => ['meaning' => 'Completed bookings', 'clarification' => null, 'count_unit' => null], 'quantity' => null, 'horizon' => null];
    }
}
