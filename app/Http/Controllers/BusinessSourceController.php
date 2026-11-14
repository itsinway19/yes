<?php

namespace App\Http\Controllers;

use App\Http\Responses\SpaPage;
use App\Providers\Ads\NativeSourceContext;
use App\Providers\Ads\NativeSourceOwner;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Sources\SourceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BusinessSourceController
{
    public function index(Request $request, string $business, NativeSourceContext $context, SourceRegistry $registry): SpaPage
    {
        return $this->qualified($request, $business, $context, function (NativeSourceOwner $owner) use ($request, $registry): SpaPage {
            $sources = $registry->projection($request, $owner->businessId);
            $request->attributes->set('builderad.qualified_visibility', hash('sha256', json_encode([$owner->publicId, $owner->selection(), $sources], JSON_THROW_ON_ERROR)));
            if (! SpaPage::needsData($request)) {
                return new SpaPage([]);
            }

            return new SpaPage([
                'business' => ['public_id' => $owner->publicId],
                'canRequestSourceAuthority' => app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user()),
                'sourceCandidateId' => (string) Str::uuid7(),
                'sources' => $sources,
            ]);
        });
    }

    public function command(Request $request, string $business, NativeSourceContext $context, SourceRegistry $registry): JsonResponse
    {
        $input = $request->validate([...$this->scopeRules(), 'command_id' => ['required', 'uuid'],
            'effect' => ['required', Rule::in(['register', 'rename', 'declare_scope', 'revoke'])],
            'expected_version' => ['required', 'integer', 'min:0'],
            'family' => ['required_if:effect,register', 'prohibited_unless:effect,register', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/'],
            'namespace' => ['required_if:effect,register', 'prohibited_unless:effect,register', 'string', 'max:80'],
            'external_id' => ['required_if:effect,register', 'prohibited_unless:effect,register', 'string', 'max:180'],
            'label' => ['required_if:effect,register,rename', 'prohibited_if:effect,revoke,declare_scope', 'string', 'max:120']]);
        $this->rejectUnknownFields($request, $input);

        return response()->json($this->qualified($request, $business, $context,
            fn (NativeSourceOwner $owner) => $registry->command($request, $owner->businessId, $owner->publicId, $owner->selection(), $input)));
    }

    public function grant(Request $request, string $business, NativeSourceContext $context): JsonResponse
    {
        $input = $request->validate([...$this->scopeRules(), 'command_id' => ['required', 'uuid'], 'action' => ['required', Rule::in(GrantAuthority::SOURCE_ACTIONS)],
            'decision' => ['required', Rule::in(['authorize', 'revoke'])], 'expires_at' => ['nullable', 'date']]);
        if ($input['decision'] === 'revoke' && ! empty($input['expires_at'])) {
            throw ValidationException::withMessages(['expires_at' => 'Expiry is not part of a revocation decision.']);
        }
        $this->rejectUnknownFields($request, $input);
        $result = $this->qualified($request, $business, $context, fn (NativeSourceOwner $owner) => DB::selectOne(
            'SELECT builderad_business_source_authority(?, ?, ?::jsonb) AS result',
            [$owner->businessId, SessionAuthority::sessionKey($request), json_encode($input, JSON_THROW_ON_ERROR)]));

        return response()->json(['decision' => $input['decision'], 'receipt' => json_decode($result->result, true, 512, JSON_THROW_ON_ERROR)]);
    }

    public function recoverBusiness(Request $request, string $business, string $obligation, NativeSourceContext $context, SourceRegistry $registry): JsonResponse
    {
        return response()->json($this->qualified($request, $business, $context,
            fn (NativeSourceOwner $owner) => $registry->recoverBusiness($request, $owner->businessId, $obligation)));
    }

    private function qualified(Request $request, string $business, NativeSourceContext $context, callable $effect): mixed
    {
        try {
            return $context->run($request->user(), $business, $effect(...), $request);
        } catch (QueryException $error) {
            abort_if($error->getCode() === '42501', 403);
            abort_if(in_array($error->getCode(), ['23505', '40001'], true), 409, 'Source command or version conflict.');
            if (in_array($error->getCode(), ['22023', '22P02', '23514', '23502'], true)) {
                throw ValidationException::withMessages(['source' => 'Invalid source state or declaration.']);
            }
            throw $error;
        }
    }

    private function rejectUnknownFields(Request $request, array $validated): void
    {
        if (array_diff(array_keys($request->except('_token')), array_keys($validated))) {
            throw ValidationException::withMessages(['source' => 'Unknown source command fields.']);
        }
    }

    private function scopeRules(): array
    {
        return ['source_id' => ['required', 'uuid'], 'resource' => ['required', 'string', 'max:180'],
            'operation' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]{0,79}$/'],
            'purpose' => ['required', 'string', 'regex:/^[a-z][a-z0-9_.]{0,127}$/'],
            'reason' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{2,79}$/']];
    }
}
