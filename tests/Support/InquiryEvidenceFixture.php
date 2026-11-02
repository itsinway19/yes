<?php

namespace Tests\Support;

use App\Commercial\InquiryEvidence;
use App\Foundation\Business\BusinessScope;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Tests real own-statement enrollment; no synthetic new-policy roots. */
trait InquiryEvidenceFixture
{
    protected function requestFor(User $user): Request
    {
        $this->actingAs($user);
        $request = Request::create('/api/businesses');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }

    protected function selection(string|int $grant): array
    {
        return ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'business', 'leaf_grant_id' => (string) $grant]]];
    }

    protected function fixture(): array
    {
        $user = User::factory()->create();
        $request = $this->requestFor($user);
        $draft = app(BusinessScope::class)->create($request, (string) Str::uuid(), 'Own declared activity', null);
        $business = $draft['business_public_id'];
        $basis = $this->selection($draft['grant_refs']['business.context.read']);
        $evidence = app(InquiryEvidence::class);
        $eligibility = $evidence->eligibility($request, $business, $basis);
        $enrolled = $evidence->enroll($request, $business, (string) Str::uuid(), $eligibility['basis_revision'], true, $basis);

        return compact('user', 'request', 'draft', 'business', 'basis', 'eligibility', 'enrolled', 'evidence');
    }

    protected function value(): array
    {
        return ['cohort' => ['channel_kind' => 'form', 'definition_version' => 1, 'country_code' => 'SA', 'population' => 'recorded_inquiries', 'counting_policy' => 'distinct_inquiries'],
            'observation' => ['period_start' => '2020-01-01T00:00:00Z', 'period_end' => '2020-01-02T00:00:00Z', 'as_of' => '2020-01-03T00:00:00Z', 'timezone' => 'Asia/Riyadh'],
            'cost' => ['amount' => '1000.00', 'currency' => 'SAR', 'precision' => 2, 'cost_basis' => 'declared_cohort_cost', 'allocation_basis' => 'whole_cohort'],
            'inquiries' => ['count' => 100, 'coverage' => 'complete'],
            'acceptance' => ['count' => 10, 'pending_count' => 0, 'criterion_code' => 'operator_defined', 'criterion_version' => 1, 'criterion_definition' => 'Inquiry explicitly requests an available service', 'cutoff' => '2020-01-03T00:00:00Z', 'maturity' => 'mature', 'coverage' => 'complete']];
    }

    protected function denied(callable $operation, string $state = '42501'): void
    {
        try {
            $operation();
            $this->fail('Expected bounded inquiry rejection.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->errorInfo[0]);
        }
    }
}
