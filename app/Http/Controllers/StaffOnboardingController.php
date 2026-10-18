<?php

namespace App\Http\Controllers;

use App\Http\Responses\SpaPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StaffOnboardingController
{
    public function __invoke(Request $request): SpaPage
    {
        $personnel = DB::table('internal_personnel')->where('user_id', $request->user()->id)->firstOrFail();

        return new SpaPage([
            'verified' => $request->user()->hasVerifiedEmail(),
            'kind' => $personnel->kind,
            'passkeys' => $request->user()->passkeys()
                ->orderBy('created_at')->get(['id', 'name', 'created_at']),
            'attested' => $personnel->attested_at !== null,
        ]);
    }
}
