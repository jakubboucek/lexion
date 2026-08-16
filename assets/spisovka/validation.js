// Validation state of the spisovka input.
//
// The one rule that keeps this correct: a server answer is applied only when it
// describes the text that is in the field *now*. Requests are therefore never
// raced - a late answer for older text is dropped instead of being applied,
// which also covers "the user cleared the field" for free. Requests are not
// cancelled when the next keystroke arrives (PHP finishes its query regardless,
// so nothing is saved); the only place worth aborting is a cleared field.
//
// Display is deliberately steadier than the state: the last message the panel
// was allowed to show stays on screen while the next check runs, only marked as
// stale. Blanking it on every keystroke made the panel flicker through
// "empty -> checking -> result" on the way to a complete file number.
//
// Errors follow "reward early, punish late": while the field is pristine an
// erroneous answer shows nothing at all - premature errors are the most
// complained-about part of live validation (Baymard).

import {computed, ref, shallowRef} from 'vue';

export const DEBOUNCE_MS = 400;

/** Panel states, in the order they take precedence. */
export const Status = {
    Idle: 'idle',           // nothing to say yet (empty field, or a pristine field with errors)
    Checking: 'checking',   // an answer for the current text is on its way
    Error: 'error',         // the file number cannot be used as typed
    Choice: 'choice',       // recognized, but the user has to pick a court
    Warning: 'warning',     // recognized with a caveat, or the check itself failed
    Ok: 'ok',               // recognized, nothing left to do
};

function hasErrors(data) {
    return data !== null && (!data.ok || data.errors.length > 0);
}

function needsChoice(data) {
    return data !== null && data.ok && data.errors.length === 0
        && ((data.cachedCourts?.length ?? 0) > 1 || (data.hearingCourts?.length ?? 0) > 1);
}

export function useSpisovkaValidation(validateUrl, {debounceMs = DEBOUNCE_MS} = {}) {
    const text = ref('');
    const result = shallowRef(null);    // last applied answer
    const resultFor = ref(null);        // the text that answer describes
    const shown = shallowRef(null);     // last answer the panel was allowed to show
    const shownFor = ref(null);         // the text *that* answer describes
    const pending = ref(false);
    const failed = ref(false);          // the check itself could not be run
    const eager = ref(false);           // an error has been shown -> validate live from now on
    const showErrors = ref(false);

    let timer = null;
    const inflight = new Set();         // controllers of requests still running
    let composing = false;              // IME / dead keys: input events are not final yet

    // Strict view: only an answer describing the current text may drive the
    // court field. Display uses `shown` instead, which may lag by one check.
    const applied = computed(() => (resultFor.value === text.value ? result.value : null));

    const status = computed(() => {
        if (text.value.trim() === '') {
            return Status.Idle;
        }
        if (failed.value) {
            return Status.Warning;
        }
        if (pending.value || resultFor.value !== text.value) {
            return Status.Checking;
        }
        const data = result.value;
        if (hasErrors(data)) {
            // Pristine field: the answer is not shown, so the icon must not
            // announce it either.
            return showErrors.value ? Status.Error : Status.Idle;
        }
        if (needsChoice(data)) {
            return Status.Choice;
        }
        return (data?.warnings.length ?? 0) > 0 ? Status.Warning : Status.Ok;
    });

    // The message on screen is stale whenever it describes something else than
    // what is in the field - including the case where a newer answer arrived
    // but was not allowed on screen (a pristine field with errors).
    const stale = computed(() => shown.value !== null && shownFor.value !== text.value);

    async function run(withErrors) {
        const asked = text.value;
        if (asked === '') {
            clear();
            return;
        }
        showErrors.value = withErrors || eager.value;
        pending.value = true;
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
            if (showErrors.value && hasErrors(data)) {
                eager.value = true;
            }
            if (showErrors.value || !hasErrors(data)) {
                shown.value = data; // allowed on screen, so it becomes the panel's content
                shownFor.value = asked;
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
        // The one place where cancelling is worth it: nothing is coming back
        // into an empty field, so let the connections go.
        for (const controller of inflight) {
            controller.abort();
        }
        inflight.clear();
        result.value = null;
        resultFor.value = null;
        shown.value = null;
        shownFor.value = null;
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
        text, status, stale, failed, showErrors,
        result: applied,   // drives the court field
        shown,             // drives the panel
        onInput, onBlur, onCompositionStart, onCompositionEnd, validateNow,
    };
}


/**
 * Which court the answer suggests, and which courts stay offered.
 *
 * Kept apart from the fetch state on purpose: this - not the request handling -
 * is the part no form library models. The answer may change *another* field,
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
        // The record wins over hearings: it knows the case is filed at that
        // court, a hearing only points at the room's court.
        if (data.cachedCourts.length === 1) {
            return data.cachedCourts[0].kod;
        }
        const hearings = data.hearingCourts ?? [];
        return data.cachedCourts.length === 0 && hearings.length === 1 ? hearings[0].kod : null;
    });

    return {touched, allowed, suggested};
}
