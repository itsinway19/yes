<?php

namespace App\Notifications;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Keep authoritative changes committed even when the mail queue is unavailable. */
final class SecurityMail
{
    public static function afterCommit(string $type, Closure $dispatch): void
    {
        DB::connection('pgsql_authority')->afterCommit(static function () use ($type, $dispatch): void {
            try {
                $dispatch();
            } catch (Throwable $exception) {
                Log::error('security_mail_enqueue_failed', [
                    'type' => $type, 'error_class' => $exception::class,
                    'error_code' => (string) $exception->getCode(),
                ]);
            }
        });
    }
}
