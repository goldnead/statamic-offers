<?php

namespace Goldnead\StatamicOffers\Mail;

use Goldnead\StatamicOffers\Models\SeatPool;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An die Kaeuferin: deine Plaetze, und wo du sie verteilst.
 *
 * Neben der Kaufbestaetigung von payments, nicht an ihrer Stelle. Die sagt,
 * was bezahlt wurde; diese sagt, was jetzt zu tun ist, und traegt den einen
 * Link, ueber den das geht.
 */
class SeatPoolMail extends Mailable
{
    public function __construct(public SeatPool $pool) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('statamic-offers::messages.seats_mail_pool_subject', ['name' => $this->pool->title()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'statamic-offers::seats.mail.pool-html',
            text: 'statamic-offers::seats.mail.pool',
            with: [
                'title' => $this->pool->title(),
                'seats' => $this->pool->seats,
                'name' => $this->pool->owner_name,
                'url' => route('statamic-offers.seats.manage', $this->pool->manage_token),
            ],
        );
    }
}
