<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
        public readonly ?string $name = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset Password BimbelKu');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset_password',
            text: 'emails.reset_password_text',
            with: ['url' => $this->resetUrl, 'name' => $this->name],
        );
    }
}
