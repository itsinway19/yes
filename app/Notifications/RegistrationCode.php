<?php

namespace App\Notifications;

use App\Security\RegistrationEmailProof;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class RegistrationCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        private readonly string $attempt,
        private readonly string $code,
        private readonly string $digest,
        private readonly int $expiresAt,
    ) {
        $this->locale(app()->getLocale());
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function shouldSend($notifiable, string $channel): bool
    {
        return $channel === 'mail' && now()->timestamp < $this->expiresAt
            && app(RegistrationEmailProof::class)->shouldDeliver($this->attempt, $this->digest);
    }

    public function toMail($notifiable): MailMessage
    {
        $locale = app()->getLocale();
        $data = [
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'title' => __('registration.mail_title'),
            'intro' => __('registration.mail_intro', ['code' => $this->code]),
            'action' => null,
            'url' => null,
            'outro' => __('registration.mail_outro'),
        ];

        return (new MailMessage)->subject($data['title'])
            ->view('mail.security', $data)->text('mail.security-text', $data);
    }
}
