<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, Listing, EmptyStateMenu, EmptyStateItem, DocsCallout,
    Button, CommandPaletteItem, Stack, Heading, ConfirmationModal,
    Field, Input, Textarea, Select, Combobox, Switch, DropdownItem, Alert,
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
    bumpOptions: { type: Array, default: () => [] },
    confirmationModes: { type: Array, default: () => [] },
    confirmationTemplates: { type: Array, default: () => [] },
    t: { type: Object, required: true },
});

const blank = () => ({
    name: '', handle: '', product: props.products[0]?.value ?? '',
    amount_cent: null, compare_at_cent: null, currency: null,
    headline: '', body: '', button_label: '', image: '',
    slot: 'standalone', bumps: [], active: true, products: [],
    // The standard mail, so that an offer created and saved without ever
    // opening this field still reaches its buyer.
    confirmation_mode: 'default', confirmation_template: null,
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

const slotHelp = computed(() => props.slots.find((s) => s.value === form.value.slot)?.description ?? '');

const confirmationHelp = computed(
    () => props.confirmationModes.find((m) => m.value === form.value.confirmation_mode)?.description ?? '',
);

/**
 * "Own mail" stays on the list even with no templates to pick from.
 *
 * Hiding it would be tidier on an empty install and wrong on a real one: an
 * offer already set to `custom` would open on a select that does not contain
 * its own value, and the next save would silently move it to the standard
 * mail. The choice stays, and the picker below explains why it is empty.
 */
const hasTemplates = computed(() => props.confirmationTemplates.length > 0);

const wantsTemplate = computed(() => form.value.confirmation_mode === 'custom');

/**
 * Was an dem gerade bearbeiteten Angebot haengt.
 *
 * Kommt mit der Zeile aus der Liste, nicht aus einem eigenen Abruf: ein
 * Kaestchen, das erst nachlaedt, sieht im Zweifel leer aus — und „leer" ist
 * hier eine Aussage, keine Ladephase.
 */
const usage = computed(() => editing.value?.usage ?? { funnels: [], automations: [] });

/**
 * Leaving "own mail" drops the template with it.
 *
 * The server does this too, and has to — it is the only side that can be
 * trusted. Here it is so the field does not quietly keep a value the form no
 * longer shows, and hand it back the moment somebody switches to `custom`
 * again as a choice they never made.
 */
watch(wantsTemplate, (wants) => {
    if (!wants) {
        form.value.confirmation_template = null;
    }
});

/**
 * An offer may not carry itself.
 *
 * Filtered here as well as refused on the server: the server call is what makes
 * it true, this is what stops somebody picking an option that was never going
 * to save.
 */
const availableBumps = computed(() => props.bumpOptions.filter((o) => o.value !== form.value.handle));

/**
 * Was ein Buendel ausser dem Leitprodukt noch enthalten darf.
 *
 * Das Leitprodukt selbst faellt heraus: es steht schon in `product`, und ein
 * zweites Mal in der Liste hiesse, es zweimal zu verkaufen. Der Server weist
 * es ebenfalls ab; das hier verhindert, dass man es ueberhaupt anklickt.
 */
const availableProducts = computed(() => props.products.filter((o) => o.value !== form.value.product));

/**
 * Wie `bumpsError`: Laravel meldet einen abgelehnten Eintrag als `products.0`,
 * nicht als `products`, und ein Feld, das nur auf `products` schaut, zeigt
 * nichts an, waehrend das Speichern gescheitert aussieht wie ein Erfolg.
 */
const productsError = computed(() => {
    const key = Object.keys(errors.value).find((k) => k === 'products' || k.startsWith('products.'));

    return key ? errors.value[key] : null;
});

/**
 * Ein Buendel ohne eigenen Preis kostet die Summe seiner Teile. Hier steht nur
 * der Hinweis darauf — gerechnet wird auf dem Server, weil eine Zahl, die der
 * Browser ausrechnet, nie die ist, die abgebucht wird.
 */
const isBundle = computed(() => (form.value.products?.length ?? 0) > 0);

/**
 * Laravel reports a rejected entry as `bumps.0`, not `bumps`, so a field bound
 * to `errors.bumps` alone shows nothing and the save looks like it worked.
 */
const bumpsError = computed(() => {
    const key = Object.keys(errors.value).find((k) => k === 'bumps' || k.startsWith('bumps.'));

    return key ? errors.value[key] : null;
});

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
        onSuccess: () => { open.value = false; errors.value = {}; listing.value?.refresh(); },
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
            <Button variant="primary" :text="t.new" @click="create" />
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
                @click="create"
            />
        </EmptyStateMenu>

        <Listing
            v-else
            ref="listing"
            :url="listingUrl"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            preferences-prefix="statamic-offers.offers"
            push-query
        >
            <template #cell-name="{ row }">
                <button type="button" class="font-medium hover:text-primary" @click="edit(row)">{{ row.name }}</button>
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
                <span v-if="row.compare_at" class="block text-2xs text-gray-500 dark:text-gray-400 line-through tabular-nums">
                    {{ row.compare_at }} {{ row.currency }}
                </span>
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
                <DropdownItem icon="edit" :text="t.edit_action" @click="edit(row)" />
                <DropdownItem icon="trash" variant="destructive" :text="t.delete_action" @click="deleting = row" />
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
            :title="t.delete_title"
            :body-text="deletePrompt"
            :button-text="t.delete_action"
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

                <Field :label="t.field_name" :instructions="t.field_name_help" :error="errors.name" required>
                    <Input v-model="form.name" />
                </Field>

                <Field :label="t.field_handle" :instructions="t.field_handle_help" :error="errors.handle" required>
                    <Input v-model="form.handle" class="font-mono" @update:model-value="handleTouched = true" />
                </Field>

                <Field :label="t.field_product" :instructions="t.field_product_help" :error="errors.product" required>
                    <Select v-model="form.product" :options="products" />
                </Field>

                <!-- Was ausser dem Leitprodukt noch mitverkauft wird. Leer
                     heisst: ein Angebot ueber eine Sache, wie bisher. -->
                <Field
                    :label="t.field_products"
                    :instructions="isBundle ? t.field_products_bundle : t.field_products_help"
                    :error="productsError"
                >
                    <Combobox
                        v-model="form.products"
                        :options="availableProducts"
                        :placeholder="t.field_products_placeholder"
                        :disabled="availableProducts.length === 0"
                        multiple
                        searchable
                        clearable
                    />
                </Field>

                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="t.field_amount" :error="errors.amount_cent">
                            <Input v-model.number="form.amount_cent" type="number" min="1" :append="currency" />
                        </Field>

                        <Field :label="t.field_compare_at" :error="errors.compare_at_cent">
                            <Input v-model.number="form.compare_at_cent" type="number" min="1" :append="currency" />
                        </Field>
                    </div>

                    <!-- One explanation under both, because the two fields are
                         one decision: what this costs here, and what it says it
                         would otherwise cost. -->
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ t.field_amount_help }}
                        {{ t.field_compare_at_help }}
                    </p>
                </div>

                <Field :label="t.field_slot" :instructions="slotHelp" :error="errors.slot" required>
                    <Select v-model="form.slot" :options="slots" />
                </Field>

                <!-- Only offers placed at checkout can be carried, and never
                     this offer itself. The server refuses both again; this is
                     the half that stops somebody picking an impossible one. -->
                <Field
                    :label="t.field_bumps"
                    :instructions="availableBumps.length ? t.field_bumps_help : t.field_bumps_empty"
                    :error="bumpsError"
                >
                    <Combobox
                        v-model="form.bumps"
                        :options="availableBumps"
                        :placeholder="t.field_bumps_placeholder"
                        :disabled="availableBumps.length === 0"
                        multiple
                        searchable
                        clearable
                    />
                </Field>

                <Field :label="t.field_headline" :error="errors.headline">
                    <Input v-model="form.headline" />
                </Field>

                <Field :label="t.field_body" :error="errors.body">
                    <Textarea v-model="form.body" :rows="4" />
                </Field>

                <Field :label="t.field_button" :error="errors.button_label">
                    <Input v-model="form.button_label" />
                </Field>

                <Field :label="t.field_image" :instructions="t.field_image_help" :error="errors.image">
                    <Input v-model="form.image" />
                </Field>

                <!--
                    Was an diesem Angebot haengt. Auch — und gerade — wenn
                    nichts daran haengt: ein fehlendes Kaestchen liest sich wie
                    „noch nicht gebaut", ein leeres wie „nichts verdrahtet".
                    Der Unterschied ist der Grund, warum die fehlende Kaufmail
                    einen Monat lang niemandem auffiel.
                -->
                <Field v-if="editing" :label="t.field_usage" :instructions="t.field_usage_help">
                    <div class="text-sm">
                        <p v-if="!usage.funnels.length && !usage.automations.length" class="text-gray">
                            {{ t.usage_empty }}
                        </p>
                        <template v-else>
                            <p v-if="usage.funnels.length">
                                <strong>{{ t.usage_funnels }}</strong>
                                {{ usage.funnels.map((f) => f.title).join(', ') }}
                            </p>
                            <p v-if="usage.automations.length">
                                <strong>{{ t.usage_automations }}</strong>
                                <span v-for="(a, i) in usage.automations" :key="a.name">
                                    {{ i ? ', ' : '' }}{{ a.name }}<template v-if="!a.enabled"> ({{ t.usage_disabled }})</template>
                                </span>
                            </p>
                            <p v-else class="text-gray">{{ t.usage_no_automations }}</p>
                        </template>
                    </div>
                </Field>

                <Field
                    :label="t.field_confirmation"
                    :instructions="confirmationHelp || t.field_confirmation_help"
                    :error="errors.confirmation_mode"
                >
                    <Select v-model="form.confirmation_mode" :options="confirmationModes" />
                </Field>

                <Field
                    v-if="wantsTemplate"
                    :label="t.field_confirmation_template"
                    :instructions="hasTemplates ? t.field_confirmation_template_help : t.field_confirmation_template_missing"
                    :error="errors.confirmation_template"
                >
                    <Select
                        v-if="hasTemplates"
                        v-model="form.confirmation_template"
                        :options="confirmationTemplates"
                        :placeholder="t.field_confirmation_template_placeholder"
                        clearable
                    />
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

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-offers#readme"
        />
    </div>
</template>
