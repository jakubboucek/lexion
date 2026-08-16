// Tom Select kept as-is, wrapped so Vue can drive it declaratively.
//
// Deliberately not replaced by a Vue combobox: Tom Select carries the fuzzy
// search over ~98 courts, the optgroups, keyboard handling and ~90 lines of
// daisyUI styling in app.css. Rewriting that is a separate project; what the
// island needs is only to say "these courts are offered" and "this one is
// suggested".

import TomSelect from 'tom-select';
import {ref, watch} from 'vue';

export function useTomSelect(selectEl, {allowed, suggested, touched}) {
    const courts = new TomSelect(selectEl, {
        plugins: ['dropdown_input'],
        allowEmptyOption: true, // the "determine automatically" prompt stays selectable
        maxOptions: null,
        render: {
            no_results: () => '<div class="no-results">Žádný soud neodpovídá hledání</div>',
        },
    });
    courts.control_input.setAttribute('placeholder', 'hledat soud… (např. „trut“)');

    // The selected court as reactive state: the panel has to know what is
    // actually in the field, otherwise its messages would describe a preselect
    // that never happened.
    const selected = ref(courts.getValue());
    const sync = () => {
        selected.value = courts.getValue();
    };

    // Full option snapshot (incl. optgroup and order) for constraint rebuilds.
    const allOptions = Object.values(courts.options)
        .filter((option) => option.value !== '')
        .map((option) => ({...option}));

    // A change event only reaches us when it did not come from us: our own
    // writes below are silent. So this is exactly "the user chose a court".
    courts.on('change', () => {
        touched.value = true;
        sync();
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

    watch([allowed, suggested], ([allowedKods, suggestedKod]) => {
        const allowedSet = allowedKods !== null ? new Set(allowedKods) : null;
        for (const option of allOptions) {
            const present = option.value in courts.options;
            if (allowedSet !== null && !allowedSet.has(option.value)) {
                if (present) {
                    courts.removeOption(option.value);
                }
            } else if (!present) {
                courts.addOption({...option});
            }
        }

        // A selection that fell outside the offered set cannot stay - including
        // the user's own, because the file number says it cannot belong there.
        if (!(courts.getValue() in courts.options)) {
            courts.setValue('', true);
            touched.value = false;
            sync();
        }

        // The suggestion only fills an empty field or replaces an earlier
        // suggestion of ours - never the user's own choice.
        if (suggestedKod !== null && suggestedKod in courts.options && (!touched.value || courts.getValue() === '')) {
            courts.setValue(suggestedKod, true);
            touched.value = false;
        } else if (suggestedKod === null && !touched.value && courts.getValue() !== '') {
            courts.setValue('', true); // our earlier suggestion no longer holds
        }
        sync();

        courts.refreshOptions(false);
    });

    return {courts, pick, selected, getValue: () => courts.getValue()};
}
