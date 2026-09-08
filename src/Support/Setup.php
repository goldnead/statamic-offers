<?php

namespace Goldnead\StatamicOffers\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP screen runs before its first query.
 *
 * Both screens in this addon are hung off `Utility::register()`, so the nav
 * entries appear the moment composer put the package there — migrations are a
 * separate, manual step. Between the two, `/cp/utilities/offers` and
 * `/cp/utilities/coupons` answered HTTP 500, because each one reaches for its
 * table while the page is being built. A missing table is an operator's
 * unfinished setup, not a bug, and it owes the reader a sentence rather than a
 * stack trace.
 *
 * The reason does not disappear with the 500, though: every guarded page that
 * turns somebody away writes why to the log first. A page that renders an empty
 * state and says nothing anywhere would be worse than the crash it replaced —
 * the site would look installed and never work.
 */
final class Setup
{
    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-offers: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('statamic-offers::SetupRequired', [
            'title' => $title,
            'heading' => __('statamic-offers::messages.setup_required_heading'),
            'description' => __('statamic-offers::messages.setup_required_description'),
            'tables' => $missing,
        ]);
    }
}
