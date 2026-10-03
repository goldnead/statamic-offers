/**
 * Zwischen dem, was der Server speichert, und dem, was Statamics DatePicker
 * haelt.
 *
 * Das Modell des DatePicker ist ein `@internationalized/date` DateValue, nie ein
 * String: ein String wirft schon beim Aufbau (`.copy()`), ein DateValue als
 * Nutzlast verschickt ein Objekt statt eines Datums. Die Felder des Formulars
 * bleiben deshalb Strings, wie der Server sie liest und liefert (`Y-m-d` und
 * `Y-m-dTH:i` in der Anzeige-Zeitzone), und uebersetzt wird an dieser Naht.
 *
 * Es geht nur um den Tag. Die Uhrzeit haelt `DateTimeField.vue` daneben, siehe
 * dort, warum.
 */
import { CalendarDate } from '@internationalized/date';

const pad = (n) => String(n).padStart(2, '0');

/**
 * @param {string|null} value `Y-m-d`, auch mit Uhrzeit dahinter
 * @returns {CalendarDate|null}
 */
export function dateValue(value) {
    const m = typeof value === 'string' ? value.trim().match(/^(\d{4})-(\d{2})-(\d{2})/) : null;

    return m ? new CalendarDate(+m[1], +m[2], +m[3]) : null;
}

/**
 * @param {*} value was der DatePicker meldet
 * @returns {string|null} `Y-m-d`
 */
export function fromDateValue(value) {
    if (!value || typeof value.year !== 'number') return null;

    return `${value.year}-${pad(value.month)}-${pad(value.day)}`;
}
