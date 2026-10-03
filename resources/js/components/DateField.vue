<script setup>
/**
 * Eines der Datumsfelder des Angebots, als Feld des Kerns.
 *
 * Das ist Statamics eigener `date`-Feldtyp, und zwar mit allem, was er
 * mitbringt: Feldbild, Datumswahl und eine Uhrzeit, die unabhaengig vom Browser
 * als HH:MM im 24-Stunden-Format eingegeben wird. Er braucht einen
 * `PublishContainer` ueber sich, den die Seite stellt; Blueprint, Werte und
 * Metadaten kommen vom Server (`OffersController::dateFields()`).
 *
 * Eigener Datumsbaustein war die Alternative und ist keine: der Picker des
 * Kerns erkennt nur Objekte aus der Kopie von `@internationalized/date`, die im
 * Kern steckt. Eine zweite Kopie im Bundle kostet 17 %, und der Wert daraus
 * zeigt die Uhrzeit nicht an.
 */
import { computed } from 'vue';
import { PublishFields, PublishFieldsProvider } from '@statamic/cms/ui';

const props = defineProps({
    fields: { type: Array, required: true },
    handle: { type: String, required: true },
});

const field = computed(() => props.fields.filter((f) => f.handle === props.handle));
</script>

<template>
    <PublishFieldsProvider :fields="field">
        <PublishFields />
    </PublishFieldsProvider>
</template>
