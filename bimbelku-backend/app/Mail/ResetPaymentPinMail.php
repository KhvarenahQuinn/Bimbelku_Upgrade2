<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPaymentPinMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
        public readonly ?string $name = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset PIN Pembayaran BimbelKu');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset_payment_pin',
            text: 'emails.reset_payment_pin_text',
            with: ['url' => $this->resetUrl, 'name' => $this->name],
        );
    }
}
