<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    Button, CommandPaletteItem, ConfirmationModal, DropdownItem,
} from '@statamic/cms/ui';

/**
 * Offers: the list.
 *
 * A row leads to the offer's own page, and "New" leads to the empty one. The
 * form lives there (`Offers/Show.vue`), not in a stack: an offer has prices,
 * bumps, withdrawal terms, checkout fields, countries, seats and a short link,
 * which is more than a side panel carries.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    createUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    sortColumn: { type: String, default: 'name' },
    sortDirection: { type: String, default: 'asc' },
    hasAny: { type: Boolean, default: false },
    filters: { type: Array, default: () => [] },
    t: { type: Object, required: true },
});

const availabilityColor = (row) => ({
    sold_out: 'red', ended: 'red', not_yet: 'amber', limited: 'default', unlimited: 'gray',
}[row.availability?.state] ?? 'gray');

/**
 * The listing fetches its own rows over axios; an Inertia redirect updates the
 * page's props but never touches them. Without asking it to refresh, a deleted
 * row is still there afterwards and the delete looks like it failed.
 */
const listing = ref(null);

/**
 * Deleting asks first.
 *
 * An offer carries its counters, and those are the only record of whether it
 * ever worked. Core asks before every destructive action; a listing that
 * deletes on one click is the one screen in the Control Panel that does not.
 */
const deleting = ref(null);

const deletePrompt = computed(() => (deleting.value
    ? props.t.delete_body.replace(':name', deleting.value.name)
    : ''));

function confirmRemove() {
    const row = deleting.value;
    deleting.value = null;

    if (row) {
        router.delete(`${props.storeUrl}/${row.id}`, {
            preserveScroll: true,
            onSuccess: () => listing.value?.refresh(),
        });
    }
}

const statusColor = (row) => {
    if (!row.sellable) return 'red';

    return row.active ? 'green' : 'default';
};
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.title]" />

        <Header :title="t.title" icon="money-cashier-price-tag">
            <Button variant="primary" :text="t.new" :href="createUrl" />
        </Header>

        <CommandPaletteItem
            :text="[t.utilities, t.title]"
            :url="listingUrl"
            icon="money-cashier-price-tag"
            prioritize
        />

        <EmptyStateMenu v-if="!hasAny" :heading="t.empty_heading">
            <EmptyStateItem
                :heading="t.empty_title"
                :description="t.empty_description"
                icon="money-cashier-price-tag"
                :href="createUrl"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            ref="listing"
            :url="listingUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-offers.offers"
            push-query
        >
            <template #cell-name="{ row }">
                <a :href="row.show_url" class="text-start font-medium hover:text-primary">{{ row.name }}</a>
                <span v-if="!row.sellable" class="block text-2xs text-red-600 dark:text-red-400">
                    {{ t.not_sellable }}
                </span>
            </template>

            <template #cell-handle="{ row }">
                <span class="font-mono text-xs">{{ row.handle }}</span>
            </template>

            <template #cell-amount="{ row }">
                <span v-if="row.amount" class="tabular-nums">{{ row.amount }} {{ row.currency }}</span>
                <span v-else class="text-gray-500 dark:text-gray-400">{{ t.no_price }}</span>
                <span v-if="row.compare_at" class="block text-2xs text-gray-500 dark:text-gray-400 tabular-nums">
                    <span class="line-through whitespace-nowrap">{{ row.compare_at }} {{ row.currency }}</span>
                    <span v-if="row.discount_percent" class="whitespace-nowrap"> · −{{ row.discount_percent }} %</span>
                </span>
            </template>

            <!-- Paid revenue, and how many. The column only exists when the
                 payment tables do, so an empty cell here means "nothing sold"
                 and never "nothing to read from". -->
            <template #cell-revenue="{ row }">
                <span class="tabular-nums">{{ row.revenue }} {{ row.currency }}</span>
                <span v-if="row.sold" class="block text-2xs text-gray-500 dark:text-gray-400 tabular-nums">
                    {{ t.sold_count.replace(':count', row.sold) }}
                </span>
            </template>

            <template #cell-availability="{ row }">
                <Badge :color="availabilityColor(row)" :text="row.availability.label" />
            </template>

            <template #cell-slot="{ row }">
                <Badge :text="row.slot_label" />
            </template>

            <!-- Empty rather than 0: a column full of zeroes reads as a
                 broken feature, an empty cell reads as "none". -->
            <template #cell-bumps="{ row }">
                <span v-if="row.bumps_count" class="tabular-nums">{{ row.bumps_count }}</span>
            </template>

            <template #cell-performance="{ row }">
                <span class="tabular-nums">{{ row.accepted_count }} / {{ row.shown_count }}</span>
                <span v-if="row.conversion !== null" class="block text-2xs text-gray-500 dark:text-gray-400 tabular-nums">
                    {{ row.conversion }} %
                </span>
            </template>

            <template #cell-active="{ row }">
                <Badge :color="statusColor(row)" :text="row.active ? t.yes : t.no" />
            </template>

            <template #cell-confirmation="{ row }">
                <Badge :color="row.confirmation_silent ? 'orange' : 'gray'" :text="row.confirmation" />
            </template>

            <template #cell-product="{ row }">
                <span class="font-mono text-xs">{{ row.product }}</span>
                <span v-if="row.products_count" class="ml-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ t.bundle_of.replace(':count', row.products_count) }}
                </span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="edit" :text="t.edit_action" :href="row.show_url" />
                <DropdownItem icon="trash" variant="destructive" :text="t.delete_action" @click="deleting = row" />
            </template>
        </Listing>

        <ConfirmationModal
            :open="deleting !== null"
            :title="t.delete_title"
            :body-text="deletePrompt"
            :button-text="t.delete_action"
            danger
            @update:open="deleting = $event ? deleting : null"
            @confirm="confirmRemove"
        />

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-offers#readme"
        />
    </div>
</template>
