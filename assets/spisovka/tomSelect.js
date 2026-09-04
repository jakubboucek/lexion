// Tom Select kept as-is, wrapped so Vue can drive it declaratively.
//
// Deliberately not replaced by a Vue combobox: Tom Select carries the fuzzy
// search over ~98 courts, the optgroups, keyboard handling and ~90 lines of
// daisyUI styling in app.css. Rewriting that is a separate project; what the
// island needs is only to say "this court is suggested". The options are
// never narrowed: the candidate data is known to be incomplete, so the full
// court list stays available at all times.

import TomSelect from 'tom-select';
import {watch} from 'vue';

export function useTomSelect(selectEl, {suggested, touched}) {
    const courts = new TomSelect(selectEl, {
        plugins: ['dropdown_input'],
        allowEmptyOption: true, // the "determine automatically" prompt stays selectable
        maxOptions: null,
        render: {
            no_results: () => '<div class="no-results">Žádný soud neodpovídá hledání</div>',
        },
    });
    courts.control_input.setAttribute('placeholder', 'hledat soud… (např. „trut“)');

    // A change event only reaches us when it did not come from us: our own
    // writes below are silent. So this is exactly "the user chose a court".
    courts.on('change', () => {
        touched.value = true;
    });

    /** The user picking a court from a message list is their own choice. */
    function pick(kod) {
        if (kod in courts.options) {
            courts.setValue(kod); // not silent: marks the choice as the user's
            // Move the focus ring onto the field that just changed, but leave
            // the dropdown shut - the choice is already made.
            courts.settings.openOnFocus = false;
            courts.focus();
            window.setTimeout(() => {
                courts.settings.openOnFocus = true;
            }, 0);
        }
    }

    watch(suggested, (suggestedKod) => {
        // The suggestion only fills an empty field or replaces an earlier
        // suggestion of ours - never the user's own choice.
        if (suggestedKod !== null && suggestedKod in courts.options && (!touched.value || courts.getValue() === '')) {
            courts.setValue(suggestedKod, true);
            touched.value = false;
        } else if (suggestedKod === null && !touched.value && courts.getValue() !== '') {
            courts.setValue('', true); // our earlier suggestion no longer holds
        }
    });

    return {courts, pick, getValue: () => courts.getValue()};
}
