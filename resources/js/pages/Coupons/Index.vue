<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    Button, CommandPaletteItem, Stack, Heading, ConfirmationModal,
    Field, Input, Combobox, Switch, DropdownItem, Alert,
} from '@statamic/cms/ui';

/**
 * Coupons.
 *
 * Built as the twin of the offers screen next door, on purpose: two utilities
 * from the same addon that answer the same gestures differently is a worse tell
 * than either of them looking slightly off on its own.
 *
 * Every label arrives finished in `t`. Nothing here composes a sentence, and
 * nothing here decides what a code is worth — the row shows what the server
 * worked out, and the form posts what somebody typed for the server to judge.
 */
const props = defineProps({
    listingUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    generateUrl: { type: String, required: true },
    timezone: { type: String, default: 'UTC' },
    batch: { type: Object, default: () => ({ maxCount: 100, maxPrefix: 12, minLength: 6, maxLength: 12, defaultLength: 8 }) },
    actionUrl: { type: String, required: true },
    filters: { type: Array, default: () => [] },
    sortColumn: { type: String, default: 'code' },
    sortDirection: { type: String, default: 'asc' },
    hasAny: { type: Boolean, default: false },
    offers: { type: Array, default: () => [] },
    currency: { type: String, default: 'EUR' },
    t: { type: Object, required: true },
});

const blank = () => ({
    code: '', name: '',
    percent: null, amount_cent: null, currency: null,
    offers: [],
    starts_at: null, ends_at: null,
    max_uses: null,
    active: true,
});

/**
 * The listing fetches its own rows over axios; an Inertia redirect updates the
 * page's props but never touches them. Without asking it to refresh, a saved
 * row simply is not there afterwards and the save looks like it failed.
 */
const listing = ref(null);

const open = ref(false);
const saving = ref(false);
const errors = ref({});
const editing = ref(null);
const form = ref(blank());

const title = computed(() => (editing.value ? props.t.edit : props.t.new));

function create() {
    editing.value = null;
    form.value = blank();
    errors.value = {};
    open.value = true;
}

function edit(row) {
    editing.value = row;
    form.value = { ...blank(), ...row.edit_values };
    errors.value = {};
    open.value = true;
}

function save() {
    saving.value = true;
    const url = editing.value ? `${props.storeUrl}/${editing.value.id}` : props.storeUrl;
    const method = editing.value ? 'patch' : 'post';

    // `router`, not axios: the Inertia router is what drives the progress bar,
    // the flash toast, the dirty-state guard and the back button.
    router[method](url, form.value, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { open.value = false; errors.value = {}; listing.value?.refresh(); },
        onFinish: () => { saving.value = false; },
    });
}

/**
 * Deleting asks first.
 *
 * A coupon carries the count of how often it was redeemed, which is the only
 * record that a campaign worked at all.
 */
const deleting = ref(null);

const deletePrompt = computed(() => (deleting.value
    ? props.t.delete_body.replace(':code', deleting.value.code)
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
    if (!row.active) return 'default';

    return row.live ? 'green' : 'amber';
};

/** The percent and the amount are one decision, so filling one clears the other. */
function percentChanged(value) {
    form.value.percent = value;
    if (value !== null && value !== '') form.value.amount_cent = null;
}

function amountChanged(value) {
    form.value.amount_cent = value;
    if (value !== null && value !== '') form.value.percent = null;
}

/**
 * Many codes at once.
 *
 * A second stack with the same fields as one coupon, minus the code and plus
 * a count. All of them are made in one transaction on the server, or none.
 */
const blankBatch = () => ({
    count: 10, prefix: '', length: props.batch.defaultLength, name: '',
    percent: null, amount_cent: null, currency: null,
    offers: [],
    starts_at: null, ends_at: null,
    max_uses: 1,
});

const batchOpen = ref(false);
const batchSaving = ref(false);
const batchErrors = ref({});
const batchForm = ref(blankBatch());

const timezoneNote = computed(() => props.t.timezone_note.replace(':timezone', props.timezone));

function openBatch() {
    batchForm.value = blankBatch();
    batchErrors.value = {};
    batchOpen.value = true;
}

function batchPercentChanged(value) {
    batchForm.value.percent = value;
    if (value !== null && value !== '') batchForm.value.amount_cent = null;
}

function batchAmountChanged(value) {
    batchForm.value.amount_cent = value;
    if (value !== null && value !== '') batchForm.value.percent = null;
}

function generate() {
    batchSaving.value = true;

    router.post(props.generateUrl, batchForm.value, {
        preserveScroll: true,
        onError: (e) => { batchErrors.value = e || {}; },
        onSuccess: () => { batchOpen.value = false; batchErrors.value = {}; listing.value?.refresh(); },
        onFinish: () => { batchSaving.value = false; },
    });
}
</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[t.title]" />

        <Header :title="t.title" icon="shopping-store-discount-percent">
            <Button :text="t.generate" @click="openBatch" />
            <Button variant="primary" :text="t.new" @click="create" />
        </Header>

        <CommandPaletteItem
            :text="[t.utilities, t.title, t.generate]"
            icon="shopping-store-discount-percent"
            :action="openBatch"
        />

        <CommandPaletteItem
            :text="[t.utilities, t.title]"
            :url="listingUrl"
            icon="shopping-store-discount-percent"
            prioritize
        />

        <EmptyStateMenu v-if="!hasAny" :heading="t.empty_heading">
            <EmptyStateItem
                :heading="t.empty_title"
                :description="t.empty_description"
                icon="shopping-store-discount-percent"
                @click="create"
            />
        </EmptyStateMenu>

        <!-- The core listing, fed the way core feeds its own: an `actionUrl`
             is what turns on the checkboxes and the bulk toolbar, and a
             `preferences-prefix` is what makes saved views and the column
             picker remember anything. -->
        <Listing
            v-else
            ref="listing"
            :url="listingUrl"
            :action-url="actionUrl"
            :filters="filters"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-offers.coupons"
            push-query
        >
            <template #cell-code="{ row }">
                <button type="button" class="font-mono text-xs font-medium hover:text-primary" @click="edit(row)">
                    {{ row.code }}
                </button>
            </template>

            <template #cell-name="{ row }">
                <span v-if="row.name">{{ row.name }}</span>
            </template>

            <template #cell-discount="{ row }">
                <span v-if="row.discount" class="tabular-nums">{{ row.discount }}</span>
            </template>

            <template #cell-validity="{ row }">
                <span>{{ row.validity }}</span>
                <span v-if="row.note" class="block text-2xs text-gray-500 dark:text-gray-400">{{ row.note }}</span>
            </template>

            <template #cell-usage="{ row }">
                <span class="tabular-nums">{{ row.usage }}</span>
            </template>

            <template #cell-active="{ row }">
                <Badge :color="statusColor(row)" :text="row.active ? t.yes : t.no" />
            </template>

            <template #prepended-row-actions="{ row }">
                <DropdownItem icon="edit" :text="t.edit_action" @click="edit(row)" />
                <DropdownItem icon="trash" variant="destructive" :text="t.delete_action" @click="deleting = row" />
            </template>
        </Listing>

        <!-- `:open`, not `v-if`. The modal owns its own visibility and its own
             focus trap; mounting it conditionally means it never opens, which
             looks exactly like a Delete button that does nothing. -->
        <ConfirmationModal
            :open="deleting !== null"
            :title="t.delete_title"
            :body-text="deletePrompt"
            :button-text="t.delete_action"
            danger
            @update:open="deleting = $event ? deleting : null"
            @confirm="confirmRemove"
        />

        <Stack v-model:open="open" size="narrow">
            <!-- Surfaces use core's tokens, never a literal colour: the palette
                 is themeable at runtime, and a hard-coded surface drifts the
                 moment somebody re-themes their Control Panel. -->
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="title" size="lg" />
                </div>

                <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    <Alert v-if="errors.coupon" variant="error" :text="errors.coupon" />

                    <Field :label="t.field_code" :instructions="t.field_code_help" :error="errors.code" required>
                        <Input v-model="form.code" class="font-mono" />
                    </Field>

                    <Field :label="t.field_name" :instructions="t.field_name_help" :error="errors.name">
                        <Input v-model="form.name" />
                    </Field>

                    <div>
                        <div class="grid grid-cols-2 gap-4">
                            <Field :label="t.field_percent" :error="errors.percent">
                                <Input
                                    :model-value="form.percent"
                                    type="number"
                                    min="1"
                                    max="100"
                                    append="%"
                                    @update:model-value="percentChanged($event === '' ? null : Number($event))"
                                />
                            </Field>

                            <Field :label="t.field_amount" :error="errors.amount_cent">
                                <Input
                                    :model-value="form.amount_cent"
                                    type="number"
                                    min="1"
                                    :append="form.currency || currency"
                                    @update:model-value="amountChanged($event === '' ? null : Number($event))"
                                />
                            </Field>
                        </div>

                        <!-- One explanation under both, because the two fields
                             are one decision: what this code takes off. -->
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ t.field_discount_help }}</p>
                    </div>

                    <Field :label="t.field_currency" :instructions="t.field_currency_help" :error="errors.currency">
                        <Input v-model="form.currency" class="font-mono uppercase" :placeholder="currency" />
                    </Field>

                    <Field :label="t.field_offers" :instructions="t.field_offers_help" :error="errors.offers">
                        <Combobox
                            v-model="form.offers"
                            :options="offers"
                            :placeholder="t.field_offers_placeholder"
                            multiple
                            searchable
                            clearable
                        />
                    </Field>

                    <!-- A plain date input rather than core's `<DatePicker>`,
                         and not for want of trying: that component hands its
                         `modelValue` straight to reka-ui, which calls `.copy()`
                         on it. A stored date arrives here as a string, so the
                         component throws during setup and takes the whole
                         screen with it. Binding a real `DateValue` would mean
                         bundling a second copy of `@internationalized/date`,
                         which is the trade this addon does not make. -->
                    <div>
                        <div class="grid grid-cols-2 gap-4">
                            <Field :label="t.field_starts_at" :error="errors.starts_at">
                                <Input v-model="form.starts_at" type="date" />
                            </Field>

                            <Field :label="t.field_ends_at" :error="errors.ends_at">
                                <Input v-model="form.ends_at" type="date" />
                            </Field>
                        </div>

                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ t.field_dates_help }} {{ timezoneNote }}</p>
                    </div>

                    <Field :label="t.field_max_uses" :instructions="t.field_max_uses_help" :error="errors.max_uses">
                        <Input v-model.number="form.max_uses" type="number" min="1" :placeholder="t.usage_unlimited" />
                    </Field>

                    <Field :label="t.field_active">
                        <Switch v-model="form.active" />
                    </Field>
                </div>

                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="t.cancel" @click="open = false" />
                        <Button variant="primary" :text="t.save" :disabled="saving" @click="save" />
                    </div>
                </div>
            </div>
        </Stack>

        <!-- The batch. Same shell as the single form, so the two read as one
             screen with two doors rather than two features. -->
        <Stack v-model:open="batchOpen" size="narrow">
            <div class="flex h-full flex-col bg-content-bg">
                <div class="border-b border-content-border px-6 py-4">
                    <Heading :text="t.generate_title" size="lg" />
                </div>

                <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ t.generate_help }}</p>

                    <Alert v-if="batchErrors.count" variant="error" :text="batchErrors.count" />

                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="t.field_count" :error="batchErrors.count" required>
                            <Input v-model.number="batchForm.count" type="number" min="1" :max="batch.maxCount" />
                        </Field>

                        <Field :label="t.field_length" :instructions="t.field_length_help" :error="batchErrors.length">
                            <Input v-model.number="batchForm.length" type="number" :min="batch.minLength" :max="batch.maxLength" />
                        </Field>
                    </div>

                    <Field :label="t.field_prefix" :instructions="t.field_prefix_help" :error="batchErrors.prefix">
                        <Input v-model="batchForm.prefix" class="font-mono uppercase" :maxlength="batch.maxPrefix" />
                    </Field>

                    <Field :label="t.field_name_pattern" :instructions="t.field_name_pattern_help" :error="batchErrors.name">
                        <Input v-model="batchForm.name" />
                    </Field>

                    <div>
                        <div class="grid grid-cols-2 gap-4">
                            <Field :label="t.field_percent" :error="batchErrors.percent">
                                <Input
                                    :model-value="batchForm.percent"
                                    type="number"
                                    min="1"
                                    max="100"
                                    append="%"
                                    @update:model-value="batchPercentChanged($event === '' ? null : Number($event))"
                                />
                            </Field>

                            <Field :label="t.field_amount" :error="batchErrors.amount_cent">
                                <Input
                                    :model-value="batchForm.amount_cent"
                                    type="number"
                                    min="1"
                                    :append="batchForm.currency || currency"
                                    @update:model-value="batchAmountChanged($event === '' ? null : Number($event))"
                                />
                            </Field>
                        </div>

                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ t.field_discount_help }}</p>
                    </div>

                    <Field :label="t.field_currency" :instructions="t.field_currency_help" :error="batchErrors.currency">
                        <Input v-model="batchForm.currency" class="font-mono uppercase" :placeholder="currency" />
                    </Field>

                    <Field :label="t.field_offers" :instructions="t.field_offers_help" :error="batchErrors.offers">
                        <Combobox
                            v-model="batchForm.offers"
                            :options="offers"
                            :placeholder="t.field_offers_placeholder"
                            multiple
                            searchable
                            clearable
                        />
                    </Field>

                    <div>
                        <div class="grid grid-cols-2 gap-4">
                            <Field :label="t.field_starts_at" :error="batchErrors.starts_at">
                                <Input v-model="batchForm.starts_at" type="date" />
                            </Field>

                            <Field :label="t.field_ends_at" :error="batchErrors.ends_at">
                                <Input v-model="batchForm.ends_at" type="date" />
                            </Field>
                        </div>

                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ t.field_dates_help }} {{ timezoneNote }}</p>
                    </div>

                    <Field :label="t.field_max_uses" :instructions="t.field_max_uses_batch_help" :error="batchErrors.max_uses">
                        <Input v-model.number="batchForm.max_uses" type="number" min="1" :placeholder="t.usage_unlimited" />
                    </Field>
                </div>

                <div class="border-t border-content-border px-6 py-4">
                    <div class="flex justify-end gap-2">
                        <Button :text="t.cancel" @click="batchOpen = false" />
                        <Button variant="primary" :text="t.generate_action" :disabled="batchSaving" @click="generate" />
                    </div>
                </div>
            </div>
        </Stack>

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-offers#readme"
        />
    </div>
</template>
