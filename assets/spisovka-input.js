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
//
// The court used at submit is remembered in localStorage and prefilled on the
// next fresh form as a sticky default; court detection and the cache-based
// preselect may override it, a manual choice always wins.

import TomSelect from 'tom-select';

const DEBOUNCE_MS = 400;
const LAST_COURT_KEY = 'lexion.spisovka.lastCourt';

// localStorage may be unavailable (private mode, disabled cookies).
function readLastCourt() {
    try {
        return localStorage.getItem(LAST_COURT_KEY);
    } catch {
        return null;
    }
}

function storeLastCourt(kod) {
    try {
        localStorage.setItem(LAST_COURT_KEY, kod);
    } catch {
        // best effort only
    }
}

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

    // Origin of the current selection: 'none' (empty), 'stored' (last-used
    // court remembered in localStorage - a sticky default that survives typing
    // but yields to court detection), 'auto' (our validation-driven prefill,
    // dropped whenever the constraint changes) or 'user' (manual choice, never
    // overridden). setValue(..., true) is silent, so 'change' fires only for
    // the user's own picks.
    let courtOrigin = 'none';
    courts.on('change', () => {
        courtOrigin = 'user';
    });

    // Issue #1: prefill the last used court on a fresh form.
    const lastCourt = readLastCourt();
    if (lastCourt && courts.getValue() === '' && lastCourt in courts.options) {
        courts.setValue(lastCourt, true);
        courtOrigin = 'stored';
    }
    // The wrapper may sit inside the form or wrap it (Home does the latter).
    const form = root.closest('form') ?? root.querySelector('form');
    form?.addEventListener('submit', () => {
        const kod = courts.getValue();
        if (kod !== '') {
            storeLastCourt(kod);
        }
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
            courtOrigin = 'auto';
        } else if (courtOrigin === 'auto' || !(courts.getValue() in courts.options)) {
            // Drop a stale validation prefill or a selection outside the
            // constraint; the 'stored' default stays put otherwise.
            courts.setValue('', true);
            courtOrigin = 'none';
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
            // but only over an empty field or a prefill of ours (stored or
            // auto) - never over the user's manual choice (and options stay
            // unconstrained).
            if (data.ok && !data.fixedCourt && data.cachedCourts.length === 1) {
                const cachedKod = data.cachedCourts[0].kod;
                if (cachedKod in courts.options && (courtOrigin !== 'user' || courts.getValue() === '')) {
                    courts.setValue(cachedKod, true);
                    courtOrigin = 'auto';
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
