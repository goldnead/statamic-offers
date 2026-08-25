<?php

namespace Goldnead\StatamicOffers\Http\Resources\Cp;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * The listing payload, built the way core builds its own.
 *
 * `HasRequestedColumns` plus `setPreferred()` is what makes the column picker
 * real rather than a control that reports success and changes nothing.
 */
class OffersCollection extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = ListedOffer::class;

    protected $columns;

    protected ?string $columnPreferenceKey = null;

    public function columnPreferenceKey(string $key): self
    {
        $this->columnPreferenceKey = $key;

        return $this;
    }

    private function setColumns(): self
    {
        $columns = new Columns([
            Column::make('name')->label(__('statamic-offers::messages.column_name'))->sortable(true)->defaultOrder(1),
            Column::make('handle')->label(__('statamic-offers::messages.column_handle'))->sortable(true)->defaultOrder(2),
            Column::make('amount')->label(__('statamic-offers::messages.column_amount'))->sortable(true)->numeric(true)->defaultOrder(3),
            Column::make('slot')->label(__('statamic-offers::messages.column_slot'))->sortable(true)->defaultOrder(4),
            Column::make('bumps')->label(__('statamic-offers::messages.column_bumps'))->sortable(false)->numeric(true)->defaultOrder(5),
            Column::make('performance')->label(__('statamic-offers::messages.column_performance'))->sortable(false)->defaultOrder(6),
            Column::make('active')->label(__('statamic-offers::messages.column_active'))->sortable(true)->defaultOrder(7),
            Column::make('product')->label(__('statamic-offers::messages.column_product'))->sortable(true)->defaultOrder(8)->defaultVisibility(false)->visible(false),
        ]);

        if ($key = $this->columnPreferenceKey) {
            $columns->setPreferred($key);
        }

        $this->columns = $columns->rejectUnlisted()->values();

        return $this;
    }

    public function toArray($request)
    {
        $this->setColumns();

        return $this->collection;
    }

    public function with($request)
    {
        return [
            'meta' => [
                // Read out of every response by the Listing; missing, the read
                // throws inside its own promise and the screen shows "Something
                // went wrong" over a page where everything works.
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
