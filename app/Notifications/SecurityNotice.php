<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;

final class SecurityNotice extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    private readonly int $queuedAt;

    public function __construct(private readonly string $kind)
    {
        if (! in_array($kind, [
            'password_changed', 'passkey_added', 'passkey_removed',
            'primary_email_changed', 'account_disabled',
        ], true)) {
            throw new InvalidArgumentException('Unknown security notice.');
        }
        $this->queuedAt = time();
        $this->locale(app()->getLocale());
        $this->afterCommit();
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
        return $channel === 'mail' && time() <= $this->queuedAt + 86400;
    }

    public function toMail($notifiable): MailMessage
    {
        $locale = app()->getLocale();
        $data = [
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'title' => __('security.notice.'.$this->kind.'.title'),
            'intro' => __('security.notice.'.$this->kind.'.intro'),
            'action' => null,
            'url' => null,
            'outro' => __('security.notice.outro'),
        ];

        return (new MailMessage)->subject($data['title'])
            ->view('mail.security', $data)->text('mail.security-text', $data);
    }
}
