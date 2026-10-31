<?php

namespace App\Platform;

use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared operational admission state. Never an identity, Business right or deployment switch. */
final class PlatformMaintenance
{
    public const ACTION = 'platform.maintenance.manage';

    public function current(): array
    {
        // Availability requires the authoritative singleton. Missing schema or
        // state must never silently reopen a paused application.
        return $this->describe(DB::table('platform_maintenance')->where('id', 1)->sole());
    }

    public function allowed(Request $request): bool
    {
        return $request->is('internal/*') && $request->user()
            && app(GrantAuthority::class)->has($request->user(), (int) $request->session()->get('a001_generation', 0), self::ACTION);
    }

    public function change(Request $request, array $input): array
    {
        $db = DB::connection('pgsql_authority');

        return $db->transaction(function () use ($db, $request, $input): array {
            // Current principal middleware owns the enclosing authority/session
            // transaction. Lock the state once, then recheck after any wait.
            $prior = $db->table('platform_maintenance')->where('id', 1)->lockForUpdate()->sole();
            abort_unless($this->allowed($request), 403);
            app(SessionAuthority::class)->assertCurrentAuthority($request, $request->user(), (int) $request->session()->get('a001_generation'));
            abort_unless(app(SessionAuthority::class)->hasFreshAuthentication($request, $request->user()), 403);
            $fingerprint = hash('sha256', json_encode([$request->user()->id, $request->session()->get('a001_generation'),
                $input['revision'], $input['active'], $input['message_ar'], $input['message_en'], $input['retry_after'], $input['reason']], JSON_THROW_ON_ERROR));
            $known = $db->table('platform_maintenance_commands')->where('command_id', $input['command_id'])->first();
            if ($known) {
                if (! hash_equals($known->fingerprint, $fingerprint)) {
                    throw new ConflictHttpException('Command payload changed.');
                }

                return json_decode($known->result, true, flags: JSON_THROW_ON_ERROR);
            }
            if ((int) $prior->revision !== $input['revision']) {
                throw new ConflictHttpException('Platform status changed. Review the current status.');
            }
            $db->table('platform_maintenance')->where('id', 1)->update([
                'revision' => $prior->revision + 1, 'active' => $input['active'], 'message_ar' => $input['message_ar'],
                'message_en' => $input['message_en'], 'retry_after' => $input['retry_after'], 'changed_at' => DB::raw('clock_timestamp()'),
            ]);
            $result = ['saved' => true, 'command_id' => $input['command_id'],
                'maintenance' => $this->describe($db->table('platform_maintenance')->where('id', 1)->sole())];
            $db->table('platform_maintenance_commands')->insert([
                'command_id' => $input['command_id'], 'actor_user_id' => $request->user()->id,
                'actor_generation' => $request->session()->get('a001_generation'), 'fingerprint' => $fingerprint,
                'previous_revision' => $prior->revision, 'resulting_revision' => $prior->revision + 1,
                'reason' => $input['reason'], 'result' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);

            return json_decode($db->table('platform_maintenance_commands')->where('command_id', $input['command_id'])->value('result'), true, flags: JSON_THROW_ON_ERROR);
        });
    }

    private function describe(object $row): array
    {
        return ['revision' => (int) $row->revision, 'active' => (bool) $row->active,
            'message_ar' => $row->message_ar, 'message_en' => $row->message_en,
            'retry_after' => (int) $row->retry_after, 'changed_at' => $row->changed_at];
    }
}
