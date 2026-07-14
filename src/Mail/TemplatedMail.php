<?php

namespace ConferenceTools\Registration\Mail;

use ConferenceTools\Registration\Services\RegistrationEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One of the admin-edited registration emails, fully rendered: the subject and
 * body arrive already interpolated (see RegistrationMailer). Sent as plain
 * text — the templates are admin-typed text, not HTML. The sender is the
 * registration from address configured on the admin "Emails" console, or the
 * host's default when unset.
 */
class TemplatedMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
    ) {}

    /** The mail's subject and from address, from the admin-edited template. */
    public function envelope(): Envelope
    {
        $from = app(RegistrationEmails::class)->fromEmail();

        return new Envelope(
            subject: $this->subjectLine,
            from: $from ? new Address($from) : null,
        );
    }

    /** The mail's body view, rendering the template's interpolated text. */
    public function content(): Content
    {
        return new Content(text: 'registration::mail.template');
    }
}
