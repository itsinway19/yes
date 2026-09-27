<?php

namespace Tests\Feature\Security;

use App\Security\BreachPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    public function test_registration_rejects_short_password_and_reports_breach_service_failure_separately(): void
    {
        $this->post('/register', [
            'name' => 'Advertiser', 'email' => 'short@example.com',
            'password' => 'short password', 'password_confirmation' => 'short password',
        ])->assertSessionHasErrors([
            'password' => 'استخدم كلمة مرور من 15 حرفًا على الأقل.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'short@example.com']);

        Http::swap(new Factory);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);
        $this->assertSame(503, Http::get('https://api.pwnedpasswords.com/range/ABCDE')->status());
        $this->assertInstanceOf(BreachPasswordVerifier::class, app(UncompromisedVerifier::class));
        $this->post('/register', [
            'name' => 'Advertiser', 'email' => 'offline@example.com',
            'password' => 'a unique long passphrase 2026',
            'password_confirmation' => 'a unique long passphrase 2026',
        ])->assertSessionHasErrors([
            'service' => 'تعذّر التحقق من أمان كلمة المرور مؤقتًا. احتفظ بكلمة المرور وحاول مجددًا بعد قليل.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'offline@example.com']);

        $this->postJson('/register', [
            'name' => 'Advertiser', 'email' => 'offline-json@example.com',
            'password' => 'a unique long passphrase 2026',
            'password_confirmation' => 'a unique long passphrase 2026',
        ])->assertStatus(503)->assertJsonPath('message',
            'تعذّر التحقق من أمان كلمة المرور مؤقتًا. احتفظ بكلمة المرور وحاول مجددًا بعد قليل.');
        $this->assertDatabaseMissing('users', ['email' => 'offline-json@example.com']);

        Http::swap(new Factory);
        Http::fake(['api.pwnedpasswords.com/*' => Http::failedConnection()]);
        $this->post('/register', [
            'name' => 'Advertiser', 'email' => 'connection-failed@example.com',
            'password' => 'another unique long passphrase 2026',
            'password_confirmation' => 'another unique long passphrase 2026',
        ])->assertSessionHasErrors('service');
        $this->assertDatabaseMissing('users', ['email' => 'connection-failed@example.com']);

        Http::swap(new Factory);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
        $this->post('/register', [
            'name' => 'Advertiser', 'email' => 'malformed-response@example.com',
            'password' => 'yet another unique long passphrase 2026',
            'password_confirmation' => 'yet another unique long passphrase 2026',
        ])->assertSessionHasErrors('service');
        $this->assertDatabaseMissing('users', ['email' => 'malformed-response@example.com']);
    }

    public function test_password_of_more_than_64_characters_is_accepted_without_composition(): void
    {
        $password = str_repeat('open field phrase ', 5);
        $this->post('/register', [
            'name' => 'Advertiser', 'email' => 'long@example.com',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_a_known_breached_password_is_rejected_with_an_arabic_error(): void
    {
        $password = 'CorrectHorseBatteryStaple';
        $sha1 = strtoupper(sha1($password));
        Http::swap(new Factory);
        Http::fake([
            'api.pwnedpasswords.com/range/'.substr($sha1, 0, 5) => Http::response(substr($sha1, 5).":12\n", 200),
        ]);

        $this->post('/register', [
            'name' => 'Advertiser', 'email' => 'breached@example.test',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors([
            'password' => 'كلمة المرور معروفة في تسريبات البيانات. اختر كلمة مرور مختلفة.',
        ]);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'breached@example.test']);
    }

    public function test_password_over_the_maximum_is_rejected_without_truncation(): void
    {
        $password = str_repeat('a', 1025);
        $this->post('/register', [
            'name' => 'Advertiser', 'email' => 'too-long@example.test',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors([
            'password' => 'يجب ألا تتجاوز كلمة المرور 1024 حرفًا.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'too-long@example.test']);
    }
}
