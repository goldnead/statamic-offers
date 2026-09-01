<?php

namespace Goldnead\StatamicOffers\Http\Controllers\Cp;

use Goldnead\StatamicOffers\Http\Resources\Cp\CouponsCollection;
use Goldnead\StatamicOffers\Models\Coupon;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\CouponBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Inertia\Inertia;
use RuntimeException;
use Statamic\Facades\Scope;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Statamic;

/**
 * Coupons in the Control Panel.
 *
 * The second writing screen in this addon, and the one with the most direct
 * line to money: every row here is permission for a stranger to pay less. The
 * rule the payment family is built on still holds — the browser sends a *code*,
 * never an amount — but that only holds as long as what a code is worth is
 * decided on this side of the wire, which is what the validation below is for.
 */
class CouponsController extends CpController
{
    use QueriesFilters;

    public const SCOPE = 'statamic-offers-coupons';

    public function index(FilteredRequest $request)
    {
        $this->authorizeAccess();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        return Inertia::render('statamic-offers::Coupons/Index', [
            'listingUrl' => cp_route('utilities.coupons'),
            'storeUrl' => cp_route('utilities.coupons.store'),
            'generateUrl' => cp_route('utilities.coupons.generate'),
            // Named beside the date fields: a "valid until" typed without
            // knowing which clock it runs on ends a campaign hours early.
            'timezone' => (string) config('app.timezone', 'UTC'),
            'batch' => [
                'maxCount' => CouponBatch::MAX_COUNT,
                'maxPrefix' => CouponBatch::MAX_PREFIX,
                'minLength' => CouponBatch::MIN_LENGTH,
                'maxLength' => CouponBatch::MAX_LENGTH,
                'defaultLength' => CouponBatch::DEFAULT_LENGTH,
            ],
            // Without an action URL the listing renders no checkboxes and no
            // bulk toolbar, which is the difference between this screen and
            // the Entries screen a user just came from.
            'actionUrl' => cp_route('utilities.coupons.actions'),
            'filters' => Scope::filters(self::SCOPE, ['scope' => self::SCOPE]),
            'sortColumn' => 'code',
            'sortDirection' => 'asc',
            'hasAny' => Coupon::query()->exists(),
            'offers' => $this->offers(),
            'currency' => (string) config('statamic-payments.currency', 'EUR'),
            // Every label on the screen, translated here. Building them in the
            // template would work today and quietly stop working the moment
            // this addon is installed somewhere its language files are not part
            // of the Control Panel's dictionary — and a raw
            // `statamic-offers::messages.field_code` where a label belongs is
            // the loudest possible "third-party addon".
            't' => $this->strings(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();

        $coupon = Coupon::create($this->validated($request));

        return back()->with('message', __('statamic-offers::messages.coupon_saved', ['code' => $coupon->code]));
    }

    public function update(Request $request, Coupon $coupon)
    {
        $this->authorizeAccess();

        $coupon->update($this->validated($request, $coupon));

        return back()->with('message', __('statamic-offers::messages.coupon_saved', ['code' => $coupon->code]));
    }

    public function destroy(Request $request, Coupon $coupon)
    {
        $this->authorizeAccess();

        $coupon->delete();

        return back()->with('message', __('statamic-offers::messages.coupon_deleted'));
    }

    /**
     * Many codes at once. Same shape as one, minus the code, plus a count.
     */
    public function generate(Request $request, CouponBatch $batch)
    {
        $this->authorizeAccess();

        $validator = Validator::make($request->all(), [
            'count' => ['required', 'integer', 'min:1', 'max:'.CouponBatch::MAX_COUNT],
            'prefix' => ['nullable', 'string', 'max:'.CouponBatch::MAX_PREFIX, 'regex:/^\S*$/'],
            'length' => ['nullable', 'integer', 'min:'.CouponBatch::MIN_LENGTH, 'max:'.CouponBatch::MAX_LENGTH],
            'name' => ['nullable', 'string', 'max:191'],
            'percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'amount_cent' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'offers' => ['nullable', 'array'],
            'offers.*' => ['string', Rule::exists('offers', 'handle')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => array_filter([
                'nullable', 'date', $request->filled('starts_at') ? 'after:starts_at' : null,
            ]),
            // Nullable here means "no limit", which a batch has to ask for;
            // the form sends 1 unless somebody changes it.
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ], [
            'offers.*.exists' => __('statamic-offers::messages.coupon_offers_invalid'),
        ]);

        $validator->after(fn (ValidatorInstance $validator) => $this->checkExactlyOneDiscount($validator, $request));

        $data = $validator->validate();

        try {
            $made = $batch->generate([
                'count' => (int) $data['count'],
                'prefix' => $data['prefix'] ?? null,
                'length' => isset($data['length']) ? (int) $data['length'] : null,
                'name' => $data['name'] ?? null,
                'percent' => isset($data['percent']) ? (int) $data['percent'] : null,
                'amount_cent' => isset($data['amount_cent']) ? (int) $data['amount_cent'] : null,
                'currency' => $data['currency'] ?? null,
                'offers' => (array) ($data['offers'] ?? []),
                'starts_at' => $this->startOfDay($data['starts_at'] ?? null),
                'ends_at' => $this->endOfDay($data['ends_at'] ?? null),
                'max_uses' => array_key_exists('max_uses', $data) && $data['max_uses'] !== null ? (int) $data['max_uses'] : null,
                'active' => true,
            ]);
        } catch (QueryException $e) {
            // The database said no — a unique index the lookup did not see, a
            // connection gone. Nothing was written, the batch is one
            // transaction; the reason belongs in the log, not on the form,
            // where a driver message would be noise to the person and a
            // schema hint to anyone else.
            Log::error('statamic-offers: coupon batch failed in the database.', ['exception' => $e]);

            throw ValidationException::withMessages(['count' => __('statamic-offers::messages.coupon_batch_failed')]);
        } catch (RuntimeException $e) {
            // Nothing was written — the batch is one transaction — so the
            // person gets the reason on the form instead of a partial set.
            throw ValidationException::withMessages(['count' => $e->getMessage()]);
        }

        return back()->with('message', trans_choice('statamic-offers::messages.coupons_generated', $made->count(), [
            'count' => $made->count(),
            'prefix' => mb_strtoupper(trim((string) ($data['prefix'] ?? ''))),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Coupon $coupon = null): array
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:64', 'regex:/^\S+$/'],
            'name' => ['nullable', 'string', 'max:191'],
            // Nullable *integer* on both, so nobody can post "20,5" and have it
            // read as twenty. The "exactly one of the two" part is below, where
            // it can name both fields at once.
            'percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'amount_cent' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'offers' => ['nullable', 'array'],
            'offers.*' => ['string', Rule::exists('offers', 'handle')],
            'starts_at' => ['nullable', 'date'],
            // `after:starts_at` compares against a *field* only while that field
            // holds a date. With `starts_at` empty, Laravel reads the rule's
            // argument as a literal date string, fails to parse it, and refuses
            // every end date on a coupon that has no start.
            'ends_at' => array_filter([
                'nullable', 'date', $request->filled('starts_at') ? 'after:starts_at' : null,
            ]),
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'active' => ['boolean'],
        ], [
            'offers.*.exists' => __('statamic-offers::messages.coupon_offers_invalid'),
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($request, $coupon) {
            $this->checkExactlyOneDiscount($validator, $request);
            $this->checkCodeIsFree($validator, $request, $coupon);
        });

        $data = $validator->validate();

        // `validate()` drops a nullable key that was never sent, so reading one
        // directly is a 500 on every client that leaves it out — which is every
        // client that is not this addon's own form.
        $data['active'] = $request->boolean('active');
        $data['currency'] = ($data['currency'] ?? null) ? mb_strtoupper($data['currency']) : null;
        $data['percent'] = $data['percent'] ?? null;
        $data['amount_cent'] = $data['amount_cent'] ?? null;
        $data['name'] = $data['name'] ?? null;
        $data['max_uses'] = $data['max_uses'] ?? null;

        // Duplicates would be shown twice and counted twice; the order is kept
        // because it is the order somebody chose.
        $data['offers'] = array_values(array_unique(array_filter(
            (array) ($data['offers'] ?? []), 'is_string'
        )));

        $data['starts_at'] = $this->startOfDay($data['starts_at'] ?? null);
        // The last day counts in full. Stored as midnight it would mean "dead
        // from the first second of the day you wrote down", which is not what
        // anybody types into a field labelled "until".
        $data['ends_at'] = $this->endOfDay($data['ends_at'] ?? null);

        return $data;
    }

    protected function checkExactlyOneDiscount(ValidatorInstance $validator, Request $request): void
    {
        $percent = $request->input('percent');
        $amount = $request->input('amount_cent');

        $hasPercent = $percent !== null && $percent !== '';
        $hasAmount = $amount !== null && $amount !== '';

        if ($hasPercent === $hasAmount) {
            // On both fields, because the person reading the message is looking
            // at the pair and cannot tell which half the screen means.
            $validator->errors()->add('percent', __('statamic-offers::messages.coupon_discount_exactly_one'));
            $validator->errors()->add('amount_cent', __('statamic-offers::messages.coupon_discount_exactly_one'));
        }
    }

    /**
     * Uniqueness the way the checkout asks the question.
     *
     * A database unique index is case-sensitive on SQLite, so `FRUEHLING` and
     * `fruehling` would both be accepted — and `Coupon::findByCode()`, which
     * matches case-insensitively, would then return whichever of the two the
     * table happens to hand over first. The lookup is the authority, so the
     * check is done through it.
     */
    protected function checkCodeIsFree(ValidatorInstance $validator, Request $request, ?Coupon $coupon): void
    {
        $existing = Coupon::findByCode((string) $request->input('code'));

        if ($existing && $existing->getKey() !== $coupon?->getKey()) {
            $validator->errors()->add('code', __('statamic-offers::messages.coupon_code_taken'));
        }
    }

    protected function startOfDay(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    protected function endOfDay(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->endOfDay();
    }

    protected function json(FilteredRequest $request)
    {
        $query = Coupon::query();

        if ($search = trim((string) $request->get('search', ''))) {
            $this->applySearch($query, $search);
        }

        $badges = $this->queryFilters($query, $request->filters, ['scope' => self::SCOPE]);

        [$column, $direction] = $this->order($request);
        $query->orderBy($column, $direction);

        return (new CouponsCollection($query->paginate(Statamic::cpPerPage($request->get('perPage')))))
            ->columnPreferenceKey('statamic-offers.coupons.columns')
            ->additional(['meta' => ['activeFilterBadges' => $badges]]);
    }

    protected function authorizeAccess(): void
    {
        // Through the Gate, where `Utility::register` puts the permission and
        // where the route's `can:` middleware looks. Repeated in the controller
        // because a route is a place a middleware can be forgotten, and what is
        // behind it is the discount everybody gets.
        abort_unless(Gate::allows('access coupons utility'), 403);
    }

    /**
     * @param  Builder<Coupon>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        // `%` and `_` are LIKE wildcards; the ESCAPE clause is spelled out
        // because SQLite, unlike MySQL and Postgres, has no default one.
        $escaped = addcslashes($term, '%_\\');

        $query->where(function (Builder $q) use ($escaped) {
            foreach (['code', 'name'] as $column) {
                $q->orWhereRaw($column." LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
            }
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function order(FilteredRequest $request): array
    {
        // A positive list: `sort` arrives in the query string and would
        // otherwise order by any column in the table, including ones the screen
        // never shows.
        $sortable = [
            'code' => 'code',
            'name' => 'name',
            'validity' => 'ends_at',
            'usage' => 'used_count',
            'active' => 'active',
        ];

        $requested = (string) $request->get('sort', 'code');
        $direction = strtolower((string) $request->get('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        return [$sortable[$requested] ?? 'code', $direction];
    }

    /**
     * @return list<array<string, string>>
     */
    protected function offers(): array
    {
        return Offer::query()
            ->orderBy('name')
            ->get(['handle', 'name'])
            ->map(fn (Offer $offer) => ['value' => $offer->handle, 'label' => $offer->name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function strings(): array
    {
        return [
            'title' => __('statamic-offers::messages.coupons_utility_title'),
            'utilities' => __('Utilities'),
            'empty_heading' => __('statamic-offers::messages.coupons_empty_heading'),
            'empty_title' => __('statamic-offers::messages.coupons_empty_title'),
            'empty_description' => __('statamic-offers::messages.coupons_empty_description'),
            'new' => __('statamic-offers::messages.new_coupon'),
            'edit' => __('statamic-offers::messages.edit_coupon'),
            'delete_title' => __('statamic-offers::messages.coupon_delete_title'),
            // The placeholder survives translation and the row's code is put in
            // on the screen. Assembling the sentence in Javascript instead would
            // mean shipping half of it in English.
            'delete_body' => __('statamic-offers::messages.coupon_delete_body', ['code' => ':code']),
            'usage_unlimited' => __('statamic-offers::messages.coupon_usage_unlimited'),
            'field_code' => __('statamic-offers::messages.coupon_field_code'),
            'field_code_help' => __('statamic-offers::messages.coupon_field_code_help'),
            'field_name' => __('statamic-offers::messages.coupon_field_name'),
            'field_name_help' => __('statamic-offers::messages.coupon_field_name_help'),
            'field_percent' => __('statamic-offers::messages.coupon_field_percent'),
            'field_amount' => __('statamic-offers::messages.coupon_field_amount'),
            'field_discount_help' => __('statamic-offers::messages.coupon_field_discount_help'),
            'field_currency' => __('statamic-offers::messages.coupon_field_currency'),
            'field_currency_help' => __('statamic-offers::messages.coupon_field_currency_help'),
            'field_offers' => __('statamic-offers::messages.coupon_field_offers'),
            'field_offers_help' => __('statamic-offers::messages.coupon_field_offers_help'),
            'field_offers_placeholder' => __('statamic-offers::messages.coupon_field_offers_placeholder'),
            'field_starts_at' => __('statamic-offers::messages.coupon_field_starts_at'),
            'field_ends_at' => __('statamic-offers::messages.coupon_field_ends_at'),
            'field_dates_help' => __('statamic-offers::messages.coupon_field_dates_help'),
            'field_max_uses' => __('statamic-offers::messages.coupon_field_max_uses'),
            'field_max_uses_help' => __('statamic-offers::messages.coupon_field_max_uses_help'),
            'field_active' => __('statamic-offers::messages.coupon_field_active'),
            'generate' => __('statamic-offers::messages.coupons_generate'),
            'generate_title' => __('statamic-offers::messages.coupons_generate_title'),
            'generate_help' => __('statamic-offers::messages.coupons_generate_help'),
            'generate_action' => __('statamic-offers::messages.coupons_generate_action'),
            'field_count' => __('statamic-offers::messages.coupon_field_count'),
            'field_prefix' => __('statamic-offers::messages.coupon_field_prefix'),
            'field_prefix_help' => __('statamic-offers::messages.coupon_field_prefix_help'),
            'field_length' => __('statamic-offers::messages.coupon_field_length'),
            'field_length_help' => __('statamic-offers::messages.coupon_field_length_help'),
            'field_name_pattern' => __('statamic-offers::messages.coupon_field_name_pattern'),
            'field_name_pattern_help' => __('statamic-offers::messages.coupon_field_name_pattern_help'),
            'field_max_uses_batch_help' => __('statamic-offers::messages.coupon_field_max_uses_batch_help'),
            'timezone_note' => __('statamic-offers::messages.timezone_note'),
            'yes' => __('statamic-offers::messages.yes'),
            'no' => __('statamic-offers::messages.no'),
            'save' => __('Save'),
            'cancel' => __('Cancel'),
            'edit_action' => __('Edit'),
            'delete_action' => __('Delete'),
        ];
    }
}
