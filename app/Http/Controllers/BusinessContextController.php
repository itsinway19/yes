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
            DB::selectOne('SELECT builderad_select_workspace_context(?)', [$result['id']]);
            $workspace = Workspace::query()->findOrFail($result['id']);
            $business->write($request, $workspace, [...$input, 'version' => 0, 'name' => Str::squish($input['name'])]);
            return $workspace;
        });
        $request->session()->put('business.creation_completed', $input['creation_key']);
        return redirect()->route('workspaces.show', $workspace->public_id);
    }

    public function edit(Request $request, string $workspace, WorkspaceContext $context, BusinessContext $business): Response
    {
        return $context->run($request->user(), $workspace, function (Workspace $w) use ($request, $business): Response {
            $existingGrant = DB::table('security_action_grants')->where('subject_user_id', $request->user()->id)
                ->where('subject_generation', $request->session()->get('a001_generation'))
                ->where('workspace_id', $w->id)->where('action', 'business.context.manage')->exists();
            $requestable = ! $existingGrant && DB::table('workspace_ownerships')->where('workspace_id', $w->id)
                ->where('responsible_owner_user_id', $request->user()->id)->exists();
            return Inertia::render('Business/Context', [
                'workspace' => $w->only(['public_id', 'name', 'business_type_id', 'business_context_version']),
                'types' => $business->types(), 'canManage' => $business->canManage($request, $w),
                'canRequestAuthority' => $requestable, 'creationKey' => null, 'version' => $w->business_context_version,
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
        $this->mutate(fn () => $context->run($request->user(), $workspace,
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
