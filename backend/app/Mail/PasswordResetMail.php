<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password reset email — docs/02_User_Flows.md Flow 6. Localized per the user's stored
 * locale (docs/09_Backend_Architecture.md §9). Queued (database driver, drained by cron —
 * docs/09_Backend_Architecture.md §5).
 */
class PasswordResetMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $token,
        public readonly string $email,
        public readonly string $userLocale,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('legacyvault.mail.password_reset_subject', [], $this->userLocale),
        );
    }

    public function content(): Content
    {
        $resetUrl = rtrim(config('app.frontend_url', config('app.url')), '/')
            .'/reset-password?token='.$this->token
            .'&email='.urlencode($this->email);

        return new Content(
            markdown: 'mail.password-reset',
            with: [
                'resetUrl' => $resetUrl,
                'locale' => $this->userLocale,
            ],
        );
    }
}
