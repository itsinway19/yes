<?php

namespace App\Providers;

use App\Auth\AuthorityUserProvider;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Notifications\SecurityResetPassword;
use App\Notifications\SecurityVerifyEmail;
use App\Security\BreachPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Passkey endpoints require Builderad lifecycle and session checks.
        // Register only the application-owned routes after those checks exist.
        Passkeys::ignoreRoutes();
        Passkeys::useUserModel(User::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('authority-eloquent', fn ($app, array $config) => new AuthorityUserProvider(
            $app['hash'], $config['model'],
        ));
        Vite::prefetch(concurrency: 3);
        // Resolve Laravel's deferred verifier before overriding its fail-open default.
        $this->app->make(UncompromisedVerifier::class);
        $this->app->bind(UncompromisedVerifier::class, BreachPasswordVerifier::class);
        Password::defaults(fn () => Password::min(15)->max(1024)->uncompromised());
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel === 'mail' && $this->isSecurityMail($event->notification)) {
                Log::info('security_mail_transport_accepted', [
                    'type' => class_basename($event->notification),
                    'notification_id' => $event->notification->id,
                ]);
            }
        });
        Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
            if ($event->channel === 'mail' && $this->isSecurityMail($event->notification)) {
                Log::warning('security_mail_transport_failed', [
                    'type' => class_basename($event->notification),
                    'notification_id' => $event->notification->id,
                ]);
            }
        });
    }

    private function isSecurityMail(object $notification): bool
    {
        return $notification instanceof SecurityVerifyEmail ||
            $notification instanceof SecurityResetPassword ||
            $notification instanceof SecurityNotice;
    }
}
