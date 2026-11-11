<?php

namespace Tests\Support;

use App\Notifications\RegistrationCode;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

trait RegistrationEmailProofFixture
{
    private function issueRegistrationCode(string $email, array $headers = []): array
    {
        Notification::fake();
        $response = $this->postJson('/register/email', ['email' => $email], $headers)
            ->assertOk()->assertJsonPath('state', 'code_required');
        $notification = Notification::sent(new AnonymousNotifiable, RegistrationCode::class)->sole();
        preg_match('/\b[0-9]{6}\b/', $notification->toMail(new AnonymousNotifiable)->viewData['intro'], $match);
        $this->assertArrayHasKey(0, $match);

        return ['attempt_id' => $response->json('attempt_id'), 'code' => $match[0]];
    }

    private function verifyRegistrationEmail(string $email, array $headers = []): string
    {
        $code = $this->issueRegistrationCode($email, $headers);
        $this->postJson('/register/email/verify', $code, $headers)
            ->assertOk()->assertJsonPath('state', 'verified');

        return $code['attempt_id'];
    }
}
