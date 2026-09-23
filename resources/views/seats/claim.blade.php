@extends('statamic-offers::seats.layout')

@section('title', $title)

@section('content')
    @if ($claimed)
        <h1>{{ __('statamic-offers::messages.seats_claimed_heading') }}</h1>
        <p class="lede">{{ __('statamic-offers::messages.seats_claimed_body', ['name' => $title, 'email' => $seat->email]) }}</p>

        @if ($next)
            <a class="btn" href="{{ $next }}">{{ __('statamic-offers::messages.seats_claimed_next') }}</a>
        @endif
    @else
        <h1>{{ $title }}</h1>
        <p class="lede">{{ __('statamic-offers::messages.seats_claim_body', ['inviter' => $inviter]) }}</p>
        <p class="muted">{{ __('statamic-offers::messages.seats_claim_for', ['email' => $seat->email]) }}</p>

        <form method="post" action="{{ route('statamic-offers.seats.accept', $seat->token) }}">
            @csrf
            <button class="btn" type="submit">{{ __('statamic-offers::messages.seats_claim_action') }}</button>
        </form>
    @endif
@endsection
