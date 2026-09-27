<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

final class SecurityResetPassword extends ResetPassword implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    private readonly int $queuedAt;

    public function __construct(#[\SensitiveParameter] string $token, private readonly string $expectedEmail)
    {
        parent::__construct($token);
        $this->queuedAt = time();
        $this->locale(app()->getLocale());
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function shouldSend($notifiable, string $channel): bool
    {
        $allowed = $channel === 'mail' && time() <= $this->queuedAt + 300 &&
            hash_equals($this->expectedEmail, $notifiable->email) &&
            Password::broker()->tokenExists($notifiable, $this->token);
        if (! $allowed) {
            Log::notice('security_mail_skipped_stale', [
                'type' => 'password_reset', 'user_id' => $notifiable->id,
            ]);
        }

        return $allowed;
    }

    public function toMail($notifiable): MailMessage
    {
        $locale = app()->getLocale();
        $data = [
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'title' => __('security.reset.title'),
            'intro' => __('security.reset.intro'),
            'action' => __('security.reset.action'),
            'url' => $this->resetUrl($notifiable),
            'outro' => __('security.reset.outro', [
                'minutes' => config('auth.passwords.users.expire'),
            ]),
        ];

        return (new MailMessage)->subject($data['title'])
            ->view('mail.security', $data)->text('mail.security-text', $data);
    }
}
