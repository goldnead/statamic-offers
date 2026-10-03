<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import {
    Header, Badge, DocsCallout, Button, Card, ConfirmationModal,
    Dropdown, DropdownMenu, DropdownItem,
    Field, Input, Textarea, Select, Combobox, Switch, Alert,
    Subheading, Checkbox, CheckboxGroup,
    Tabs, TabList, TabTrigger, TabContent,
} from '@statamic/cms/ui';

/**
 * Ein Angebot: Detailseite und Formular in einem.
 *
 * Wie beim Collection-Entry gibt es keine zweite Seite zum Bearbeiten und
 * keinen Stack: was hier steht, ist das Feld, gespeichert wird oben rechts,
 * Loeschen sitzt im Menue daneben. Dieselbe Seite legt auch an (`offer` ist
 * dann null); nach dem Speichern landet man auf der Detailseite des neuen
 * Angebots.
 *
 * Die vielen Felder sind in fuenf Tabs gegliedert, nicht in eine lange Spalte.
 */
const props = defineProps({
    offer: { type: Object, default: null },
    indexUrl: { type: String, required: true },
    storeUrl: { type: String, required: true },
    updateUrl: { type: String, default: null },
    deleteUrl: { type: String, default: null },
    products: { type: Array, default: () => [] },
    slots: { type: Array, default: () => [] },
    currency: { type: String, default: 'EUR' },
    bumpOptions: { type: Array, default: () => [] },
    confirmationModes: { type: Array, default: () => [] },
    confirmationTemplates: { type: Array, default: () => [] },
    checkoutFields: { type: Array, default: () => [] },
    withdrawalDefaults: { type: Object, default: () => ({}) },
    timezone: { type: String, default: 'UTC' },
    countries: { type: Array, default: () => [] },
    linkBase: { type: String, default: '' },
    t: { type: Object, required: true },
});

const blank = () => ({
    name: '', handle: '', product: props.products[0]?.value ?? '',
    amount_cent: null, compare_at_cent: null, discount_percent: null, currency: null,
    // Leer heisst einmalig. Siehe das Feld unter dem Preis.
    interval: null, times: null, trial_days: null, trial_amount_cent: null,
    // Leer heisst „ein Preis". Siehe die Liste unter dem Rhythmus.
    pricing_options: [],
    headline: '', body: '', button_label: '', image: '',
    slot: 'standalone', bumps: [], active: true, products: [],
    // The standard mail, so that an offer created and saved without ever
    // opening this field still reaches its buyer.
    confirmation_mode: 'default', confirmation_template: null,
    // Scarcity and access: empty is "unlimited, now, for good".
    quantity_limit: null, available_from: null, available_until: null,
    access_starts_at: null, access_days: null,
    // Checkout fields: nothing ticked means the funnel step decides.
    checkout_fields: [],
    // Withdrawal: every empty field inherits the config's default, which the
    // form shows as a placeholder so that "empty" is visibly "this".
    withdrawal_days: null, withdrawal_text: '', withdrawal_waiver_text: '',
    withdrawal_checkbox_required: true, withdrawal_b2b_text: '', withdrawal_pdf: false,
    // Zahl, was du willst. `fixed` ist alles, was es vorher gab.
    price_mode: 'fixed', pwyw_min_cent: null, pwyw_suggested_cent: null, pwyw_max_cent: null, pwyw_thanks: [],
    // Einrichtungsgebuehr, nur mit Rhythmus wirksam.
    setup_fee_cent: null, setup_fee_label: '',
    // Weltweit, bis jemand eine Regel setzt.
    country_mode: 'all', countries: [],
    // Kurzlink: ohne Adresse keiner.
    link_slug: '', link_target: '', link_fallback: '', link_switch_at: null, link_switch_on_sold_out: true,
    // Plaetze fuer Gruppen: leer ist ein gewoehnlicher Kauf.
    seats: null,
});

const priceModes = computed(() => [
    { value: 'fixed', label: props.t.price_mode_fixed },
    { value: 'pwyw', label: props.t.price_mode_pwyw },
]);

const countryModes = computed(() => [
    { value: 'all', label: props.t.country_mode_all },
    { value: 'only', label: props.t.country_mode_only },
    { value: 'except', label: props.t.country_mode_except },
]);

const isPwyw = computed(() => form.value.price_mode === 'pwyw');

/**
 * Traegt das Angebot irgendwo einen Rhythmus? Nur dann kann eine
 * Einrichtungsgebuehr anfallen. Der Server prueft dasselbe; hier ist es, damit
 * das Feld nicht aussieht, als wirke es.
 */
const hasPlan = computed(() => Boolean(form.value.interval)
    || (form.value.pricing_options ?? []).some((o) => Boolean(o.interval)));

function addThanks() {
    form.value.pwyw_thanks = [...(form.value.pwyw_thanks ?? []), { from_cent: null, text: '' }];
}

function removeThanks(index) {
    form.value.pwyw_thanks = form.value.pwyw_thanks.filter((_, i) => i !== index);
}

function thanksError(index, field) {
    return errors.value[`pwyw_thanks.${index}.${field}`] ?? null;
}

const countriesError = computed(() => {
    const key = Object.keys(errors.value).find((k) => k === 'countries' || k.startsWith('countries.'));

    return key ? errors.value[key] : null;
});

/**
 * Der Kurzlink, wie er gedruckt wird. Waehrend jemand tippt, aus der Basis und
 * dem Slug; nach dem Speichern der vom Server gebaute (`editing.short_link`).
 */
const linkPreview = computed(() => (form.value.link_slug ? props.linkBase + form.value.link_slug : ''));

/**
 * Ein Centbetrag, wie ihn ein Mensch liest, unter dem Feld. Die Felder bleiben
 * in Cent wie jedes Geldfeld der Familie; die Zeile darunter sagt, was die
 * Zahl bedeutet, bevor jemand 49 statt 4900 speichert.
 */
function money(cent) {
    if (cent === null || cent === '' || Number.isNaN(Number(cent))) return '';

    try {
        const text = new Intl.NumberFormat(props.t.locale || 'de', { style: 'currency', currency: props.currency })
            .format(Number(cent) / 100);

        return props.t.money_preview.replace(':amount', text);
    } catch (e) {
        return '';
    }
}

/**
 * Plaetze fuer Produkte, die keinen Zugang vergeben. Aus der Produktliste
 * abgelesen, damit die Warnung auch vor dem ersten Speichern steht.
 */
const seatsGrantNothing = computed(() => {
    if (!(Number(form.value.seats) >= 2)) return false;

    const handles = [form.value.product, ...(form.value.products ?? [])].filter(Boolean);

    return handles.length > 0 && handles.every((h) => !props.products.find((p) => p.value === h)?.grants);
});

/** Die verkauften Kontingente des gerade bearbeiteten Angebots. */
const seatPools = computed(() => editing.value?.seat_pools ?? []);

const takingBack = ref(null);

const takeBackPrompt = computed(() => (takingBack.value
    ? props.t.seats_revoke_confirm.replace(':email', takingBack.value.email)
    : ''));

/**
 * Zurueckholen. Ein nur eingeladener Platz geht ohne Rueckfrage, ein
 * angenommener nimmt jemandem einen Zugang, den er schon benutzt, und fragt.
 */
function takeBack(row) {
    if (row.claimed) {
        takingBack.value = row;

        return;
    }

    postSeat(row.revoke_url);
}

function confirmTakeBack() {
    const row = takingBack.value;
    takingBack.value = null;

    if (row) postSeat(row.revoke_url);
}

function postSeat(url) {
    router.post(url, {}, {
        preserveScroll: true,
        onSuccess: () => { errors.value = {}; },
    });
}

const savedLink = computed(() => {
    const link = editing.value?.short_link ?? null;

    // Nur solange das Formular denselben Slug zeigt: ein umgetippter Slug
    // hat noch keinen QR-Code, und der alte waere eine falsche Vorschau.
    return link && editing.value?.edit_values?.link_slug === form.value.link_slug ? link : null;
});

const timezoneNote = computed(() => props.t.timezone_note.replace(':timezone', props.timezone));

/**
 * Laravel reports a rejected key as `checkout_fields.0`; see `bumpsError`.
 */
const checkoutFieldsError = computed(() => {
    const key = Object.keys(errors.value).find((k) => k === 'checkout_fields' || k.startsWith('checkout_fields.'));

    return key ? errors.value[key] : null;
});

/** An own price and a percentage are one decision, so filling one clears the other. */
function amountChanged(value) {
    form.value.amount_cent = value;
    if (value !== null && value !== '') form.value.discount_percent = null;
}

function percentChanged(value) {
    form.value.discount_percent = value;
    if (value !== null && value !== '') form.value.amount_cent = null;
}

const saving = ref(false);
const errors = ref({});
/** Der gespeicherte Stand, `null` beim Anlegen. */
const editing = computed(() => props.offer);
const form = ref({ ...blank(), ...(props.offer?.edit_values ?? {}) });

const title = computed(() => (editing.value ? (form.value.name || editing.value.name) : props.t.new));

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

/**
 * Wie `bumpsError`, nur tiefer verschachtelt: der Server meldet eine abgelehnte
 * Zeile als `pricing_options.1.key`. Die Fehler stehen je Zeile, damit
 * jemand mit vier Optionen sieht, welche gemeint ist.
 */
function optionError(index, field) {
    return errors.value[`pricing_options.${index}.${field}`] ?? null;
}

const pricingOptionsError = computed(() => errors.value.pricing_options ?? null);

/**
 * Der Typ einer Zeile, aus ihren Feldern abgelesen.
 *
 * Genau wie auf dem Server ({@see Offer::pricingOptions()}): kein Rhythmus ist
 * einmalig, ein Rhythmus mit Anzahl sind Raten, ohne Anzahl ein Abo. Ein
 * eigenes Auswahlfeld daneben waere eine zweite Wahrheit ueber dieselbe Sache,
 * und die beiden gehen auseinander, sobald jemand nur eines der Felder aendert.
 */
function optionType(option) {
    if (!option.interval) return props.t.pricing_type_once;

    return option.times ? props.t.pricing_type_instalments : props.t.pricing_type_subscription;
}

function addPricingOption() {
    form.value.pricing_options = [
        ...(form.value.pricing_options ?? []),
        { key: '', label: '', amount_cent: null, interval: null, times: null, trial_days: null, trial_amount_cent: null },
    ];
}

function removePricingOption(index) {
    form.value.pricing_options = form.value.pricing_options.filter((_, i) => i !== index);
}

/**
 * The handle follows the name until somebody types a handle of their own.
 *
 * Watched rather than hung off `@blur`: a suggestion that only appears when a
 * field happens to lose focus is a suggestion most people never see. Once the
 * field has been touched it is left alone — a handle that keeps rewriting
 * itself after a choice was made is the kind of helpfulness that loses work.
 */
const handleTouched = ref(Boolean(props.offer));

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
    const url = editing.value ? props.updateUrl : props.storeUrl;
    const method = editing.value ? 'patch' : 'post';

    router[method](url, form.value, {
        preserveScroll: true,
        onError: (e) => { errors.value = e || {}; },
        onSuccess: () => { errors.value = {}; },
        onFinish: () => { saving.value = false; },
    });
}

/**
 * Deleting asks first.
 *
 * An offer carries its counters, and those are the only record of whether it
 * ever worked. Core asks before every destructive action.
 */
const confirmingDelete = ref(false);

const deletePrompt = computed(() => props.t.delete_body.replace(':name', editing.value?.name ?? ''));

function confirmRemove() {
    confirmingDelete.value = false;
    router.delete(props.deleteUrl);
}

/**
 * Welche Felder in welchem Tab sitzen. Ein abgelehnter Schluessel faerbt seinen
 * Tab, und der erste Tab mit einem Fehler oeffnet sich nach dem Speichern.
 */
const TAB_FIELDS = {
    basics: ['name', 'handle', 'product', 'products', 'slot', 'headline', 'body', 'button_label', 'image', 'offer'],
    price: [
        'price_mode', 'pwyw_min_cent', 'pwyw_suggested_cent', 'pwyw_max_cent', 'pwyw_thanks', 'amount_cent',
        'compare_at_cent', 'discount_percent', 'currency', 'interval', 'times', 'trial_days', 'trial_amount_cent',
        'setup_fee_cent', 'setup_fee_label', 'pricing_options',
    ],
    checkout: [
        'bumps', 'checkout_fields', 'confirmation_mode', 'confirmation_template',
        'access_starts_at', 'access_days', 'seats',
    ],
    legal: [
        'withdrawal_days', 'withdrawal_text', 'withdrawal_waiver_text', 'withdrawal_checkbox_required',
        'withdrawal_b2b_text', 'withdrawal_pdf',
    ],
    visibility: [
        'active', 'quantity_limit', 'available_from', 'available_until', 'country_mode', 'countries',
        'link_slug', 'link_target', 'link_fallback', 'link_switch_at', 'link_switch_on_sold_out',
    ],
};

const activeTab = ref('basics');

const tabsWithErrors = computed(() => {
    const out = new Set();

    for (const key of Object.keys(errors.value)) {
        const root = key.split('.')[0];
        const tab = Object.keys(TAB_FIELDS).find((name) => TAB_FIELDS[name].includes(root));
        if (tab) out.add(tab);
    }

    return out;
});

watch(errors, () => {
    const first = Object.keys(TAB_FIELDS).find((name) => tabsWithErrors.value.has(name));
    if (first && !tabsWithErrors.value.has(activeTab.value)) activeTab.value = first;
});

</script>

<template>
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <Head :title="[title, t.title]" />

        <!-- Kernreihenfolge: erst das Menue, die Hauptaktion zuletzt. Loeschen
             ist ein `DropdownItem variant="destructive"` im Menue neben
             Speichern; `Button variant="danger"` gehoert nur in den Dialog. -->
        <Header :title="title" icon="money-cashier-price-tag">
            <Dropdown v-if="deleteUrl">
                <DropdownMenu>
                    <DropdownItem
                        icon="trash"
                        variant="destructive"
                        :text="t.delete_action"
                        @click="confirmingDelete = true"
                    />
                </DropdownMenu>
            </Dropdown>
            <Button variant="primary" :text="t.save" :disabled="saving" @click="save" />
        </Header>

        <Alert v-if="errors.offer" variant="error" :text="errors.offer" class="mb-4" />

        <Tabs v-model="activeTab">
            <TabList class="overflow-x-auto [&_button]:whitespace-nowrap">
                <TabTrigger name="basics">
                    {{ t.tab_basics }}
                    <Badge v-if="tabsWithErrors.has('basics')" color="red" pill class="ms-1.5" text="!" :aria-label="t.tab_has_errors" />
                </TabTrigger>
                <TabTrigger name="price">
                    {{ t.tab_price }}
                    <Badge v-if="tabsWithErrors.has('price')" color="red" pill class="ms-1.5" text="!" :aria-label="t.tab_has_errors" />
                </TabTrigger>
                <TabTrigger name="checkout">
                    {{ t.tab_checkout }}
                    <Badge v-if="tabsWithErrors.has('checkout')" color="red" pill class="ms-1.5" text="!" :aria-label="t.tab_has_errors" />
                </TabTrigger>
                <TabTrigger name="legal">
                    {{ t.tab_legal }}
                    <Badge v-if="tabsWithErrors.has('legal')" color="red" pill class="ms-1.5" text="!" :aria-label="t.tab_has_errors" />
                </TabTrigger>
                <TabTrigger name="visibility">
                    {{ t.tab_visibility }}
                    <Badge v-if="tabsWithErrors.has('visibility')" color="red" pill class="ms-1.5" text="!" :aria-label="t.tab_has_errors" />
                </TabTrigger>
            </TabList>

            <TabContent name="basics">
                <div class="mt-4 space-y-4">
                <Card class="space-y-5">
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

                <Field :label="t.field_slot" :instructions="t.field_slot_help" :error="errors.slot" required>
                    <Select v-model="form.slot" :options="slots" />
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
                </Card>

                <Card class="space-y-5">
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
                </Card>
                </div>
            </TabContent>

            <TabContent name="price">
                <div class="mt-4 space-y-4">
                <Card class="space-y-5">

                <Field :label="t.field_price_mode" :instructions="t.field_price_mode_help" :error="errors.price_mode">
                    <Select v-model="form.price_mode" :options="priceModes" />
                </Field>

                <!-- Zahl, was du willst. Die Grenzen sind das Einzige, was das
                     Angebot festlegt; den Betrag waehlt die Kaeuferin. -->
                <div v-if="isPwyw">
                    <!-- Zwei Spalten, nicht drei: im schmalen Stapel schneidet
                         eine dritte Spalte die Betraege ab. -->
                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="t.field_pwyw_min" :error="errors.pwyw_min_cent">
                            <Input
                                :model-value="form.pwyw_min_cent"
                                type="number"
                                min="0"
                                :append="t.unit_cent"
                                @update:model-value="form.pwyw_min_cent = $event === '' ? null : Number($event)"
                            />
                            <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(form.pwyw_min_cent) }}</p>
                        </Field>

                        <Field :label="t.field_pwyw_suggested" :error="errors.pwyw_suggested_cent">
                            <Input
                                :model-value="form.pwyw_suggested_cent"
                                type="number"
                                min="0"
                                :append="t.unit_cent"
                                @update:model-value="form.pwyw_suggested_cent = $event === '' ? null : Number($event)"
                            />
                            <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(form.pwyw_suggested_cent) }}</p>
                        </Field>

                        <Field :label="t.field_pwyw_max" :error="errors.pwyw_max_cent">
                            <Input
                                :model-value="form.pwyw_max_cent"
                                type="number"
                                min="1"
                                :append="t.unit_cent"
                                @update:model-value="form.pwyw_max_cent = $event === '' ? null : Number($event)"
                            />
                            <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(form.pwyw_max_cent) }}</p>
                        </Field>
                    </div>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ t.field_pwyw_help }}</p>
                </div>

                <div v-if="isPwyw">
                    <Subheading :text="t.field_pwyw_thanks" />

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ t.field_pwyw_thanks_help }}</p>

                    <div
                        v-for="(tier, index) in form.pwyw_thanks"
                        :key="index"
                        class="mt-3 rounded-md border border-gray-300 p-3 dark:border-gray-700"
                    >
                        <div class="flex items-start gap-4">
                            <Field class="w-40 shrink-0" :label="t.field_pwyw_thanks_from" :error="thanksError(index, 'from_cent')">
                                <Input
                                    :model-value="tier.from_cent"
                                    type="number"
                                    min="0"
                                    :append="t.unit_cent"
                                    @update:model-value="tier.from_cent = $event === '' ? null : Number($event)"
                                />
                                <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(tier.from_cent) }}</p>
                            </Field>

                            <Button
                                class="ms-auto"
                                variant="ghost"
                                size="sm"
                                :text="t.pwyw_thanks_remove"
                                @click="removeThanks(index)"
                            />
                        </div>

                        <Field class="mt-3" :label="t.field_pwyw_thanks_text" :error="thanksError(index, 'text')">
                            <Textarea v-model="tier.text" :rows="2" />
                        </Field>
                    </div>

                    <Button class="mt-3" size="sm" :text="t.pwyw_thanks_add" @click="addThanks" />
                </div>

                <div v-if="!isPwyw">
                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="t.field_amount" :error="errors.amount_cent">
                            <Input
                                :model-value="form.amount_cent"
                                type="number"
                                min="1"
                                :append="t.unit_cent"
                                @update:model-value="amountChanged($event === '' ? null : Number($event))"
                            />
                            <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(form.amount_cent) }}</p>
                        </Field>

                        <Field :label="t.field_compare_at" :error="errors.compare_at_cent">
                            <Input v-model.number="form.compare_at_cent" type="number" min="1" :append="t.unit_cent" />
                            <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(form.compare_at_cent) }}</p>
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

                <!-- Der Zahlungsrhythmus. Steht direkt unter dem Preis, weil
                     er dessen Bedeutung aendert: mit Rhythmus ist der Betrag
                     oben die Hoehe EINER Rate, nicht der Gesamtpreis. Zwei
                     Felder, die man getrennt liest, waeren genau die Stelle,
                     an der jemand 1.500 statt 520 eintraegt.

                     Die drei rechten Felder sind ohne Rhythmus wirkungslos
                     und werden beim Speichern mit geleert. -->
                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="t.field_interval" :error="errors.interval">
                            <Input
                                v-model="form.interval"
                                class="font-mono"
                                :placeholder="t.field_interval_placeholder"
                            />
                        </Field>

                        <Field :label="t.field_times" :error="errors.times">
                            <Input
                                :model-value="form.times"
                                type="number"
                                min="1"
                                :placeholder="t.field_times_placeholder"
                                :disabled="!form.interval"
                                @update:model-value="form.times = $event === '' ? null : Number($event)"
                            />
                        </Field>
                    </div>

                    <div class="grid grid-cols-2 gap-4 mt-4">
                        <Field :label="t.field_trial_days" :error="errors.trial_days">
                            <Input
                                :model-value="form.trial_days"
                                type="number"
                                min="0"
                                :disabled="!form.interval"
                                @update:model-value="form.trial_days = $event === '' ? null : Number($event)"
                            />
                        </Field>

                        <Field :label="t.field_trial_amount" :error="errors.trial_amount_cent">
                            <Input
                                :model-value="form.trial_amount_cent"
                                type="number"
                                min="0"
                                :append="t.unit_cent"
                                :disabled="!form.interval"
                                @update:model-value="form.trial_amount_cent = $event === '' ? null : Number($event)"
                            />
                            <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(form.trial_amount_cent) }}</p>
                        </Field>
                    </div>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ t.field_plan_help }}
                    </p>
                </div>

                <!-- Die Einrichtungsgebuehr steht beim Rhythmus, weil sie nur
                     mit ihm anfaellt: einmal, mit der ersten Zahlung. -->
                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="t.field_setup_fee" :error="errors.setup_fee_cent">
                            <Input
                                :model-value="form.setup_fee_cent"
                                type="number"
                                min="1"
                                :append="t.unit_cent"
                                :disabled="!hasPlan && !form.setup_fee_cent"
                                @update:model-value="form.setup_fee_cent = $event === '' ? null : Number($event)"
                            />
                            <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(form.setup_fee_cent) }}</p>
                        </Field>

                        <Field :label="t.field_setup_fee_label" :error="errors.setup_fee_label">
                            <Input
                                v-model="form.setup_fee_label"
                                :placeholder="t.field_setup_fee_label_placeholder"
                                :disabled="!form.setup_fee_cent"
                            />
                        </Field>
                    </div>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ t.field_setup_fee_help }}</p>
                </div>

                <!-- Mehrere Zahlweisen an einem Angebot. Steht unter dem
                     Rhythmus, weil jede Zeile derselbe Feldsatz noch einmal
                     ist: Betrag, Rhythmus, Anzahl.

                     Der Typ wird nicht gewaehlt, sondern angezeigt. Er folgt
                     aus Rhythmus und Anzahl, und ein Auswahlfeld daneben
                     waere die zweite Wahrheit, die irgendwann von der ersten
                     abweicht. -->
                <div v-if="!isPwyw">
                    <Subheading :text="t.field_pricing_options" />

                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ t.field_pricing_options_help }}
                    </p>

                    <Alert v-if="pricingOptionsError" variant="error" class="mt-2" :text="pricingOptionsError" />

                    <div
                        v-for="(option, index) in form.pricing_options"
                        :key="index"
                        class="mt-3 rounded-md border border-gray-300 p-3 dark:border-gray-700"
                    >
                        <div class="flex items-center justify-between">
                            <Badge :text="optionType(option)" />
                            <Button
                                variant="ghost"
                                size="sm"
                                :text="t.pricing_option_remove"
                                @click="removePricingOption(index)"
                            />
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-4">
                            <Field :label="t.field_pricing_option_key" :error="optionError(index, 'key')">
                                <Input v-model="option.key" class="font-mono" placeholder="raten3" />
                            </Field>

                            <Field :label="t.field_pricing_option_label" :error="optionError(index, 'label')">
                                <Input v-model="option.label" :placeholder="t.field_pricing_option_label_placeholder" />
                            </Field>
                        </div>

                        <div class="mt-3 grid grid-cols-3 gap-4">
                            <Field :label="t.field_pricing_option_amount" :error="optionError(index, 'amount_cent')">
                                <Input
                                    :model-value="option.amount_cent"
                                    type="number"
                                    min="1"
                                    :append="t.unit_cent"
                                    @update:model-value="option.amount_cent = $event === '' ? null : Number($event)"
                                />
                                <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(option.amount_cent) }}</p>
                            </Field>

                            <Field :label="t.field_interval" :error="optionError(index, 'interval')">
                                <Input
                                    v-model="option.interval"
                                    class="font-mono"
                                    :placeholder="t.field_interval_placeholder"
                                />
                            </Field>

                            <Field :label="t.field_times" :error="optionError(index, 'times')">
                                <Input
                                    :model-value="option.times"
                                    type="number"
                                    min="1"
                                    :placeholder="t.field_times_placeholder"
                                    :disabled="!option.interval"
                                    @update:model-value="option.times = $event === '' ? null : Number($event)"
                                />
                            </Field>
                        </div>

                        <!-- Die Testphase je Option. Ohne Rhythmus wirkungslos
                             und beim Speichern mit geleert, dieselbe Regel wie
                             beim Angebot selbst. -->
                        <div class="mt-3 grid grid-cols-2 gap-4">
                            <Field :label="t.field_trial_days" :error="optionError(index, 'trial_days')">
                                <Input
                                    :model-value="option.trial_days"
                                    type="number"
                                    min="0"
                                    :disabled="!option.interval"
                                    @update:model-value="option.trial_days = $event === '' ? null : Number($event)"
                                />
                            </Field>

                            <Field :label="t.field_trial_amount" :error="optionError(index, 'trial_amount_cent')">
                                <Input
                                    :model-value="option.trial_amount_cent"
                                    type="number"
                                    min="0"
                                    :append="t.unit_cent"
                                    :disabled="!option.interval"
                                    @update:model-value="option.trial_amount_cent = $event === '' ? null : Number($event)"
                                />
                                <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ money(option.trial_amount_cent) }}</p>
                            </Field>
                        </div>
                    </div>

                    <Button class="mt-3" size="sm" :text="t.pricing_option_add" @click="addPricingOption" />
                </div>

                <!-- The other way to a price: a share off the catalogue. One
                     or the other, and the server refuses both. -->
                <Field v-if="!isPwyw" :label="t.field_discount_percent" :instructions="t.field_discount_percent_help" :error="errors.discount_percent">
                    <Input
                        :model-value="form.discount_percent"
                        type="number"
                        min="1"
                        max="99"
                        append="%"
                        @update:model-value="percentChanged($event === '' ? null : Number($event))"
                    />
                </Field>
                </Card>
                </div>
            </TabContent>

            <TabContent name="checkout">
                <div class="mt-4 space-y-4">
                <Card class="space-y-5">
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
                </Card>

                <Card class="space-y-5">
                <!-- Checkout fields: picks from the library in the config.
                     The library says what a field is; the offer only says
                     "ask for it". -->
                <Subheading :text="t.section_checkout" />

                <Field
                    :label="t.field_checkout_fields"
                    :instructions="checkoutFields.length ? t.field_checkout_fields_help : t.field_checkout_fields_empty"
                    :error="checkoutFieldsError"
                >
                    <CheckboxGroup v-if="checkoutFields.length" v-model="form.checkout_fields">
                        <Checkbox
                            v-for="option in checkoutFields"
                            :key="option.value"
                            :value="option.value"
                            :label="option.label"
                        />
                    </CheckboxGroup>
                </Field>
                </Card>

                <Card class="space-y-5">
                <Subheading :text="t.section_mail" />

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
                </Card>

                <Card class="space-y-5">
                <!-- Access. Handed to the payment; the entitlements addon
                     turns it into starts_at / expires_at. -->
                <Subheading :text="t.section_access" />

                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="t.field_access_starts_at" :error="errors.access_starts_at">
                            <Input v-model="form.access_starts_at" type="date" />
                        </Field>

                        <Field :label="t.field_access_days" :error="errors.access_days">
                            <Input v-model.number="form.access_days" type="number" min="1" />
                        </Field>
                    </div>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ t.field_access_help }}</p>
                </div>

                <!-- Plaetze fuer Gruppen. Beim Zugang, weil es um Zugaenge
                     geht: wer sie bekommt, entscheidet dann die Kaeuferin. -->
                <Subheading :text="t.section_seats" />

                <Field :label="t.field_seats" :instructions="t.field_seats_help" :error="errors.seats">
                    <Input
                        :model-value="form.seats"
                        type="number"
                        min="2"
                        max="1000"
                        class="w-40"
                        @update:model-value="form.seats = $event === '' ? null : Number($event)"
                    />
                </Field>

                <Alert v-if="seatsGrantNothing" variant="warning" :text="t.field_seats_grant_nothing" />

                <!-- Was schon verkauft ist. Je Kauf die Kaeuferin, wie viele
                     Plaetze vergeben sind, und die Plaetze selbst. -->
                <div v-if="editing && (form.seats || seatPools.length)">
                    <Subheading :text="t.section_seat_pools" />

                    <p v-if="!seatPools.length" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ t.seat_pools_empty }}</p>

                    <div
                        v-for="pool in seatPools"
                        :key="pool.id"
                        class="mt-3 rounded-md border border-gray-300 p-3 dark:border-gray-700"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <!-- Umbrechen statt abschneiden: im schmalen Stapel
                                 blieb von einer Adresse sonst nur der Anfang. -->
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium wrap-anywhere">{{ pool.owner_name || pool.owner_email }}</p>
                                <p v-if="pool.owner_name" class="text-xs text-gray-500 wrap-anywhere dark:text-gray-400">{{ pool.owner_email }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ pool.created_at }}</p>
                            </div>
                            <Badge v-if="pool.closed" color="red" pill :text="t.seat_pools_closed" />
                            <Badge v-else pill :text="t.seat_pools_taken.replace(':taken', pool.taken).replace(':seats', pool.seats)" />
                        </div>

                        <!-- Geschlossen: wann, warum, welche Zahlung. -->
                        <p v-if="pool.closed" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            {{ t.seat_pools_closed_at.replace(':date', pool.closed_at) }}
                            · {{ pool.closed_reason }}
                            · {{ t.seat_pools_payment.replace(':id', pool.payment_id) }}
                        </p>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <Button size="sm" icon="mail" :text="t.seat_pools_resend" :disabled="pool.closed" @click="postSeat(pool.resend_url)" />
                            <Button size="sm" icon="external-link" :href="pool.manage_url" target="_blank" :text="t.seat_pools_open" />
                        </div>

                        <ul v-if="pool.rows.length" class="mt-3 divide-y divide-gray-200 border-t border-gray-200 text-sm dark:divide-gray-700 dark:border-gray-700">
                            <li v-for="row in pool.rows" :key="row.id" class="flex items-center gap-2 py-2">
                                <span class="min-w-0 flex-1">
                                    <span v-if="row.name" class="block">{{ row.name }}</span>
                                    <span class="block text-xs text-gray-500 wrap-anywhere dark:text-gray-400">{{ row.email }}</span>
                                </span>
                                <Badge pill :color="row.claimed ? 'green' : 'default'" :text="row.claimed ? t.seat_pools_claimed : t.seat_pools_invited" />
                                <Button size="xs" variant="ghost" :text="t.seat_pools_revoke" @click="takeBack(row)" />
                            </li>
                        </ul>
                    </div>
                </div>
                </Card>
                </div>
            </TabContent>

            <TabContent name="legal">
                <div class="mt-4 space-y-4">
                <Card class="space-y-5">
                <!-- Withdrawal. Every empty field inherits the config, shown
                     as the placeholder. What a buyer agrees to is frozen on
                     the payment together with the version below, so a text
                     edited later never rewrites an earlier consent. -->
                <Subheading :text="t.section_withdrawal" />

                <p class="text-xs text-gray-500 dark:text-gray-400">{{ t.withdrawal_help }}</p>

                <Field :label="t.field_withdrawal_days" :error="errors.withdrawal_days">
                    <Input
                        v-model.number="form.withdrawal_days"
                        type="number"
                        min="0"
                        max="365"
                        :placeholder="String(withdrawalDefaults.days ?? '')"
                    />
                </Field>

                <Field :label="t.field_withdrawal_text" :error="errors.withdrawal_text">
                    <Textarea v-model="form.withdrawal_text" :rows="6" :placeholder="withdrawalDefaults.text" />
                </Field>

                <Field :label="t.field_withdrawal_waiver_text" :error="errors.withdrawal_waiver_text">
                    <Textarea v-model="form.withdrawal_waiver_text" :rows="3" :placeholder="withdrawalDefaults.waiver_text" />
                </Field>

                <Field :label="t.field_withdrawal_checkbox_required" :error="errors.withdrawal_checkbox_required">
                    <Switch v-model="form.withdrawal_checkbox_required" />
                </Field>

                <Field :label="t.field_withdrawal_b2b_text" :error="errors.withdrawal_b2b_text">
                    <Textarea v-model="form.withdrawal_b2b_text" :rows="3" :placeholder="withdrawalDefaults.b2b_text || ''" />
                </Field>

                <Field :label="t.field_withdrawal_pdf" :instructions="t.field_withdrawal_pdf_help" :error="errors.withdrawal_pdf">
                    <Switch v-model="form.withdrawal_pdf" />
                </Field>

                <Field v-if="editing" :label="t.withdrawal_version" :instructions="t.withdrawal_version_help">
                    <span class="font-mono text-xs">{{ editing.withdrawal_version }}</span>
                </Field>
                </Card>
                </div>
            </TabContent>

            <TabContent name="visibility">
                <div class="mt-4 space-y-4">
                <Card class="space-y-5">
                <Field :label="t.field_active">
                    <Switch v-model="form.active" />
                </Field>

                <!-- Scarcity. On the offer and not on the funnel step: the
                     same offer through two funnels is one limit, not two. -->
                <Subheading :text="t.section_availability" />

                <Field :label="t.field_quantity_limit" :instructions="t.field_quantity_limit_help" :error="errors.quantity_limit">
                    <Input v-model.number="form.quantity_limit" type="number" min="1" :placeholder="t.availability_unlimited" />
                </Field>

                <!-- Plain date-time inputs; see the coupons screen for why not
                     core's DatePicker. -->
                <div>
                    <div class="grid grid-cols-2 gap-4">
                        <Field :label="t.field_available_from" :error="errors.available_from">
                            <Input v-model="form.available_from" type="datetime-local" />
                        </Field>

                        <Field :label="t.field_available_until" :error="errors.available_until">
                            <Input v-model="form.available_until" type="datetime-local" />
                        </Field>
                    </div>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ t.field_available_help }} {{ timezoneNote }}
                    </p>
                </div>

                <!-- Wo verkauft wird. Durchgesetzt in der Kasse, gegen das
                     Land der Kaeuferin; hier steht nur die Regel. -->
                <Field :label="t.field_country_mode" :error="errors.country_mode">
                    <Select v-model="form.country_mode" :options="countryModes" />
                </Field>

                <Field
                    v-if="form.country_mode !== 'all'"
                    :label="t.field_countries"
                    :instructions="t.field_countries_help"
                    :error="countriesError"
                >
                    <Combobox
                        v-model="form.countries"
                        :options="countries"
                        :placeholder="t.field_countries_placeholder"
                        multiple
                        searchable
                        clearable
                    />
                </Field>
                </Card>

                <Card class="space-y-5">
                <!-- Der Kurzlink. Steht bei der Verfuegbarkeit, weil er an ihr
                     umschaltet: Stichtag und Kontingent sind dieselben. -->
                <Subheading :text="t.section_link" />

                <Field :label="t.field_link_slug" :instructions="t.field_link_slug_help" :error="errors.link_slug">
                    <Input v-model="form.link_slug" class="font-mono" placeholder="workshop-herbst" />
                </Field>

                <!-- Die ganze Adresse unter dem Feld und nicht als Praefix im
                     Feld: im schmalen Stapel liess das Praefix vom Slug kaum
                     etwas uebrig. -->
                <p v-if="form.link_slug && !savedLink" class="-mt-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ linkPreview }}</p>

                <template v-if="form.link_slug">
                    <Field :label="t.field_link_target" :instructions="t.field_link_target_help" :error="errors.link_target" required>
                        <Input v-model="form.link_target" class="font-mono" placeholder="/workshop" />
                    </Field>

                    <Field :label="t.field_link_fallback" :instructions="t.field_link_fallback_help" :error="errors.link_fallback">
                        <Input v-model="form.link_fallback" class="font-mono" placeholder="/warteliste" />
                    </Field>

                    <Field :label="t.field_link_switch_at" :instructions="`${t.field_link_switch_at_help} ${timezoneNote}`" :error="errors.link_switch_at">
                        <Input v-model="form.link_switch_at" type="datetime-local" :disabled="!form.link_fallback" />
                    </Field>

                    <Field :label="t.field_link_switch_on_sold_out" :error="errors.link_switch_on_sold_out">
                        <Switch v-model="form.link_switch_on_sold_out" :disabled="!form.link_fallback" />
                    </Field>

                    <!-- Link, QR-Code und Aufrufe gibt es erst gespeichert: der
                         QR-Code kodiert, was der Server gleich ausliefert, und
                         nicht, was gerade im Feld steht. -->
                    <div v-if="savedLink" class="flex gap-4 rounded-md border border-gray-300 p-3 dark:border-gray-700">
                        <img
                            :src="`${savedLink.qr_svg}?inline=1`"
                            :alt="t.link_qr"
                            class="size-28 shrink-0 rounded-sm"
                        >
                        <div class="min-w-0 flex-1 space-y-2 text-sm">
                            <Input :model-value="savedLink.url" class="font-mono" read-only copyable />
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ savedLink.destination === 'fallback' ? t.link_now_fallback : t.link_now_target }}
                                {{ t.link_hits }}:
                                <span class="tabular-nums">{{ t.link_hits_target.replace(':count', savedLink.hits_target) }}</span>
                                ·
                                <span class="tabular-nums">{{ t.link_hits_fallback.replace(':count', savedLink.hits_fallback) }}</span>
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <!-- `target`, damit der Knopf ein echter Link ist:
                                     ohne ihn wird er ein Inertia-Link, und der
                                     holt die Datei per XHR statt sie zu speichern. -->
                                <Button size="sm" icon="download" :href="savedLink.qr_svg" target="_blank" :text="t.link_download_svg" />
                                <Button size="sm" icon="download" :href="savedLink.qr_png" target="_blank" :text="t.link_download_png" />
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-xs text-gray-500 dark:text-gray-400">{{ t.link_save_first }}</p>
                </template>
                </Card>
                </div>
            </TabContent>
        </Tabs>

        <ConfirmationModal
            :open="confirmingDelete"
            :title="t.delete_title"
            :body-text="deletePrompt"
            :button-text="t.delete_action"
            danger
            @update:open="confirmingDelete = $event"
            @confirm="confirmRemove"
        />

        <!-- Rueckfrage vor dem Zurueckholen eines angenommenen Platzes. -->
        <ConfirmationModal
            :open="takingBack !== null"
            :title="t.seat_pools_revoke_title"
            :body-text="takeBackPrompt"
            :button-text="t.seat_pools_revoke"
            danger
            @update:open="takingBack = $event ? takingBack : null"
            @confirm="confirmTakeBack"
        />

        <DocsCallout
            :topic="t.title"
            url="https://github.com/goldnead/statamic-offers#readme"
        />
    </div>
</template>
