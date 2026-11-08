<?php

namespace App\Foundation\Business;

use App\Business\AdvertiserGoalPreferences;
use App\Business\ObjectPurpose;
use App\Commercial\RegisterCommercialScope;
use App\Foundation\Tenancy\PrincipalContext;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Owns the clean transaction; Business identity never comes from WorkspaceContext. */
final class BusinessScope
{
    public function __construct(private readonly PrincipalContext $principals) {}

    public function practices(Request $request, string $business, int $access, ?string $practice = null): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_practices(?,?::uuid,?,?::uuid)',
            [SessionAuthority::sessionKey($request), $business, $access, $practice],
        )));
    }

    public function writePractice(Request $request, string $business, array $body): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_practice_write(?,?::uuid,?::uuid,?::uuid,?,?,?::jsonb,?::jsonb,?::jsonb,?)',
            [SessionAuthority::sessionKey($request), $business, $body['command_id'], $body['practice_id'], $body['operation'],
                $body['expected_revision'], json_encode($body['value'], JSON_THROW_ON_ERROR),
                json_encode($body['authority_selection'], JSON_THROW_ON_ERROR), json_encode($body['read_selection'], JSON_THROW_ON_ERROR), $body['correction_reason'] ?? null],
        )));
    }

    public function practiceKnowledgeOptions(Request $request, string $business, int $access, int $after): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_practice_knowledge_options(?,?::uuid,?,?)',
            [SessionAuthority::sessionKey($request), $business, $access, $after],
        )));
    }

    public function inspectPracticeKnowledge(Request $request, string $business, array $body): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_customer_discovery_practice(?,?::uuid,?::uuid,?::uuid,?,?::jsonb,?,?::jsonb,?::jsonb,?::jsonb,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $body['command_id'], $body['practice_id'], $body['expected_revision'],
                json_encode($body['material_ref'], JSON_THROW_ON_ERROR), $body['purpose'], json_encode($body['relation'], JSON_THROW_ON_ERROR),
                json_encode($body['question'], JSON_THROW_ON_ERROR), json_encode($body['read_selection'], JSON_THROW_ON_ERROR),
                json_encode($body['authority_selection'], JSON_THROW_ON_ERROR)],
        )));
    }

    public function choices(Request $request, int $after = 0): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_choices(?,?)', [SessionAuthority::sessionKey($request), $after],
        )));
    }

    public function catalog(Request $request): array
    {
        return $this->run($request, function () use ($request): array {
            return $this->decorateCatalog($this->decode(DB::scalar(
                'SELECT builderad_v2_business_catalog(?)', [SessionAuthority::sessionKey($request)],
            )));
        });
    }

    public function boundaries(Request $request, int $after = 0): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_boundary_choices(?,?)', [SessionAuthority::sessionKey($request), $after],
        )));
    }

    public function sources(Request $request, int $after = 0): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_source_choices(?,?)', [SessionAuthority::sessionKey($request), $after],
        )));
    }

    public function businessSources(Request $request, string $business, int $readGrant, int $after = 0): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_source_choices(?,?::uuid,?,?)',
            [SessionAuthority::sessionKey($request), $business, $readGrant, $after],
        )));
    }

    public function dataUseChoices(Request $request, int $after = 0): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_data_use_choices(?,?)', [SessionAuthority::sessionKey($request), $after],
        )));
    }

    public function dataLicenseJourney(Request $request, string $business, int $access): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar('SELECT builderad_v2_data_license_journey(?,?::uuid,?)',
            [SessionAuthority::sessionKey($request), $business, $access])));
    }

    public function operatingMandate(Request $request, string $business, object $body, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar('SELECT builderad_v2_business_operating_mandate(?,?::uuid,?::uuid,?::boolean,?,?,?::timestamptz,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $body->command_id, $body->confirmed, $body->capacity, $body->authority_reference, $body->valid_until,
                $body->previous_grant, json_encode($selection, JSON_THROW_ON_ERROR)])));
    }

    public function dataUseBeneficiary(Request $request, string $business, object $body, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar('SELECT builderad_v2_data_use_beneficiary(?,?::uuid,?::uuid,?::uuid,?,?::uuid,?::timestamptz,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $body->command_id, $body->license_id, $body->effect, $body->recipient_public_id,
                $body->valid_until, $body->expected_revision, json_encode($selection, JSON_THROW_ON_ERROR)])));
    }

    public function expenditureSummary(Request $request, string $business, object $body, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_expenditure_summary(?,?::uuid,?::uuid,?::uuid,?::jsonb,?::uuid,?::uuid,?::boolean,?::timestamptz,?::jsonb,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $body->command_id, $body->summary_id,
                json_encode($body->input_ref, JSON_THROW_ON_ERROR), $body->input_license_id, $body->read_command_id, $body->confirmed, $body->valid_until,
                json_encode($body->consent, JSON_THROW_ON_ERROR), json_encode($selection, JSON_THROW_ON_ERROR)]
        )));
    }

    public function source(Request $request, string $source, int $access): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_source_surface(?,?::uuid,?)', [SessionAuthority::sessionKey($request), $source, $access],
        )));
    }

    public function boundary(Request $request, string $boundary, int $access): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_boundary_surface(?,?::uuid,?)', [SessionAuthority::sessionKey($request), $boundary, $access],
        )));
    }

    public function surface(Request $request, string $business, int $readGrant): array
    {
        return $this->run($request, function () use ($request, $business, $readGrant): array {
            $surface = $this->decode(DB::scalar(
                'SELECT builderad_v2_business_surface(?,?::uuid,?)', [SessionAuthority::sessionKey($request), $business, $readGrant],
            ));
            // SQL qualifies the resource before catalog reads and rechecks after every wait.
            // Decoration is in-memory only: no authority-sensitive I/O after that check.
            $surface['catalog'] = $this->decorateCatalog($surface['catalog']);
            $surface['commercial'] = app(RegisterCommercialScope::class)->businessProjection($business);

            return $surface;
        });
    }

    public function history(Request $request, string $business, int $readGrant, string $kind, int $after = 0): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_history(?,?::uuid,?,?,?)',
            [SessionAuthority::sessionKey($request), $business, $readGrant, $kind, $after],
        )));
    }

    public function legacyHistory(Request $request, string $business, int $readGrant, string $kind, ?string $after = null): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_legacy_history(?,?::uuid,?,?,?::uuid)',
            [SessionAuthority::sessionKey($request), $business, $readGrant, $kind, $after],
        )));
    }

    private function decorateCatalog(array $catalog): array
    {
        $candidates = [...\DateTimeZone::listIdentifiers(\DateTimeZone::ALL), 'UTC'];
        $catalog['intent_timezones'] = array_values(array_intersect($catalog['intent_timezones'], $candidates));
        sort($catalog['intent_timezones'], SORT_STRING);
        $catalog['goals'] = AdvertiserGoalPreferences::catalog();

        return $catalog;
    }

    public function purposes(Request $request, string $business, int $readGrant, ?string $purpose = null, ?\DateTimeImmutable $effectiveAt = null, ?\DateTimeImmutable $knownAt = null): array
    {
        return $this->run($request, function () use ($request, $business, $readGrant, $purpose, $effectiveAt, $knownAt): array {
            $snapshot = $this->decode(DB::scalar('SELECT builderad_v2_business_purpose_snapshot(?,?::uuid,?,?::uuid)',
                [SessionAuthority::sessionKey($request), $business, $readGrant, $purpose]));
            $now = new \DateTimeImmutable($snapshot['read_at']);
            abort_if(! $snapshot['history_available'] && ($effectiveAt !== null || $knownAt !== null), 403, 'Current declaration access does not release temporal history.');
            $knownAt ??= $now;
            if ($knownAt > $now) {
                throw ValidationException::withMessages(['known_at' => 'Knowledge time cannot be in the future.']);
            }
            $heads = array_map(static fn ($row) => (object) $row, $snapshot['heads']);
            $projection = app(ObjectPurpose::class)->projectHistory($heads, $snapshot['context_version'], $snapshot['dtm_version'], $effectiveAt ?? $now, $knownAt, historyAvailable: $snapshot['history_available']);

            return ['business_public_id' => $business, 'scope' => $snapshot['scope'], 'authority_scope' => 'business_declaration_only', 'establishes_external_authority' => false, 'projection' => $projection];
        });
    }

    public function writePurpose(Request $request, string $business, array $command, int $expectedContext, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_purpose_write(?,?::uuid,?::jsonb,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, json_encode($command, JSON_THROW_ON_ERROR), $expectedContext, json_encode($selection, JSON_THROW_ON_ERROR)],
        )) + ['authority_scope' => 'business_declaration_only', 'establishes_external_authority' => false]);
    }

    public function create(Request $request, string $command, string $name, ?int $type): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_create_draft(?,?::uuid,?,?)',
            [SessionAuthority::sessionKey($request), $command, $name, $type],
        )));
    }

    public function enrollDirectTeam(Request $request, string $business, string $command, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_team_enroll(?,?::uuid,?::uuid,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function enrollPurposeStatement(Request $request, string $business, string $command, bool $confirmed, string $previous, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_purpose_enroll(?,?::uuid,?::uuid,?,?::bigint,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, $confirmed, $previous, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function internalTeam(Request $request, string $business, string $command, string $workspace, string $effect, ?string $recipient, int $expected, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_internal_team_command(?,?::uuid,?::uuid,?::uuid,?,?::uuid,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, $workspace, $effect, $recipient, $expected, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function internalTeamSurface(Request $request, string $business, int $read): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_internal_team_surface(?,?::uuid,?)', [SessionAuthority::sessionKey($request), $business, $read],
        )));
    }

    public function read(Request $request, string $business, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_read(?,?::uuid,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function link(Request $request, string $business, string $workspace, string $command, string $effect, int $expected, array $selection, string $kind = 'collaboration'): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_link_command(?,?::uuid,?::uuid,?::uuid,?,?,?::jsonb,?)',
            [SessionAuthority::sessionKey($request), $business, $workspace, $command, $effect, $expected, json_encode($selection, JSON_THROW_ON_ERROR), $kind],
        )));
    }

    public function context(Request $request, string $business, string $command, string $name, ?int $type, int $expected, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_context_write(?,?::uuid,?::uuid,?,?,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, $name, $type, $expected, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function markets(Request $request, string $business, string $command, array $codes, int $expected, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_markets_write(?,?::uuid,?::uuid,ARRAY(SELECT jsonb_array_elements_text(?::jsonb)),?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, json_encode($codes, JSON_THROW_ON_ERROR), $expected, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function preferences(Request $request, string $business, string $command, array|\stdClass $value, int $expected, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_preferences_write(?,?::uuid,?::uuid,?::jsonb,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, json_encode($value, JSON_THROW_ON_ERROR), $expected, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function intent(Request $request, string $business, string $command, array|\stdClass|null $value, array $confirmations, int $context, int $intent, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_intent_write(?,?::uuid,?::uuid,?::jsonb,?::jsonb,?,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, json_encode($value, JSON_THROW_ON_ERROR), json_encode($confirmations, JSON_THROW_ON_ERROR), $context, $intent, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function grant(Request $request, string $business, string $command, string $effect, string $recipient, string $action, ?int $parent, ?int $grant, int $expected, ?string $expires, bool $delegable, ?string $realm, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_grant_command(?,?::uuid,?::uuid,?,?::uuid,?,?,?,?,?::timestamptz,?,?::uuid,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, $effect, $recipient, $action, $parent, $grant,
                $expected, $expires, $delegable ? 'true' : 'false', $realm, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function resourceRight(Request $request, string $command, string $kind, string $target, string $effect, \stdClass $payload, int $expected, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_resource_right_command(?,?::uuid,?,?::uuid,?,?::jsonb,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $command, $kind, $target, $effect,
                json_encode($payload, JSON_THROW_ON_ERROR), $expected, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function dataUseLicense(Request $request, string $business, string $command, string $license, string $effect, int $expected, \stdClass $declaration, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_data_use_license(?,?::uuid,?::uuid,?::uuid,?,?,?::jsonb,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, $license, $effect, $expected,
                json_encode($declaration, JSON_THROW_ON_ERROR), json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function bindDataUse(Request $request, string $business, string $command, string $scope, string $effect, int $expected, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_data_use_binding(?,?::uuid,?::uuid,?::uuid,?,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, $scope, $effect, $expected, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function material(Request $request, string $business, string $command, array $refs, string $operation, string $purpose, string $recipient, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_scoped_material_read(?,?::uuid,?::uuid,?::jsonb,?,?,?::uuid,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, json_encode($refs, JSON_THROW_ON_ERROR),
                $operation, $purpose, $recipient, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function publish(Request $request, string $business, string $command, array $artifact, array $dependencies, string $purpose, string $recipient, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_scoped_material_publish(?,?::uuid,?::uuid,?::jsonb,?::jsonb,?,?::uuid,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, json_encode($artifact, JSON_THROW_ON_ERROR), json_encode($dependencies, JSON_THROW_ON_ERROR),
                $purpose, $recipient, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    public function lifecycle(Request $request, string $business, string $command, string $effect, ?string $target, \stdClass $payload, int $expected, array $selection): array
    {
        return $this->run($request, fn () => $this->decode(DB::scalar(
            'SELECT builderad_v2_business_lifecycle_command(?,?::uuid,?::uuid,?,?::uuid,?::jsonb,?,?::jsonb)',
            [SessionAuthority::sessionKey($request), $business, $command, $effect, $target,
                json_encode($payload, JSON_THROW_ON_ERROR), $expected, json_encode($selection, JSON_THROW_ON_ERROR)],
        )));
    }

    private function run(Request $request, callable $command): array
    {
        if (DB::connection()->transactionLevel() !== 0 || ! $request->user()) {
            throw new LogicException('Business command requires its own authenticated transaction.');
        }

        return DB::transaction(function () use ($request, $command): array {
            $this->principals->activate($request->user());

            return $command();
        }, 1);
    }

    private function decode(string $value): array
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
