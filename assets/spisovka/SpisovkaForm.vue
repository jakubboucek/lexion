<script setup>
// The spisovka tool. The server contributes the endpoints, the court codelist
// and the prefill; the form itself exists only here, so there is no second,
// server-rendered version of it to drift apart.

import {computed, onMounted, ref, shallowRef, useTemplateRef} from 'vue';
import SpisovkaPanel from './SpisovkaPanel.vue';
import {useCourtSuggestion, useSpisovkaValidation} from './validation.js';
import {useTomSelect} from './tomSelect.js';

const props = defineProps({
    /** Endpoints the island talks to. */
    config: {type: Object, required: true},
    /** What the visitor arrives with (prefill from the URL). */
    state: {type: Object, required: true},
    /** Court codelist, grouped by court level. */
    courts: {type: Array, required: true},
});

const znacka = ref(props.state.znacka ?? '');
const input = useTemplateRef('input');
const select = useTemplateRef('select');

const validation = useSpisovkaValidation(props.config.validateUrl);
const {touched, allowed, suggested} = useCourtSuggestion(validation.result);

const submitting = ref(null);          // which button is waiting for the server
const formErrors = ref([]);            // errors that belong to no single field
const courtErrors = ref([]);
// Tom Select is created on mount (it needs the rendered <select>), so the
// wrapper lives in a ref the template can read once it exists.
const courtSelect = shallowRef(null);

onMounted(() => {
    courtSelect.value = useTomSelect(select.value, {allowed, suggested, touched});
    if (props.state.soud) {
        courtSelect.value.courts.setValue(props.state.soud); // prefill counts as the user's choice
    }
    if (znacka.value.trim() !== '') {
        validation.validateNow(znacka.value);
    }
});

function onInput() {
    formErrors.value = [];
    courtErrors.value = [];
    validation.onInput(znacka.value);
}

function applySuggestion(text) {
    znacka.value = text;
    validation.validateNow(text);
    input.value?.focus();
}

/**
 * Both buttons ask the server where to go: the same rules the POST used to
 * apply (court fallback, NSS refusal, "the case must exist"). Navigation
 * happens only on its answer.
 */
async function submit(action) {
    if (submitting.value !== null) {
        return;
    }
    submitting.value = action;
    formErrors.value = [];
    courtErrors.value = [];
    // Mirror the values into the current history entry before leaving, so the
    // back button restores the search (the server prefills from the params).
    const url = new URL(location.href);
    const put = (key, value) => (value !== '' ? url.searchParams.set(key, value) : url.searchParams.delete(key));
    put('znacka', znacka.value.trim());
    put('soud', courtSelect.value?.getValue() ?? '');
    history.replaceState(history.state, '', url);

    try {
        const response = await fetch(props.config.resolveUrl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({text: znacka.value.trim(), soud: courtSelect.value?.getValue() ?? '', action}),
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const data = await response.json();
        if (data.ok) {
            location.href = data.redirect;
            return; // keep the buttons disabled while the browser navigates
        }
        formErrors.value = data.errors.form ?? [];
        courtErrors.value = data.errors.soud ?? [];
        if (data.errors.znacka) {
            // A field error means the live validator will show it too - let it
            // own the message and switch to live mode.
            validation.validateNow(znacka.value);
        }
    } catch {
        formErrors.value = ['Požadavek se nepodařilo odeslat, zkuste to prosím znovu.'];
    } finally {
        submitting.value = null;
    }
}

const busy = computed(() => submitting.value !== null);
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit('detail')">
        <div v-for="error in formErrors" :key="error" class="alert alert-error text-sm">{{ error }}</div>

        <fieldset class="fieldset w-full">
            <label for="spisovka-znacka" class="text-base font-semibold">Spisová značka</label>
            <input
                id="spisovka-znacka"
                ref="input"
                v-model="znacka"
                class="input w-full font-mono"
                autocomplete="off"
                autofocus
                placeholder="např. „12 C 34/2026“ nebo „KSPH 60 INS 19742/2024“"
                :aria-invalid="validation.showErrors.value && validation.result.value !== null
                    && (!validation.result.value.ok || validation.result.value.errors.length > 0)"
                aria-describedby="spisovka-messages"
                @input="onInput"
                @blur="validation.onBlur()"
                @compositionstart="validation.onCompositionStart()"
                @compositionend="validation.onCompositionEnd(znacka)"
            >
        </fieldset>

        <SpisovkaPanel
            id="spisovka-messages"
            :result="validation.shown.value"
            :status="validation.status.value"
            :stale="validation.stale.value"
            :failed="validation.failed.value"
            @apply-suggestion="applySuggestion"
            @pick-court="courtSelect?.pick"
        />

        <fieldset class="fieldset w-full">
            <label for="spisovka-soud" class="text-base font-semibold">Soud</label>
            <!-- Tom Select attaches to this select on mount; the island only
                 says which courts are offered and which one is suggested. -->
            <select id="spisovka-soud" ref="select" class="w-full">
                <option value="">(určit automaticky ze značky)</option>
                <optgroup v-for="group in courts" :key="group.label" :label="group.label">
                    <option v-for="court in group.courts" :key="court.kod" :value="court.kod">{{ court.name }}</option>
                </optgroup>
            </select>
            <p v-for="error in courtErrors" :key="error" class="text-sm text-error">{{ error }}</p>
        </fieldset>

        <div class="mt-2 flex flex-wrap items-center gap-2">
            <button type="submit" class="btn btn-primary" :disabled="busy">
                <span v-if="submitting === 'detail'" class="loading loading-spinner loading-xs" aria-hidden="true"></span>
                <span v-else class="icon-[material-symbols-light--book-ribbon] text-[1.2em]" aria-hidden="true"></span>Otevřít</button>
            <button type="button" class="btn font-normal" :disabled="busy" @click="submit('infosoud')">
                <span v-if="submitting === 'infosoud'" class="loading loading-spinner loading-xs" aria-hidden="true"></span>
                <span v-else class="icon-[material-symbols-light--arrow-right-alt] text-[1.2em]" aria-hidden="true"></span>InfoSoud</button>
            <button type="button" class="btn font-normal" disabled
                    title="Jen pro přihlášené – připravujeme. Prohledá soudy, kde se spis najde.">
                <span class="icon-[material-symbols-light--database-search-outline] text-[1.2em]" aria-hidden="true"></span>Najít příslušný soud</button>
        </div>
    </form>
</template>
