<?php

namespace App\Commercial;

use App\Foundation\Tenancy\PrincipalContext;
use App\Security\SessionAuthority;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CommercialContext
{
    public function __construct(private readonly PrincipalContext $principals, private readonly SessionAuthority $sessions) {}

    public function run(Request $request, Closure $operation, bool $mutation = false): mixed
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            throw new AuthorizationException('Current verified commercial principal required.');
        }
        $run = function () use ($request, $operation, $mutation): mixed {
            $generation = (int) $request->session()->get('a001_generation', 0);
            if ($mutation) {
                // The existing P4-A mutation transaction owns lifecycle/session invalidation locks.
                if (DB::connection('pgsql_authority')->transactionLevel() < 1) {
                    throw new LogicException('Commercial mutation requires protected P4-A session transaction.');
                }
                $this->sessions->assertCurrentAuthority($request, $request->user(), $generation);
                if (! $this->sessions->hasFreshAuthentication($request, $request->user())) {
                    throw new AuthorizationException('Fresh internal authentication required.');
                }
            } else {
                $this->sessions->assertCurrent($request, $request->user(), $generation);
            }
            $this->principals->activate($request->user());

            $result = $operation();
            if ($mutation) {
                // Complements the SQL effect-time grant fence: the protected session
                // must also remain current/fresh after the authoritative operation's waits.
                $this->sessions->assertCurrentAuthority($request, $request->user(), $generation);
                if (! $this->sessions->hasFreshAuthentication($request, $request->user())) {
                    throw new AuthorizationException('Authentication expired during the commercial operation.');
                }
            }

            return $result;
        };
        try {
            return DB::transactionLevel() === 0 ? DB::transaction($run) : $run();
        } catch (QueryException $exception) {
            if ($exception->getCode() === '42501') {
                throw new AuthorizationException('Exact current commercial target authority required.', previous: $exception);
            }
            if ($exception->getCode() === '23505') {
                throw new HttpException(409, 'تعارض في الأمر أو المراجعة أو تسجيل سابق؛ افحص الإيصال وحدّث المراجعة قبل إصدار أمر جديد.', $exception);
            }
            if (in_array($exception->getCode(), ['23503', '23514'], true)) {
                throw ValidationException::withMessages(['commercial' => 'مرجع السوق أو الإصدار أو Catalog أو سياق العملة غير صالح أو غير متاح للإسناد.']);
            }
            throw $exception;
        }
    }
}
