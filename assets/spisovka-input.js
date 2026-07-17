// Live behavior of the reusable spisovka input (see SpisovkaInputFactory):
// debounced validation against the JSON endpoint, court select narrowing and
// a client-side court filter. Initialized for every [data-spisovka-input].

const DEBOUNCE_MS = 400;

const normalize = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

function initSpisovkaInput(root) {
    const validateUrl = root.dataset.validateUrl;
    const znacka = root.querySelector('input[name="znacka"]');
    const select = root.querySelector('select[data-spisovka-court]');
    const filter = root.querySelector('[data-spisovka-court-filter]');
    const messages = root.querySelector('[data-spisovka-messages]');
    if (!validateUrl || !znacka || !select || !messages) {
        return;
    }

    const allOptions = Array.from(select.querySelectorAll('option')).filter((o) => o.value !== '');
    let courtAutoSet = false; // distinguishes our prefill from the user's own choice
    select.addEventListener('change', () => {
        courtAutoSet = false;
    });

    function applyCourtConstraint(fixedKod, candidateKods) {
        const candidates = new Set(candidateKods);
        for (const option of allOptions) {
            option.hidden = fixedKod !== null
                ? option.value !== fixedKod
                : (candidates.size > 0 && !candidates.has(option.value));
        }
        if (fixedKod !== null) {
            select.value = fixedKod;
            courtAutoSet = true;
        } else if (courtAutoSet || select.selectedOptions[0]?.hidden) {
            select.value = ''; // drop our stale prefill / a selection outside the constraint
            courtAutoSet = false;
        }
    }

    function applyFilter() {
        if (!filter) {
            return;
        }
        const needle = normalize(filter.value.trim());
        for (const option of allOptions) {
            if (needle !== '' && !option.hidden) {
                option.hidden = !normalize(option.textContent).includes(needle);
            }
        }
    }

    function renderMessages(data) {
        messages.replaceChildren();
        const add = (cls, text) => {
            const div = document.createElement('div');
            div.className = cls;
            div.textContent = text;
            messages.append(div);
        };
        if (!data.ok) {
            add('text-error', data.error);
            return;
        }
        for (const error of data.errors) {
            add('text-error', error);
        }
        for (const suggestion of data.suggestions) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline btn-xs mt-1 mr-1';
            button.textContent = `Opravit na „${suggestion.text}"`;
            button.addEventListener('click', () => {
                znacka.value = suggestion.text;
                validate();
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
        }
    }

    let timer = null;
    let requestSeq = 0;
    let lastConstraint = {fixed: null, candidates: []};

    function applyAll() {
        applyCourtConstraint(lastConstraint.fixed, lastConstraint.candidates);
        applyFilter();
    }

    async function validate() {
        const text = znacka.value.trim();
        if (text === '') {
            messages.replaceChildren();
            lastConstraint = {fixed: null, candidates: []};
            applyAll();
            return;
        }
        const seq = ++requestSeq;
        try {
            const response = await fetch(`${validateUrl}?text=${encodeURIComponent(text)}`);
            const data = await response.json();
            if (seq !== requestSeq) {
                return; // a newer request is in flight
            }
            renderMessages(data);
            lastConstraint = data.ok
                ? {fixed: data.fixedCourt?.kod ?? null, candidates: data.candidateKods}
                : {fixed: null, candidates: []};
            applyAll();
        } catch {
            // network hiccup - keep quiet, server-side validation still applies on submit
        }
    }

    znacka.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(validate, DEBOUNCE_MS);
    });
    filter?.addEventListener('input', applyAll);

    if (znacka.value.trim() !== '') {
        validate();
    }
}

document.querySelectorAll('[data-spisovka-input]').forEach(initSpisovkaInput);
