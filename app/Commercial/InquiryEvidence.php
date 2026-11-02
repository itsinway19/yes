<?php

namespace App\Commercial;

use App\Foundation\Tenancy\PrincipalContext;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** BF03-I01 source assertions only. SQL owns resource/action/dependency authority. */
final class InquiryEvidence
{
    public function __construct(private readonly PrincipalContext $principals) {}

    public function eligibility(Request $request, string $business, array $selection): array
    {
        return $this->run($request, 'SELECT builderad_v2_inquiry_eligibility(?,?::uuid,?::jsonb)', [$business, $this->json($selection)]);
    }

    public function enroll(Request $request, string $business, string $command, int $basis, bool $confirm, array $selection): array
    {
        return $this->run($request, 'SELECT builderad_v2_inquiry_enroll(?,?::uuid,?::uuid,?,?,?::jsonb)', [$business, $command, $basis, $confirm, $this->json($selection)]);
    }

    public function write(Request $request, string $business, string $command, ?string $declaration, int $expected, string $effect, ?array $value, array $selection): array
    {
        return $this->run($request, 'SELECT builderad_v2_inquiry_write(?,?::uuid,?::uuid,?::uuid,?,?,?::jsonb,?::jsonb)', [$business, $command, $declaration, $expected, $effect, $value === null ? null : $this->json($value), $this->json($selection)]);
    }

    public function read(Request $request, string $business, array $selection, ?string $declaration = null, int $after = 0, ?string $cursor = null): array
    {
        return $this->run($request, 'SELECT builderad_v2_inquiry_read(?,?::uuid,?::jsonb,?::uuid,?,?::uuid)', [$business, $this->json($selection), $declaration, $after, $cursor]);
    }

    public function revoke(Request $request, string $business, string $command, string $action, array $selection): array
    {
        return $this->run($request, 'SELECT builderad_v2_inquiry_revoke(?,?::uuid,?::uuid,?,?::jsonb)', [$business, $command, $action, $this->json($selection)]);
    }

    private function run(Request $request, string $sql, array $arguments): array
    {
        if (DB::transactionLevel() !== 0 || ! $request->user()) {
            throw new LogicException('Inquiry operation requires its own authenticated transaction.');
        }

        return DB::transaction(function () use ($request, $sql, $arguments): array {
            $this->principals->activate($request->user());

            return json_decode(DB::scalar($sql, [SessionAuthority::sessionKey($request), ...$arguments]), true, flags: JSON_THROW_ON_ERROR);
        }, 1);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
