<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    Button, CommandPaletteItem, Stack, Heading, ConfirmationModal,
    Field, Input, Textarea, Select, Switch, DropdownItem, Alert,
} from '@statamic/cms/ui';

/**
 * Offers.
 *
 * The one screen in this family that writes, because an offer is something a
 * site owner *makes*: the words and the price are the product here, and needing
 * a developer for them would defeat the addon.
 *
 * The editor is a panel rather than a page of its own. An offer is eleven
 * fields; a full publish form with its own route would be more navigation than
 * content.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    sortColumn: { type: String, default: 'name' },
    sortDirection: { type: String, default: 'asc' },
    hasAny: { type: Boolean, default: false },
    products: { type: Array, default: () => [] },
    slots: { type: Array, default: () => [] },
    currency: { type: String, default: 'EUR' },
});

const blank = () => ({
    name: '', handle: '', product: props.products[0]?.value ?? '',
    amount_cent: null, compare_at_cent: null, currency: null,
    headline: '', body: '', button_label: '', image: '',
    slot: 'standalone', active: true,
});

const open = ref(false);
const saving = ref(false);
const errors = ref({});
const editing = ref(null);
const form = ref(blank());

const title = computed(() => editing.value
    ? __('statamic-offers::messages.edit_offer')
    : __('statamic-offers::messages.new_offer'));

const slotHelp = computed(() => props.slots.find((s) => s.value === form.value.slot)?.description ?? '');

function create() {
    editing.value = null;
    form.value = blank();
    errors.value = {};
    handleTouched.value = false;
    open.value = true;
}

function edit(row) {
    editing.value = row;
    form.value = { ...blank(), ...row.edit_values };
    errors.value = {};
    handleTouched.value = true;
    open.value = true;
}

/**
 * The handle follows the name until somebody types a handle of their own.
 *
 * Watched rather than hung off `@blur`: a suggestion that only appears when a
 * field happens to lose focus is a suggestion most people never see. Once the
 * field has been touched it is left alone — a handle that keeps rewriting
 * itself after a choice was made is the kind of helpfulness that loses work.
 */
const handleTouched = ref(false);

const slugify = (value) => (value || '')
    .toLowerCase()
    .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

watch(() => form.value.name, (name) => {
    if (editing.value || handleTouched.value) return;
    form.value.handle = slugify(name);
});

function save() {
    saving.value = true;
    const url = editing.value ? `${props.storeUrl}/${editing.value.id}` : props.storeUrl;
    const method = editing.value ? 'patch' : 'post';

    router[method](url, form.value, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { open.value = false; errors.value = {}; },
        onFinish: () => { saving.value = false; },
    });
}

/**
 * Deleting asks first.
 *
 * An offer carries its counters, and those are the only record of whether it
 * ever worked. Core asks before every destructive action; a listing that
 * deletes on one click is the one screen in the Control Panel that does not.
 */
const deleting = ref(null);

const deletePrompt = computed(() => deleting.value
    ? __('statamic-offers::messages.delete_body', { name: deleting.value.name })
    : '');

function confirmRemove() {
    const row = deleting.value;
    deleting.value = null;

    if (row) router.delete(`${props.storeUrl}/${row.id}`, { preserveScroll: true });
}

const statusColor = (row) => {
    if (!row.sellable) return 'red';

    return row.active ? 'green' : 'default';
};
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[__('statamic-offers::messages.utility_title')]" />

        <Header :title="__('statamic-offers::messages.utility_title')" icon="money-cashier-price-tag">
            <Button variant="primary" :text="__('statamic-offers::messages.new_offer')" @click="create" />
        </Header>

        <CommandPaletteItem
            :text="[__('Utilities'), __('statamic-offers::messages.utility_title')]"
            :url="listingUrl"
            icon="money-cashier-price-tag"
            prioritize
        />

        <EmptyStateMenu v-if="!hasAny" :heading="__('statamic-offers::messages.empty_heading')">
            <EmptyStateItem
                :heading="__('statamic-offers::messages.empty_title')"
                :description="__('statamic-offers::messages.empty_description')"
                icon="money-cashier-price-tag"
                @click="create"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            :url="listingUrl"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-offers.offers"
            push-query
        >
            <template #cell-name="{ row }">
                <button type="button" class="font-medium hover:text-primary" @click="edit(row)">{{ row.name }}</button>
                <span v-if="!row.sellable" class="block text-2xs text-red-600 dark:text-red-400">
                    {{ __('statamic-offers::messages.not_sellable') }}
                </span>
            </template>

            <template #cell-handle="{ row }">
                <span class="font-mono text-xs">{{ row.handle }}</span>
            </template>

            <template #cell-amount="{ row }">
                <span v-if="row.amount" class="tabular-nums">{{ row.amount }} {{ row.currency }}</span>
                <span v-else class="text-gray-500 dark:text-gray-400">{{ __('statamic-offers::messages.no_price') }}</span>
                <span v-if="row.compare_at" class="block text-2xs text-gray-500 dark:text-gray-400 line-through tabular-nums">
                    {{ row.compare_at }} {{ row.currency }}
                </span>
            </template>

            <template #cell-slot="{ row }">
                <Badge :text="row.slot_label" />
            </template>

            <template #cell-performance="{ row }">
                <span class="tabular-nums">{{ row.accepted_count }} / {{ row.shown_count }}</span>
                <span v-if="row.conversion !== null" class="block text-2xs text-gray-500 dark:text-gray-400 tabular-nums">
                    {{ row.conversion }} %
                </span>
            </template>

            <template #cell-active="{ row }">
                <Badge :color="statusColor(row)" :text="row.active ? __('Yes') : __('No')" />
            </template>

            <template #cell-product="{ row }">
                <span class="font-mono text-xs">{{ row.product }}</span>
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="edit" :text="__('Edit')" @click="edit(row)" />
                <DropdownItem icon="trash" variant="destructive" :text="__('Delete')" @click="deleting = row" />
            </template>
        </Listing>

        <!-- A stack, which is what the Control Panel uses for editing beside a
             listing: it traps focus, hands `esc` back to whatever was under it,
             and cascades if something opens on top. A hand-built overlay does
             none of that and steals `esc` from its parent. -->
        <!-- `:open`, not `v-if`. The modal owns its own visibility and its own
             focus trap; mounting it conditionally means it never opens, which
             looked exactly like a Delete button that does nothing. -->
        <ConfirmationModal
            :open="deleting !== null"
            :title="__('statamic-offers::messages.delete_title')"
            :body-text="deletePrompt"
            :button-text="__('Delete')"
            danger
            @update:open="deleting = $event ? deleting : null"
            @confirm="confirmRemove"
        />

        <Stack v-model:open="open" size="narrow">
            <!-- Surfaces use core's tokens, never a literal colour: the
                 palette is themeable at runtime, and a hard-coded surface
                 drifts the moment somebody re-themes their Control Panel. -->
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="title" size="lg" />
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                <Alert v-if="errors.offer" variant="error" :text="errors.offer" />

                <Field :label="__('statamic-offers::messages.field_name')" :instructions="__('statamic-offers::messages.field_name_help')" :error="errors.name" required>
                    <Input v-model="form.name" />
                </Field>

                <Field :label="__('statamic-offers::messages.field_handle')" :instructions="__('statamic-offers::messages.field_handle_help')" :error="errors.handle" required>
                    <Input v-model="form.handle" class="font-mono" @update:model-value="handleTouched = true" />
                </Field>

                <Field :label="__('statamic-offers::messages.field_product')" :instructions="__('statamic-offers::messages.field_product_help')" :error="errors.product" required>
                    <Select v-model="form.product" :options="products" />
                </Field>

                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="__('statamic-offers::messages.field_amount')" :error="errors.amount_cent">
                            <Input v-model.number="form.amount_cent" type="number" min="1" :append="currency" />
                        </Field>

                        <Field :label="__('statamic-offers::messages.field_compare_at')" :error="errors.compare_at_cent">
                            <Input v-model.number="form.compare_at_cent" type="number" min="1" :append="currency" />
                        </Field>
                    </div>

                    <!-- One explanation under both, because the two fields are
                         one decision: what this costs here, and what it says it
                         would otherwise cost. -->
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('statamic-offers::messages.field_amount_help') }}
                        {{ __('statamic-offers::messages.field_compare_at_help') }}
                    </p>
                </div>

                <Field :label="__('statamic-offers::messages.field_slot')" :instructions="slotHelp" :error="errors.slot" required>
                    <Select v-model="form.slot" :options="slots" />
                </Field>

                <Field :label="__('statamic-offers::messages.field_headline')" :error="errors.headline">
                    <Input v-model="form.headline" />
                </Field>

                <Field :label="__('statamic-offers::messages.field_body')" :error="errors.body">
                    <Textarea v-model="form.body" :rows="4" />
                </Field>

                <Field :label="__('statamic-offers::messages.field_button')" :error="errors.button_label">
                    <Input v-model="form.button_label" />
                </Field>

                <Field :label="__('statamic-offers::messages.field_image')" :instructions="__('statamic-offers::messages.field_image_help')" :error="errors.image">
                    <Input v-model="form.image" />
                </Field>

                <Field :label="__('statamic-offers::messages.field_active')">
                    <Switch v-model="form.active" />
                </Field>
                </div>

                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="__('Cancel')" @click="open = false" />
                        <Button variant="primary" :text="__('Save')" :disabled="saving" @click="save" />
                    </div>
                </div>
            </div>
        </Stack>

        <DocsCallout
            :topic="__('statamic-offers::messages.utility_title')"
            url="https://github.com/goldnead/statamic-offers#readme"
        />
    </div>
</template>
