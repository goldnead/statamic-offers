<?php

namespace Goldnead\StatamicOffers\Mail;

use Goldnead\StatamicOffers\Models\Seat;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An die Eingeladene: jemand hat dir einen Platz gegeben.
 *
 * Nennt, wer eingeladen hat. Eine Mail „Sie haben einen Zugang" ohne Absender
 * ist von einer Phishing-Mail nicht zu unterscheiden, und die Chorleiterin ist
 * der Grund, warum jemand auf den Knopf drueckt.
 */
class SeatInvitationMail extends Mailable
{
    public function __construct(public Seat $seat) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('statamic-offers::messages.seats_mail_invite_subject', ['name' => $this->seat->pool->title()]),
        );
    }

    public function content(): Content
    {
        $pool = $this->seat->pool;

        return new Content(
            view: 'statamic-offers::seats.mail.invitation-html',
            text: 'statamic-offers::seats.mail.invitation',
            with: [
                'title' => $pool->title(),
                'inviter' => $pool->owner_name ?: $pool->owner_email,
                'name' => $this->seat->name,
                'url' => route('statamic-offers.seats.claim', $this->seat->token),
            ],
        );
    }
}
