// Live behavior of the reusable spisovka input (see SpisovkaInputFactory):
// debounced validation against the JSON endpoint and a searchable court
// combobox (Tom Select, dropdown_input plugin: closed = selectbox, opened =
// filter field on top + filtered items below). Court detection narrows the
// offered options. Initialized for every [data-spisovka-input].
//
// Errors follow the "reward early, punish late" pattern: while the field is
// pristine, typing shows only positive feedback (recognized value, court
// detection) and errors stay hidden until the user leaves the field or
// submits. Once an error has been displayed, validation goes fully live.

import TomSelect from 'tom-select';

const DEBOUNCE_MS = 400;

function initSpisovkaInput(root) {
    const validateUrl = root.dataset.validateUrl;
    const znacka = root.querySelector('input[name="znacka"]');
    const select = root.querySelector('select[data-spisovka-court]');
    const messages = root.querySelector('[data-spisovka-messages]');
    if (!validateUrl || !znacka || !select || !messages) {
        return;
    }

    const courts = new TomSelect(select, {
        plugins: ['dropdown_input'],
        allowEmptyOption: true, // the "determine automatically" prompt stays selectable
        maxOptions: null,
        render: {
            no_results: () => '<div class="no-results">Žádný soud neodpovídá hledání</div>',
        },
    });
    courts.control_input.setAttribute('placeholder', 'hledat soud… (např. „trut“)');
    // Full option snapshot (incl. optgroup and order) for constraint rebuilds.
    const allOptions = Object.values(courts.options)
        .filter((o) => o.value !== '')
        .map((o) => ({...o}));

    let courtAutoSet = false; // distinguishes our prefill from the user's own choice
    courts.on('change', () => {
        courtAutoSet = false;
    });

    // Mirror the form values into the current history entry just before the
    // form leaves the page (same trick as infosoud): the POST/redirect flow
    // otherwise brings the browser's back button to an empty form. The server
    // reads the znacka/soud GET params and prefills the form from them.
    const form = root.closest('form') ?? root.querySelector('form');
    form?.addEventListener('submit', () => {
        const url = new URL(location.href);
        const put = (key, value) => (value !== '' ? url.searchParams.set(key, value) : url.searchParams.delete(key));
        put('znacka', znacka.value.trim());
        put('soud', courts.getValue());
        history.replaceState(history.state, '', url);
    });

    function applyCourtConstraint(fixedKod, candidateKods) {
        const allowed = fixedKod !== null
            ? new Set([fixedKod])
            : (candidateKods.length > 0 ? new Set(candidateKods) : null);
        for (const option of allOptions) {
            const present = option.value in courts.options;
            if (allowed !== null && !allowed.has(option.value)) {
                if (present) {
                    courts.removeOption(option.value);
                }
            } else if (!present) {
                courts.addOption({...option});
            }
        }
        if (fixedKod !== null) {
            courts.setValue(fixedKod, true);
            courtAutoSet = true;
        } else if (courtAutoSet || !(courts.getValue() in courts.options)) {
            courts.setValue('', true); // drop our stale prefill / a selection outside the constraint
            courtAutoSet = false;
        }
        courts.refreshOptions(false);
    }

    // Renders the validation result; with showErrors=false (pristine field,
    // still typing) any erroneous result just clears the messages instead.
    // Returns whether an error was actually displayed.
    function renderMessages(data, showErrors) {
        const hasErrors = !data.ok || data.errors.length > 0;
        messages.replaceChildren();
        if (!showErrors && hasErrors) {
            return false;
        }
        const add = (cls, text) => {
            const div = document.createElement('div');
            div.className = cls;
            div.textContent = text;
            messages.append(div);
        };
        if (!data.ok) {
            add('text-error', data.error);
            return true;
        }
        for (const error of data.errors) {
            add('text-error', error);
        }
        for (const suggestion of data.suggestions) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline btn-xs mt-1 mr-1';
            button.textContent = `Opravit na „${suggestion.text}“`;
            button.addEventListener('click', () => {
                znacka.value = suggestion.text;
                validate(true);
            });
            messages.append(button);
        }
        for (const warning of data.warnings) {
            add('text-warning', warning);
        }
        if (data.errors.length === 0) {
            let info = `Rozpoznáno: ${data.prefix ? data.prefix + ' ' : ''}${data.normalized}`;
            if (data.registryDescription) {
                info += ` — ${data.registryDescription}`;
            }
            add('text-base-content/70', info);
            if (data.fixedCourt) {
                add('text-success', `Soud určen: ${data.fixedCourt.name} (${data.fixedCourt.reason})`);
            }
            if (data.cachedCourts.length === 1) {
                if (!data.fixedCourt) {
                    add('text-success', `Spis už evidujeme – soud předvybrán: ${data.cachedCourts[0].name}`);
                } else if (data.fixedCourt.kod === data.cachedCourts[0].kod) {
                    add('text-success', 'Spis už evidujeme.');
                }
            } else if (data.cachedCourts.length > 1) {
                const names = data.cachedCourts.map((c) => c.name).join(', ');
                add('text-base-content/70', `Spis evidujeme na více soudech (${names}) – vyberte ten správný.`);
            }
            // Hearings are a weaker hint than the cache: they say a hearing
            // with this file number is held in that court's rooms, not that
            // the case is filed there - so the wording must not promise it.
            const hearingCourts = data.hearingCourts ?? [];
            if (!data.fixedCourt && data.cachedCourts.length === 0) {
                if (hearingCourts.length === 1) {
                    add('text-success', `U soudu ${hearingCourts[0].name} evidujeme jednání s touto značkou – soud předvybrán.`);
                } else if (hearingCourts.length > 1) {
                    const names = hearingCourts.map((c) => c.name).join(', ');
                    add('text-base-content/70', `Jednání s touto značkou evidujeme u více soudů (${names}) – vyberte ten správný.`);
                }
            }
        }
        return hasErrors;
    }

    let timer = null;
    let requestSeq = 0;
    let eager = false; // flips once an error has been shown; errors then display live

    async function validate(showErrors) {
        const text = znacka.value.trim();
        if (text === '') {
            messages.replaceChildren();
            applyCourtConstraint(null, []);
            return;
        }
        const seq = ++requestSeq;
        try {
            const response = await fetch(`${validateUrl}?text=${encodeURIComponent(text)}`);
            const data = await response.json();
            if (seq !== requestSeq) {
                return; // a newer request is in flight
            }
            if (renderMessages(data, showErrors || eager)) {
                eager = true;
            }
            applyCourtConstraint(data.ok ? (data.fixedCourt?.kod ?? null) : null, data.ok ? data.candidateKods : []);
            // Cache-based preselect: a single cached match fills the court in,
            // but only over an empty field or our own earlier prefill - never
            // over the user's manual choice (and options stay unconstrained).
            if (data.ok && !data.fixedCourt) {
                const hearingCourts = data.hearingCourts ?? [];
                // The cache wins over hearings: it knows the case is filed at
                // that court, while a hearing only points at the room's court.
                const preselect = data.cachedCourts.length === 1
                    ? data.cachedCourts[0].kod
                    : (data.cachedCourts.length === 0 && hearingCourts.length === 1 ? hearingCourts[0].kod : null);
                if (preselect !== null && preselect in courts.options && (courtAutoSet || courts.getValue() === '')) {
                    courts.setValue(preselect, true);
                    courtAutoSet = true;
                }
            }
        } catch {
            // network hiccup - keep quiet, server-side validation still applies on submit
        }
    }

    znacka.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => validate(false), DEBOUNCE_MS);
    });

    // Leaving the field marks the input as finished: run one full validation
    // (errors included) even while the field is still pristine.
    znacka.addEventListener('blur', () => {
        if (eager) {
            return; // live mode already shows everything
        }
        clearTimeout(timer);
        if (znacka.value.trim() !== '') {
            validate(true);
        }
    });

    // A prefilled field (form redisplayed by the server) validates in full
    // right away, so a server-side error switches straight to live mode.
    if (znacka.value.trim() !== '') {
        validate(true);
    }
}

document.querySelectorAll('[data-spisovka-input]').forEach(initSpisovkaInput);
