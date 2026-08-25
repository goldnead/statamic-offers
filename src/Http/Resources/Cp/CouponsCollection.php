<?php

namespace Goldnead\StatamicOffers\Http\Resources\Cp;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * The listing payload, built the way core builds its own.
 *
 * Same shape as the offers listing next door, for the same reason: without
 * `HasRequestedColumns` and `setPreferred()` the column picker is a control that
 * reports success and changes nothing, and without `meta.columns` on *every*
 * response the listing throws inside its own promise and shows "Something went
 * wrong" over a page where everything works.
 */
class CouponsCollection extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = ListedCoupon::class;

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
            Column::make('code')->label(__('statamic-offers::messages.coupon_column_code'))->sortable(true)->defaultOrder(1),
            Column::make('name')->label(__('statamic-offers::messages.coupon_column_name'))->sortable(true)->defaultOrder(2),
            // Not sortable: the cell is a percentage on some rows and an amount
            // on others, and there is no column that orders both honestly.
            Column::make('discount')->label(__('statamic-offers::messages.coupon_column_discount'))->sortable(false)->defaultOrder(3),
            Column::make('validity')->label(__('statamic-offers::messages.coupon_column_validity'))->sortable(true)->defaultOrder(4),
            Column::make('usage')->label(__('statamic-offers::messages.coupon_column_usage'))->sortable(true)->numeric(true)->defaultOrder(5),
            Column::make('active')->label(__('statamic-offers::messages.coupon_column_active'))->sortable(true)->defaultOrder(6),
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
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
