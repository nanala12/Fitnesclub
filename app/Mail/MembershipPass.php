<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use SensitiveParameter;

class MembershipPass extends Mailable
{
    public function __construct(private int $deliveryId, #[SensitiveParameter] private string $documentPath) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Twój karnet — ActiveFit');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.membership-pass');
    }

    public function headers(): Headers
    {
        return new Headers(messageId: hash_hmac('sha256', 'membership-delivery:'.$this->deliveryId, (string) config('app.key')).'@activefit.invalid');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('membership_documents', $this->documentPath)->as('karnet-activefit.pdf')->withMime('application/pdf')];
    }
}
