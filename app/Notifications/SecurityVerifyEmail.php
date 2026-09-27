<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SecurityVerifyEmail extends VerifyEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    private readonly int $queuedAt;

    public function __construct(
        private readonly string $expectedEmail,
        private readonly string $lifecycleUpdatedAt,
    ) {
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
        $lifecycle = DB::table('principal_lifecycles')
            ->where('user_id', $notifiable->id)->first();
        $allowed = $channel === 'mail' && time() <= $this->queuedAt + 600 &&
            ! $notifiable->hasVerifiedEmail() &&
            hash_equals($this->expectedEmail, $notifiable->email) &&
            $lifecycle?->status === 'pending' &&
            (string) $lifecycle->updated_at === $this->lifecycleUpdatedAt;
        if (! $allowed) {
            Log::notice('security_mail_skipped_stale', [
                'type' => 'verify_email', 'user_id' => $notifiable->id,
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
            'title' => __('security.verify.title'),
            'intro' => __('security.verify.intro'),
            'action' => __('security.verify.action'),
            'url' => $this->verificationUrl($notifiable),
            'outro' => __('security.verify.outro'),
        ];

        return (new MailMessage)->subject($data['title'])
            ->view('mail.security', $data)->text('mail.security-text', $data);
    }
}
