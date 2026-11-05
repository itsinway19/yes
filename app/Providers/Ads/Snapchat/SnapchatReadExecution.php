<?php

namespace App\Providers\Ads\Snapchat;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderRequestGuard;
use App\Providers\Ads\ProviderScope;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Immutable, encrypted Snapchat execution receipts atomically paired with the common journal. */
final class SnapchatReadExecution
{
    public function execute(Request $request, ProviderScope $scope, string $stream, string $command,
        int $accessVersion, int $generation, string $fingerprint, \Closure $read): array
    {
        if ($scope->provider !== Provider::Snapchat || $scope->operation !== 'report.read'
            || DB::connection()->transactionLevel() !== 1) {
            throw new LogicException('Snapchat execution must own a signed exact report transaction.');
        }
        app(ProviderRequestGuard::class)->requireCustody($request, $scope, 'provider.read');
        $context = [$scope->workspaceId, SessionAuthority::sessionKey($request), $scope->sourceId, $scope->accountId,
            $scope->purpose, $stream, $command, $accessVersion, $generation, $fingerprint];
        $ciphertext = DB::scalar('SELECT builderad_snapchat_attempt_begin(?, ?, ?::uuid, ?, ?, ?, ?::uuid, ?, ?, ?)', $context);
        $binding = [$scope->jsonSerialize(), $stream, $command, $accessVersion, $generation, $fingerprint];
        if ($ciphertext !== null) {
            try {
                $envelope = json_decode(Crypt::decryptString($ciphertext), true, 32, JSON_THROW_ON_ERROR);
            } catch (\Exception) {
                throw new AuthorizationException('Snapchat execution receipt integrity failed.');
            }
            if (! is_array($envelope) || ($envelope['binding'] ?? null) !== $binding || ! is_array($envelope['result'] ?? null)) {
                throw new AuthorizationException('Snapchat execution receipt scope changed.');
            }

            return $envelope['result'];
        }
        $result = $read();
        $encoded = json_encode(['binding' => $binding, 'result' => $result], JSON_THROW_ON_ERROR);
        DB::selectOne('SELECT builderad_snapchat_attempt_finish(?, ?, ?::uuid, ?, ?, ?, ?::uuid, ?, ?, ?, ?, ?)',
            [...$context, hash_hmac('sha256', $encoded, (string) config('app.key')), Crypt::encryptString($encoded)]);

        return $result;
    }
}
