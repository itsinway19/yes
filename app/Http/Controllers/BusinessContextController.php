<?php

namespace App\Http\Controllers;

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\Workspace;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Security\WorkspaceAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class BusinessContextController
{
    public function entry(Request $request, WorkspaceAuthority $authority): RedirectResponse
    {
        $businesses = $authority->visibleTo($request->user(), (int) $request->session()->get('a001_generation', 0));
        if (count($businesses) === 1) {
            return redirect()->route('workspaces.show', $businesses[0]['public_id']);
        }

        return redirect()->route($businesses ? 'workspaces.index' : 'business.start');
    }

    public function start(Request $request, BusinessContext $business, WorkspaceAuthority $authority): Response|RedirectResponse
    {
        $visible = $authority->visibleTo($request->user(), (int) $request->session()->get('a001_generation', 0));
        if ($visible && ! $request->boolean('new')) {
            return redirect()->route('workspaces.index');
        }
        abort_unless(app(GrantAuthority::class)->has($request->user(), (int) $request->session()->get('a001_generation', 0), 'workspace.create'), 403);
        $key = $request->session()->get('business.creation_key');
        if (! $key || $request->session()->get('business.creation_completed') === $key) {
            $key = (string) Str::uuid();
            $request->session()->put('business.creation_key', $key);
        }

        return Inertia::render('Business/Context', ['workspace' => null, 'types' => $business->types(),
            'canManage' => true, 'canRequestAuthority' => false, 'creationKey' => $key, 'version' => 0]);
    }

    public function store(Request $request, WorkspaceAuthority $authority, BusinessContext $business): RedirectResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120'],
            'business_type_id' => ['required', 'integer', 'min:1'], 'creation_key' => ['required', 'uuid']]);
        abort_unless(hash_equals((string) $request->session()->get('business.creation_key', ''), $input['creation_key']), 403);
        $workspace = $this->mutate(function () use ($request, $authority, $business, $input): Workspace {
            $result = $authority->create($request, Str::squish($input['name']), 'activity-'.$input['creation_key']);
            // Continue the same canonical atomic creation transaction, using its signed selector.
            DB::selectOne('SELECT builderad_business_context_begin(?,?)', [$result['id'], SessionAuthority::sessionKey($request)]);
            $workspace = Workspace::query()->findOrFail($result['id']);
            $business->write($request, $workspace, [...$input, 'version' => 0, 'name' => Str::squish($input['name'])]);

            return $workspace;
        });
        $request->session()->put('business.creation_completed', $input['creation_key']);

        return redirect()->route('workspaces.show', $workspace->public_id);
    }

    public function edit(Request $request, string $workspace, WorkspaceContext $context, BusinessContext $business): Response
    {
        // Catalog presentation data is independent of the protected Workspace
        // snapshot; keep its bounded query outside the Workspace read transaction.
        $timezoneOptions = $business->currentIntentTimezones();

        return $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $business, $timezoneOptions): Response {
            $existingGrant = DB::table('security_action_grants')->where('subject_user_id', $request->user()->id)
                ->where('subject_generation', $request->session()->get('a001_generation'))
                ->where('workspace_id', $w->id)->where('action', 'business.context.manage')->exists();
            $requestable = ! $existingGrant && DB::table('workspace_ownerships')->where('workspace_id', $w->id)
                ->where('responsible_owner_user_id', $request->user()->id)->exists();

            $projection = $business->projection($request, $w);

            return Inertia::render('Business/Context', [
                'workspace' => [...$w->only(['public_id', 'name', 'business_type_id']), 'business_context_version' => $projection['version']],
                'types' => $business->types(), 'canManage' => $business->canManage($request, $w),
                'canRequestAuthority' => $requestable, 'creationKey' => null, 'version' => $projection['version'],
                'business' => $projection, 'countryOptions' => $business->countries(), 'timezoneOptions' => $timezoneOptions,
            ]);
        }, $request);
    }

    public function update(Request $request, string $workspace, WorkspaceContext $context, BusinessContext $business): RedirectResponse
    {
        $input = $request->validate(['business_type_id' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'min:2', 'max:120'], 'version' => ['required', 'integer', 'min:0']]);
        if (isset($input['name'])) {
            $input['name'] = Str::squish($input['name']);
        }
        $this->mutate(fn () => $context->runBusinessMutation($request->user(), $workspace,
            fn (Workspace $w) => $business->write($request, $w, $input), $request));
        $destination = $request->session()->pull('business.intended.'.$workspace);
        // Destination was captured from an authorized product route; its route authorizes again on GET.
        if (is_string($destination) && $this->safeDestination($destination, $workspace)) {
            return redirect()->to($destination);
        }

        return redirect()->route('workspaces.show', $workspace);
    }

    public function authority(Request $request, string $workspace, WorkspaceContext $context, GrantAuthority $grants): RedirectResponse
    {
        $request->validate(['decision' => ['required', 'in:authorize'], 'reason' => ['required', 'in:complete_business_context']]);
        $this->mutate(fn () => $context->run($request->user(), $workspace,
            fn (Workspace $w) => $grants->requestBusinessContextGrant($request, $w->id), $request));

        return redirect()->route('workspaces.business.edit', $workspace);
    }

    public function updateCurrentIntent(Request $request, string $workspace, WorkspaceContext $context, BusinessContext $business): RedirectResponse
    {
        $invalid = app()->getLocale() === 'ar'
            ? 'أرسل إعلانًا كاملًا وتأكيدات واضحة ضمن حدود الإدخال. لا تُحذف أو تُختصر أي قيمة تلقائيًا.'
            : 'Submit a complete declaration and explicit confirmations within the input limits. Values are never silently truncated.';
        $oversized = app()->getLocale() === 'ar'
            ? 'حجم الطلب يتجاوز حد الإدخال التقني. احتفظ بالمسودة وقلّل حجمها قبل الحفظ.'
            : 'The request exceeds the technical input limit. Keep your draft and reduce its size before saving.';
        abort_if(strlen($request->getContent()) > 81920, 413, $oversized);
        try {
            $payload = $request->isJson() ? json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $payload = null;
        }
        $keys = is_object($payload) ? array_keys(get_object_vars($payload)) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['command', 'confirmations', 'value', 'version'] || ! is_object($payload->confirmations)
            || ! is_int($payload->version) || $payload->version < 0 || ! in_array($payload->command, ['replace', 'clear'], true)
            || ($payload->command === 'clear' ? $payload->value !== null : ! is_object($payload->value))) {
            throw ValidationException::withMessages(['value' => $invalid]);
        }
        foreach (get_object_vars($payload->confirmations) as $key => $confirmed) {
            if (! in_array($key, ['declaration', 'business_wide', 'outcome', 'acceptance_clear', 'clarification_required', 'quantity', 'horizon'], true)
                || ! is_bool($confirmed)) {
                throw ValidationException::withMessages(['confirmations' => $invalid]);
            }
        }
        if ($payload->command === 'replace') {
            if (! is_object($payload->value->outcome ?? null)
                || ! property_exists($payload->value, 'horizon')
                || ($payload->value->horizon !== null && ! is_object($payload->value->horizon))) {
                throw ValidationException::withMessages(['value' => $invalid]);
            }
        }
        // Validate the original body, not middleware-trimmed/coerced input.
        // PostgreSQL compares the submitted horizon with serialized CURRENT state;
        // there is intentionally no unconditional selectable-zone membership rule.
        $input = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $rules = [];
        if ($payload->command === 'replace') {
            $rules = [
                'value' => ['required', 'array:direction,scope,description,outcome,quantity,horizon'],
                'value.direction' => ['required', 'string', Rule::in(['increase_value', 'preserve_value', 'improve_efficiency_preserving_value', 'validate_business_direction'])],
                'value.scope' => ['required', 'string', Rule::in(['business_wide'])],
                'value.description' => ['present', 'nullable', 'string', 'max:2000'],
                'value.outcome' => ['required', 'array:meaning,clarification,count_unit'],
                'value.outcome.meaning' => ['required', 'string', 'max:2000'],
                'value.outcome.clarification' => ['present', 'nullable', 'string', 'max:2000'],
                'value.outcome.count_unit' => ['present', 'nullable', 'string', 'max:120', Rule::requiredIf(($input['value']['quantity'] ?? null) !== null)],
                'value.quantity' => ['present', 'nullable', 'string', 'max:120', 'regex:/^(?=.*[1-9])[0-9]+$/D'],
                'value.horizon' => ['present', 'nullable', 'array:deadline_date,timezone'],
            ];
            if ($payload->value->horizon !== null) {
                $rules['value.horizon.timezone'] = ['required', 'string', 'max:120', 'regex:~^(?:UTC|[A-Za-z0-9_+\-]+(?:/[A-Za-z0-9_+\-]+)+)$~D'];
                $rules['value.horizon.deadline_date'] = ['required', 'string', function ($attribute, $value, $fail) use ($invalid): void {
                    if (! is_string($value) || ! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts)
                        || (int) $parts[1] < 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                        $fail($invalid);
                    }
                }];
            }
        }
        $messages = array_fill_keys(array_map(fn ($key) => $key.'.*', array_keys($rules)), $invalid);
        \Illuminate\Support\Facades\Validator::make($input, $rules, $messages)->validate();
        try {
            $context->runBusinessMutation($request->user(), $workspace,
                fn (Workspace $w) => $business->replaceCurrentIntent($request, $w, $input['value'], $input['confirmations'], $input['version']), $request);
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '40001') {
                throw ValidationException::withMessages(['version' => app()->getLocale() === 'ar'
                    ? 'تغيّر سياق النشاط. احتفظ بالمسودة وراجع السياق الحالي قبل اعتماد المراجعة وإعادة التأكيد.'
                    : 'Business context changed. Keep your draft and review the current context before adopting its revision and reconfirming.']);
            }
            if (in_array($error->getCode(), ['22023', '22P05'], true)) {
                throw ValidationException::withMessages(['value' => $invalid]);
            }
            throw $error;
        }

        return redirect()->route('workspaces.business.edit', $workspace);
    }

    public function updateTargetMarkets(Request $request, string $workspace, WorkspaceContext $context, BusinessContext $business): RedirectResponse
    {
        // Preserve JSON list/object shape before Laravel's associative decoding makes [] and {} identical.
        if ($request->isJson()) {
            $payload = json_decode($request->getContent());
            if (! is_object($payload) || ! property_exists($payload, 'country_codes') || ! is_array($payload->country_codes)) {
                throw ValidationException::withMessages(['country_codes' => app()->getLocale() === 'ar'
                    ? 'أرسل قائمة رموز الدول، ويمكن أن تكون فارغة.' : 'Submit a list of country codes; the list may be empty.']);
            }
        }
        $countries = array_column($business->countries(), 'code');
        try {
            $input = $request->validate([
                'country_codes' => ['present', 'array', 'list', 'max:'.count($countries)],
                'country_codes.*' => ['required', 'string', Rule::in($countries)],
                'version' => ['required', 'integer', 'min:0'],
            ]);
        } catch (ValidationException $error) {
            $messages = [];
            foreach ($error->errors() as $field => $errors) {
                $key = str_starts_with($field, 'country_codes.') ? 'country_codes' : $field;
                $messages[$key] = array_merge($messages[$key] ?? [], $errors);
            }
            throw ValidationException::withMessages($messages);
        }
        try {
            $context->runBusinessMutation($request->user(), $workspace,
                fn (Workspace $w) => $business->replaceTargetMarkets($request, $w, $input['country_codes'], (int) $input['version']), $request);
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if ($error->getCode() === '40001') {
                throw ValidationException::withMessages(['version' => app()->getLocale() === 'ar'
                    ? 'تغيّر سياق النشاط. أعد تحميل الصفحة وراجع الأسواق قبل الحفظ.'
                    : 'Business context changed. Reload and review the markets before saving.']);
            }
            if (in_array($error->getCode(), ['22023', '23503', '23514', '23505'], true)) {
                throw ValidationException::withMessages(['country_codes' => app()->getLocale() === 'ar'
                    ? 'تعذّر حفظ الأسواق. تحقّق من رموز الدول.' : 'Could not save markets. Check the country codes.']);
            }
            throw $error;
        }

        return redirect()->route('workspaces.business.edit', $workspace);
    }

    public function safeDestination(string $destination, string $workspace): bool
    {
        $path = parse_url($destination, PHP_URL_PATH);

        return ! preg_match('/[\x00-\x20\\\\]/', $destination) &&
            parse_url($destination, PHP_URL_HOST) === null &&
            is_string($path) && ($path === '/workspaces/'.$workspace || $path === '/workspaces/'.$workspace.'/foundation');
    }

    private function mutate(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                abort(403);
            }
            if (in_array($error->getCode(), ['22023', '23503', '23514', '23505', '40001'], true)) {
                throw ValidationException::withMessages(['business_type_id' => 'تعذّر حفظ سياق النشاط. تحقّق من النوع وأعد تحميل الصفحة إذا تغيّرت البيانات.']);
            }
            throw $error;
        }
    }
}
