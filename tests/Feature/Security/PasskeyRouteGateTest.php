<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasskeyRouteGateTest extends TestCase
{
    public function test_package_passkey_routes_stay_closed_until_application_authority_is_integrated(): void
    {
        foreach (['passkey.login', 'passkey.login-options', 'passkey.confirm', 'passkey.store', 'passkey.destroy'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }

        $this->get('/passkeys/login/options')->assertNotFound();
        $this->post('/passkeys/login', [])->assertNotFound();
        $this->post('/user/passkeys', [])->assertNotFound();
    }
}
