<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('statamic-offers::messages.seats_mail_invite_subject', ['name' => $title]) }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f4f5;">
<div style="max-width:560px; margin:0 auto; padding:32px 24px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b; font-size:15px; line-height:1.6;">
    <p style="margin:0 0 16px;">{{ $name ? __('statamic-offers::messages.seats_mail_greeting_named', ['name' => $name]) : __('statamic-offers::messages.seats_mail_greeting') }}</p>

    <p style="margin:0 0 24px;">{{ __('statamic-offers::messages.seats_mail_invite_body', ['inviter' => $inviter, 'name' => $title]) }}</p>

    <p style="margin:0 0 24px;">
        <a href="{{ $url }}" style="display:inline-block; padding:11px 18px; border-radius:8px; background:#18181b; color:#ffffff; text-decoration:none;">{{ __('statamic-offers::messages.seats_mail_invite_button') }}</a>
    </p>

    <p style="margin:0 0 24px; font-size:13px; color:#71717a; word-break:break-all;">{{ $url }}</p>

    <p style="margin:0; font-size:13px; color:#a1a1aa;">{{ __('statamic-offers::messages.seats_mail_invite_ignore', ['inviter' => $inviter]) }}</p>
</div>
</body>
</html>
