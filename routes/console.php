<?php

use App\Security\RegistrationEmailProof;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(RegistrationEmailProof::class)->pruneExpired())
    ->name('prune-expired-registration-email-proofs')->hourly();
