{{ $name ? __('statamic-offers::messages.seats_mail_greeting_named', ['name' => $name]) : __('statamic-offers::messages.seats_mail_greeting') }}

{{ trans_choice('statamic-offers::messages.seats_mail_pool_body', $seats, ['count' => $seats, 'name' => $title]) }}

{{-- Unescaped: Klartext kennt kein &amp;, und ein escapter Link waere ein anderer. --}}
{!! $url !!}

{{ __('statamic-offers::messages.seats_mail_pool_keep') }}
