// Validation state of the spisovka input, shared by both island variants.
//
// The one rule that keeps this correct: a response is applied only when it
// describes the text that is in the field *now*. Requests are therefore never
// raced - a late answer for older text is dropped rather than rendered, which
// also covers the "user cleared the field" case for free. Aborting the request
// is only an optimization on top (see abortOnClear below): PHP would finish the
// query anyway, so cancelling buys nothing on the server.
//
// See docs: the "reward early, punish late" pattern (errors stay hidden while
// the field is pristine) matches the inline-validation research - premature
// errors are the most complained-about part of live validation.

import {computed, ref, shallowRef} from 'vue';

export const DEBOUNCE_MS = 400;

export function useSpisovkaValidation(validateUrl, {debounceMs = DEBOUNCE_MS, abortOnClear = true} = {}) {
    const text = ref('');
    const result = shallowRef(null);   // last applied server answer
    const resultFor = ref(null);       // the text that answer describes
    const pending = ref(false);
    const failed = ref(false);         // the check itself could not be run
    const eager = ref(false);          // an error has been shown -> validate live from now on

    let timer = null;
    const inflight = new Set();        // controllers of requests still running
    let composing = false;             // IME / dead keys: input events are not final yet

    // The result is only meaningful while it matches the current input; that
    // single condition replaces the old sequence counter and its blind spot.
    const current = computed(() => (resultFor.value === text.value ? result.value : null));
    const showErrors = ref(false);

    async function run(withErrors) {
        const asked = text.value;
        if (asked === '') {
            clear();
            return;
        }
        showErrors.value = withErrors || eager.value;
        pending.value = true;
        // Requests are NOT cancelled when the next keystroke arrives: PHP would
        // finish the query anyway, so nothing is saved by aborting. Correctness
        // comes from the guard below, not from cancelling.
        const controller = new AbortController();
        inflight.add(controller);
        try {
            const response = await fetch(`${validateUrl}?text=${encodeURIComponent(asked)}`, {signal: controller.signal});
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const data = await response.json();
            if (asked !== text.value) {
                return; // the field moved on; this answer describes older text
            }
            result.value = data;
            resultFor.value = asked;
            failed.value = false;
            if (showErrors.value && (!data.ok || data.errors.length > 0)) {
                eager.value = true;
            }
        } catch (e) {
            if (e.name === 'AbortError' || asked !== text.value) {
                return;
            }
            failed.value = true;
        } finally {
            inflight.delete(controller);
            if (asked === text.value) {
                pending.value = false;
            }
        }
    }

    function clear() {
        window.clearTimeout(timer);
        if (abortOnClear) {
            // The one place where cancelling is worth it: nothing is coming
            // back into an empty field, so let the connection go.
            for (const controller of inflight) {
                controller.abort();
            }
            inflight.clear();
        }
        result.value = null;
        resultFor.value = null;
        pending.value = false;
        failed.value = false;
    }

    /** Called on every keystroke; schedules a debounced check. */
    function onInput(value) {
        text.value = value;
        window.clearTimeout(timer);
        if (value.trim() === '') {
            clear();
            return;
        }
        if (composing) {
            return; // wait for the composition to be committed
        }
        timer = window.setTimeout(() => run(false), debounceMs);
    }

    /** Leaving the field finishes the input: show errors even while pristine. */
    function onBlur() {
        if (eager.value || text.value.trim() === '') {
            return;
        }
        window.clearTimeout(timer);
        run(true);
    }

    function onCompositionStart() {
        composing = true;
    }

    function onCompositionEnd(value) {
        composing = false;
        onInput(value);
    }

    /** Validates right away (prefilled field, or after applying a suggestion). */
    function validateNow(value) {
        text.value = value;
        window.clearTimeout(timer);
        if (value.trim() === '') {
            clear();
            return;
        }
        run(true);
    }

    return {
        text, pending, failed, eager, showErrors,
        result: current,
        onInput, onBlur, onCompositionStart, onCompositionEnd, validateNow,
    };
}


/**
 * Which court the answer suggests, and which courts stay offered.
 *
 * Kept apart from the fetch state on purpose: this - not the request handling -
 * is the part no form library models. The response may change *another* field,
 * but only while that field still holds our own suggestion.
 */
export function useCourtSuggestion(result) {
    const touched = ref(false); // the user picked a court themselves

    const allowed = computed(() => {
        const data = result.value;
        if (!data?.ok) {
            return null; // no constraint
        }
        if (data.fixedCourt) {
            return [data.fixedCourt.kod];
        }
        return data.candidateKods.length > 0 ? data.candidateKods : null;
    });

    const suggested = computed(() => {
        const data = result.value;
        if (!data?.ok) {
            return null;
        }
        if (data.fixedCourt) {
            return data.fixedCourt.kod; // determined by the file number itself
        }
        // The cache wins over hearings: it knows the case is filed at that
        // court, a hearing only points at the room's court.
        if (data.cachedCourts.length === 1) {
            return data.cachedCourts[0].kod;
        }
        const hearings = data.hearingCourts ?? [];
        return data.cachedCourts.length === 0 && hearings.length === 1 ? hearings[0].kod : null;
    });

    return {touched, allowed, suggested};
}
