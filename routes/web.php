<?php

use Goldnead\StatamicOffers\Http\Controllers\Web\SeatsController;
use Goldnead\StatamicOffers\Http\Controllers\Web\ShortLinkController;
use Goldnead\StatamicOffers\Offers;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Link-Weiche
|--------------------------------------------------------------------------
|
| `/go/<slug>`, Praefix in `statamic-offers.links.prefix`. Der Slug ist so
| eng wie in der Validierung, damit diese Route keinen Seitenpfad der Site
| verschluckt, der zufaellig mit dem Praefix beginnt.
|
*/

Route::get(Offers::linkPrefix().'/{slug}', [ShortLinkController::class, 'go'])
    ->where('slug', '[a-z0-9][a-z0-9-]{0,63}')
    ->middleware('throttle:120,1')
    ->name('statamic-offers.link');

/*
|--------------------------------------------------------------------------
| Plaetze fuer Gruppen
|--------------------------------------------------------------------------
|
| Zwei Seiten, beide mit einem Token als einziger Berechtigung. Die
| Schreibwege sind gedrosselt: eine Einladung verschickt eine Mail, und ein
| Formular, das beliebig oft Mails an beliebige Adressen ausloest, ist ein
| Spam-Werkzeug.
|
*/

Route::prefix(trim((string) config('statamic-offers.seats.prefix', '!/statamic-offers/plaetze'), '/'))
    ->name('statamic-offers.seats.')
    ->group(function () {
        Route::get('/einladung/{token}', [SeatsController::class, 'claim'])->name('claim');
        Route::post('/einladung/{token}', [SeatsController::class, 'accept'])
            ->middleware('throttle:30,1')
            ->name('accept');

        Route::get('/{token}', [SeatsController::class, 'manage'])->name('manage');
        Route::post('/{token}/einladen', [SeatsController::class, 'invite'])
            ->middleware('throttle:30,1')
            ->name('invite');
        Route::post('/{token}/{seat}/zurueckholen', [SeatsController::class, 'revoke'])
            ->whereNumber('seat')
            ->middleware('throttle:30,1')
            ->name('revoke');
    });
