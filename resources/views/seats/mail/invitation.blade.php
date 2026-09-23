{{ $name ? __('statamic-offers::messages.seats_mail_greeting_named', ['name' => $name]) : __('statamic-offers::messages.seats_mail_greeting') }}

{{ __('statamic-offers::messages.seats_mail_invite_body', ['inviter' => $inviter, 'name' => $title]) }}

{!! $url !!}

{{ __('statamic-offers::messages.seats_mail_invite_ignore', ['inviter' => $inviter]) }}
