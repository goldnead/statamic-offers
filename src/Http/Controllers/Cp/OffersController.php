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
            'product' => ['required', 'string', 'max:191'],
            // Nullable means "use the catalogue price". Nullable *integer*
            // means nobody can post "12,00" and have it read as 12 cents.
            'amount_cent' => ['nullable', 'integer', 'min:1'],
            'compare_at_cent' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'headline' => ['nullable', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:5000'],
            'button_label' => ['nullable', 'string', 'max:191'],
            'slot' => ['required', Rule::in(Offer::slots())],
            'active' => ['boolean'],
        ]);

        $data['active'] = $request->boolean('active');
        $data['currency'] = $data['currency'] ? strtoupper($data['currency']) : null;

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
