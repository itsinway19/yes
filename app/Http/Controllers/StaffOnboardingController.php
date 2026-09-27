<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class StaffOnboardingController
{
    public function __invoke(Request $request): Response
    {
        $personnel = DB::table('internal_personnel')->where('user_id', $request->user()->id)->firstOrFail();

        return Inertia::render('Internal/Onboarding', [
            'verified' => $request->user()->hasVerifiedEmail(),
            'kind' => $personnel->kind,
            'passkeys' => $request->user()->passkeys()
                ->orderBy('created_at')->get(['id', 'name', 'created_at']),
            'attested' => $personnel->attested_at !== null,
        ]);
    }
}
