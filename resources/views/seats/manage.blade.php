@extends('statamic-offers::seats.layout')

@section('title', __('statamic-offers::messages.seats_manage_title', ['name' => $title]))

@section('content')
    <h1>{{ $title }}</h1>
    <p class="lede">{{ __('statamic-offers::messages.seats_manage_lede') }}</p>

    @if ($closed)
        <p class="errors" role="status">{{ __('statamic-offers::messages.seats_closed') }}</p>
    @endif

    <p class="count" aria-label="{{ __('statamic-offers::messages.seats_taken_label') }}">{{ $taken }} / {{ $pool->seats }}</p>
    @unless ($closed)
        <p class="muted">{{ trans_choice('statamic-offers::messages.seats_free', $free, ['count' => $free]) }}</p>
    @endunless

    @unless ($closed)
    <section class="block">
        <h2>{{ __('statamic-offers::messages.seats_invite_heading') }}</h2>
        <p class="hint">{{ __('statamic-offers::messages.seats_invite_hint') }}</p>

        @if ($errors->any())
            <p class="errors" role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="{{ route('statamic-offers.seats.invite', $pool->manage_token) }}">
            @csrf
            <div class="field">
                <label for="email">{{ __('statamic-offers::messages.seats_field_email') }}</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="off" @disabled($free === 0)>
            </div>
            <div class="field">
                <label for="name">{{ __('statamic-offers::messages.seats_field_name') }}</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" autocomplete="off" @disabled($free === 0)>
            </div>
            <button class="btn" type="submit" @disabled($free === 0)>{{ __('statamic-offers::messages.seats_invite_action') }}</button>
        </form>
    </section>
    @endunless

    <section class="block">
        <h2>{{ __('statamic-offers::messages.seats_list_heading') }}</h2>

        @if ($seats->isEmpty())
            <p class="hint">{{ __('statamic-offers::messages.seats_list_empty') }}</p>
        @else
            <ul class="list">
                @foreach ($seats as $seat)
                    <li class="entry">
                        <div class="what">
                            <span class="name">{{ $seat->name ?: $seat->email }}</span>
                            @if ($seat->name)
                                <span class="desc">{{ $seat->email }}</span>
                            @endif
                        </div>
                        @if ($seat->status === \Goldnead\StatamicOffers\Models\Seat::STATUS_CLAIMED)
                            <span class="badge ok">{{ __('statamic-offers::messages.seats_status_claimed') }}</span>
                        @else
                            <span class="badge">{{ __('statamic-offers::messages.seats_status_invited') }}</span>
                        @endif
                        {{-- Rueckfrage nur bei angenommenen Plaetzen: dort nimmt der
                             Knopf jemandem einen Zugang, den er schon benutzt. --}}
                        <form method="post" action="{{ route('statamic-offers.seats.revoke', [$pool->manage_token, $seat->id]) }}"
                            @if ($seat->status === \Goldnead\StatamicOffers\Models\Seat::STATUS_CLAIMED)
                                onsubmit="return confirm(this.dataset.confirm)"
                                data-confirm="{{ __('statamic-offers::messages.seats_revoke_confirm', ['email' => $seat->email]) }}"
                            @endif
                        >
                            @csrf
                            <button class="btn btn-quiet" type="submit">{{ __('statamic-offers::messages.seats_revoke_action') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="foot">{{ __('statamic-offers::messages.seats_manage_foot') }}</p>
@endsection
