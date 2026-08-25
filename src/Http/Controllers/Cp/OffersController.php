<?php

namespace Goldnead\StatamicOffers\Http\Controllers\Cp;

use Goldnead\StatamicOffers\Http\Resources\Cp\OffersCollection;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Support\Catalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Statamic;

/**
 * Offers in the Control Panel.
 *
 * Unlike the payment and booking screens, this one writes: an offer is
 * something a site owner *makes*, and the whole point of the addon is that the
 * words and the price can be changed without a developer.
 */
class OffersController extends CpController
{
    use QueriesFilters;

    public const SCOPE = 'statamic-offers-offers';

    public function index(FilteredRequest $request)
    {
        $this->authorizeAccess();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        return Inertia::render('statamic-offers::Offers/Index', [
            'listingUrl' => cp_route('utilities.offers'),
            'storeUrl' => cp_route('utilities.offers.store'),
            'sortColumn' => 'name',
            'sortDirection' => 'asc',
            'hasAny' => Offer::query()->exists(),
            // What may be sold. Offered as a list rather than a free text field
            // because an offer pointing at a product nobody configured is the
            // single most likely way to build one that cannot be bought.
            'products' => $this->products(),
            'slots' => collect(Offer::slots())->map(fn (string $slot) => [
                'value' => $slot,
                'label' => __('statamic-offers::messages.slot_'.$slot),
                'description' => __('statamic-offers::messages.slot_'.$slot.'_description'),
            ])->all(),
            'currency' => (string) config('statamic-payments.currency', 'EUR'),
            // Only offers that are actually placed at checkout. Handing over
            // every offer and hoping the form picks the right ones is how a
            // bump ends up pointing at a post-purchase upsell that then never
            // renders.
            'bumpOptions' => $this->bumpOptions(),
            // Every label on the screen, translated here rather than in the
            // template. See the coupons screen for the reasoning; the two are
            // built the same way on purpose.
            't' => $this->strings(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAccess();

        $offer = Offer::create($this->validated($request));

        return back()->with('message', __('statamic-offers::messages.saved', ['name' => $offer->name]));
    }

    public function update(Request $request, Offer $offer)
    {
        $this->authorizeAccess();

        $offer->update($this->validated($request, $offer));

        return back()->with('message', __('statamic-offers::messages.saved', ['name' => $offer->name]));
    }

    public function destroy(Request $request, Offer $offer)
    {
        $this->authorizeAccess();

        // The counts go with it. An offer nobody can see any more should not
        // keep contributing to a conversion report.
        $offer->delete();

        return back()->with('message', __('statamic-offers::messages.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Offer $offer = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'handle' => [
                'required', 'string', 'max:191', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('offers', 'handle')->ignore($offer?->getKey()),
            ],
            // Only what the catalogue actually sells. A free-text product was
            // how an offer could be pointed at another offer, and that pair
            // asked each other what they cost until the process ran out of
            // memory — taking the listing you would have deleted it from with
            // it.
            'product' => ['required', 'string', 'max:191', Rule::in(array_keys(app(Catalogue::class)->all()))],
            // Nullable means "use the catalogue price". Nullable *integer*
            // means nobody can post "12,00" and have it read as 12 cents.
            'amount_cent' => ['nullable', 'integer', 'min:1'],
            'compare_at_cent' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'headline' => ['nullable', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:5000'],
            'button_label' => ['nullable', 'string', 'max:191'],
            'image' => ['nullable', 'string', 'max:500'],
            'slot' => ['required', Rule::in(Offer::slots())],
            // A select the browser fills freely. Every handle has to be an
            // offer that exists *and* is placed at checkout, or the checkbox
            // renders as a product the buyer cannot be charged for; and it may
            // never be this offer, which would ask itself what it costs.
            'bumps' => ['nullable', 'array'],
            'bumps.*' => [
                'string',
                Rule::exists('offers', 'handle')->where('slot', Offer::SLOT_BUMP),
                Rule::notIn([(string) $request->input('handle')]),
            ],
            'active' => ['boolean'],
        ], [
            'bumps.*.exists' => __('statamic-offers::messages.field_bumps_invalid'),
            'bumps.*.not_in' => __('statamic-offers::messages.field_bumps_invalid'),
        ]);

        // `validate()` omits a nullable key that was never sent, so reading it
        // directly is a 500 on every client that leaves the field out — which
        // is every client that is not this addon's own form.
        $data['active'] = $request->boolean('active');
        $data['currency'] = ($data['currency'] ?? null) ? strtoupper($data['currency']) : null;

        // Duplicates would render the same checkbox twice and charge for it
        // twice. The order is kept, because the order somebody dragged them
        // into is the whole editorial decision.
        $data['bumps'] = array_values(array_unique(array_filter(
            (array) ($data['bumps'] ?? []), 'is_string'
        )));

        return $data;
    }

    protected function json(FilteredRequest $request)
    {
        $query = Offer::query();

        if ($search = trim((string) $request->get('search', ''))) {
            $this->applySearch($query, $search);
        }

        $badges = $this->queryFilters($query, $request->filters, ['scope' => self::SCOPE]);

        [$column, $direction] = $this->order($request);
        $query->orderBy($column, $direction);

        return (new OffersCollection($query->paginate(Statamic::cpPerPage($request->get('perPage')))))
            ->columnPreferenceKey('statamic-offers.offers.columns')
            ->additional(['meta' => ['activeFilterBadges' => $badges]]);
    }

    protected function authorizeAccess(): void
    {
        // Through the Gate, where `Utility::register` puts the permission and
        // where the route's `can:` middleware looks.
        abort_unless(Gate::allows('access offers utility'), 403);
    }

    /**
     * @param  Builder<Offer>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        // `%` and `_` are LIKE wildcards; the ESCAPE clause is spelled out
        // because SQLite, unlike MySQL and Postgres, has no default one.
        $escaped = addcslashes($term, '%_\\');

        $query->where(function (Builder $q) use ($escaped) {
            foreach (['name', 'handle', 'product', 'headline'] as $column) {
                $q->orWhereRaw($column." LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
            }
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function order(FilteredRequest $request): array
    {
        // A positive list: `sort` comes from the query string and would
        // otherwise order by any column in the table. `amount` shows a
        // formatted string and sorts on the integer behind it.
        $sortable = [
            'name' => 'name',
            'handle' => 'handle',
            'amount' => 'amount_cent',
            'slot' => 'slot',
            'active' => 'active',
            'product' => 'product',
        ];

        $requested = (string) $request->get('sort', 'name');
        $direction = strtolower((string) $request->get('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        return [$sortable[$requested] ?? 'name', $direction];
    }

    /**
     * Offers that may be carried as a bump.
     *
     * @return list<array<string, string>>
     */
    protected function bumpOptions(): array
    {
        return Offer::query()
            ->where('slot', Offer::SLOT_BUMP)
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
            'title' => __('statamic-offers::messages.utility_title'),
            'utilities' => __('Utilities'),
            'empty_heading' => __('statamic-offers::messages.empty_heading'),
            'empty_title' => __('statamic-offers::messages.empty_title'),
            'empty_description' => __('statamic-offers::messages.empty_description'),
            'new' => __('statamic-offers::messages.new_offer'),
            'edit' => __('statamic-offers::messages.edit_offer'),
            'delete_title' => __('statamic-offers::messages.delete_title'),
            'delete_body' => __('statamic-offers::messages.delete_body', ['name' => ':name']),
            'not_sellable' => __('statamic-offers::messages.not_sellable'),
            'no_price' => __('statamic-offers::messages.no_price'),
            'field_name' => __('statamic-offers::messages.field_name'),
            'field_name_help' => __('statamic-offers::messages.field_name_help'),
            'field_handle' => __('statamic-offers::messages.field_handle'),
            'field_handle_help' => __('statamic-offers::messages.field_handle_help'),
            'field_product' => __('statamic-offers::messages.field_product'),
            'field_product_help' => __('statamic-offers::messages.field_product_help'),
            'field_amount' => __('statamic-offers::messages.field_amount'),
            'field_amount_help' => __('statamic-offers::messages.field_amount_help'),
            'field_compare_at' => __('statamic-offers::messages.field_compare_at'),
            'field_compare_at_help' => __('statamic-offers::messages.field_compare_at_help'),
            'field_slot' => __('statamic-offers::messages.field_slot'),
            'field_bumps' => __('statamic-offers::messages.field_bumps'),
            'field_bumps_help' => __('statamic-offers::messages.field_bumps_help'),
            'field_bumps_placeholder' => __('statamic-offers::messages.field_bumps_placeholder'),
            'field_bumps_empty' => __('statamic-offers::messages.field_bumps_empty'),
            'field_headline' => __('statamic-offers::messages.field_headline'),
            'field_body' => __('statamic-offers::messages.field_body'),
            'field_button' => __('statamic-offers::messages.field_button'),
            'field_image' => __('statamic-offers::messages.field_image'),
            'field_image_help' => __('statamic-offers::messages.field_image_help'),
            'field_active' => __('statamic-offers::messages.field_active'),
            'yes' => __('statamic-offers::messages.yes'),
            'no' => __('statamic-offers::messages.no'),
            'save' => __('Save'),
            'cancel' => __('Cancel'),
            'edit_action' => __('Edit'),
            'delete_action' => __('Delete'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    protected function products(): array
    {
        return collect(app(Catalogue::class)->all())
            ->map(fn (array $product, string $handle) => [
                'value' => $handle,
                'label' => $product['name'] ?? $handle,
            ])
            ->values()
            ->all();
    }
}
