<script setup>
/**
 * Datum und Uhrzeit, so wie das Control Panel sie zeigt.
 *
 * Das Datum ist Statamics eigener `DatePicker`. Die Uhrzeit steht daneben in
 * einem `Input type="time"` und nicht im Picker, und das aus einem Grund, der
 * sich messen laesst: der Picker erkennt nur DateValue-Objekte aus der Kopie
 * von `@internationalized/date`, die im Kern gebuendelt ist (`instanceof`). Ein
 * Wert aus einer zweiten Kopie zeigt nur das Datum und verliert die Uhrzeit
 * auf dem Schirm, waehrend sie im Formular weiterlebt. Ein String wirft schon
 * beim Aufbau. Also gibt der Picker das Datum, das Feld daneben die Uhrzeit,
 * und das Formular behaelt, was der Server liefert: `Y-m-d` oder `Y-m-dTH:i`.
 */
import { computed } from 'vue';
import { DatePicker, Input } from '@statamic/cms/ui';
import { dateValue, fromDateValue } from '../support/datetime';

const props = defineProps({
    modelValue: { type: String, default: null },
    withTime: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const time = computed(() => (props.modelValue ?? '').split('T')[1] ?? '');

function setDate(value) {
    const day = fromDateValue(value);

    if (!day) {
        emit('update:modelValue', null);

        return;
    }

    emit('update:modelValue', props.withTime ? `${day}T${time.value || '00:00'}` : day);
}

function setTime(value) {
    const day = (props.modelValue ?? '').split('T')[0];

    // Ohne Datum gibt es keine Uhrzeit, die sich speichern liesse.
    if (!day) return;

    emit('update:modelValue', `${day}T${value || '00:00'}`);
}
</script>

<template>
    <!-- Untereinander: die Seitenspalte ist 20rem breit, und nebeneinander
         bleibt vom Datum nur die Haelfte. -->
    <div :class="withTime ? 'space-y-2' : ''">
        <DatePicker
            :model-value="dateValue(modelValue)"
            clearable
            :disabled="disabled"
            @update:model-value="setDate"
        />
        <Input
            v-if="withTime"
            type="time"
            :model-value="time"
            :disabled="disabled || !modelValue"
            @update:model-value="setTime"
        />
    </div>
</template>
