<script setup>
// The validation panel under the file-number field: a status icon in a narrow
// left column, the messages in the right one.
//
// The panel is always present - it never collapses and never blanks out
// between keystrokes. While the next check runs, the previous message stays
// (dimmed) and only the icon turns into a spinner; the block was flickering
// through "empty -> checking -> result" while a file number was being typed.
//
// The icon carries the outcome on its own, so the meaning is readable before
// the sentence is: grey ring = nothing to say, spinner = checking, green check
// = recognized, blue arrow = the user has to pick a court, yellow triangle =
// caveat, red cross = unusable as typed.

import {computed} from 'vue';
import {Status} from './validation.js';

const props = defineProps({
    result: {type: Object, default: null},   // message content (may be one check behind)
    status: {type: String, required: true},
    stale: {type: Boolean, default: false},
    failed: {type: Boolean, default: false},
});

const emit = defineEmits(['applySuggestion', 'pickCourt']);

const icon = computed(() => ({
    [Status.Idle]: 'icon-[material-symbols-light--radio-button-unchecked] text-base-content/30',
    [Status.Ok]: 'icon-[material-symbols-light--check-circle-outline] text-success',
    [Status.Choice]: 'icon-[material-symbols-light--arrow-circle-right-outline] text-info',
    [Status.Warning]: 'icon-[material-symbols-light--warning-outline] text-warning',
    [Status.Error]: 'icon-[material-symbols-light--cancel-outline] text-error',
}[props.status] ?? ''));

const label = computed(() => ({
    [Status.Idle]: 'Zatím nic k ověření',
    [Status.Checking]: 'Ověřuji značku',
    [Status.Ok]: 'Značka rozpoznána',
    [Status.Choice]: 'Vyberte prosím soud',
    [Status.Warning]: 'Upozornění',
    [Status.Error]: 'Značku nelze použít',
}[props.status] ?? ''));
</script>

<template>
    <div class="flex items-start gap-2 text-sm">
        <!-- Fixed-width column so the messages never shift sideways when the
             icon changes. -->
        <div class="flex h-5 w-5 shrink-0 items-center justify-center" :title="label">
            <span v-if="status === Status.Checking" class="loading loading-spinner loading-xs text-base-content/40"></span>
            <span v-else :class="icon" class="text-xl" role="img" :aria-label="label"></span>
        </div>

        <!-- role=status + aria-live: the outcome is announced without stealing
             focus; polite, so it waits for a pause in typing. -->
        <div class="min-h-5 grow" :class="{'opacity-50': stale}" role="status" aria-live="polite">
            <div v-if="failed" class="text-warning">
                Ověření značky se nepodařilo — zkuste to prosím znovu, kontrolu jinak provedeme až při odeslání.
            </div>

            <template v-else-if="result">
                <div v-if="!result.ok" class="text-error">{{ result.error }}</div>

                <template v-else>
                    <div v-for="error in result.errors" :key="error" class="text-error">{{ error }}</div>

                    <button
                        v-for="suggestion in result.suggestions"
                        :key="suggestion.code"
                        type="button"
                        class="btn btn-outline btn-xs mt-1 mr-1"
                        @click="emit('applySuggestion', suggestion.text)"
                    >Opravit na „{{ suggestion.text }}“</button>

                    <div v-for="warning in result.warnings" :key="warning" class="text-warning">{{ warning }}</div>

                    <template v-if="result.errors.length === 0">
                        <div class="text-base-content/70">
                            Rozpoznáno: {{ result.prefix ? result.prefix + ' ' : '' }}{{ result.normalized
                            }}<template v-if="result.registryDescription"> — {{ result.registryDescription }}</template>
                        </div>

                        <div v-if="result.fixedCourt" class="text-success">
                            Soud určen: {{ result.fixedCourt.name }} ({{ result.fixedCourt.reason }})
                        </div>

                        <div v-if="result.cachedCourts.length === 1 && !result.fixedCourt" class="text-success">
                            Spis už evidujeme – soud předvybrán: {{ result.cachedCourts[0].name }}
                        </div>
                        <div v-else-if="result.cachedCourts.length === 1 && result.fixedCourt?.kod === result.cachedCourts[0].kod"
                             class="text-success">Spis už evidujeme.</div>

                        <div v-else-if="result.cachedCourts.length > 1" class="text-base-content/70">
                            Spis evidujeme na více soudech – vyberte ten správný:
                            <ul class="list-disc list-inside mt-1">
                                <li v-for="court in result.cachedCourts" :key="court.kod">
                                    <button type="button" class="link link-primary" @click="emit('pickCourt', court.kod)">{{ court.name }}</button>
                                </li>
                            </ul>
                        </div>

                        <!-- Hearings are a weaker hint than the case record: they say a
                             hearing with this file number is held in that court's rooms,
                             not that the case is filed there - the wording must not promise it. -->
                        <template v-if="!result.fixedCourt && result.cachedCourts.length === 0">
                            <div v-if="(result.hearingCourts ?? []).length === 1" class="text-success">
                                U soudu {{ result.hearingCourts[0].name }} evidujeme jednání s touto značkou – soud předvybrán.
                            </div>
                            <div v-else-if="(result.hearingCourts ?? []).length > 1" class="text-base-content/70">
                                Jednání s touto značkou evidujeme u více soudů – vyberte ten správný:
                                <ul class="list-disc list-inside mt-1">
                                    <li v-for="court in result.hearingCourts" :key="court.kod">
                                        <button type="button" class="link link-primary" @click="emit('pickCourt', court.kod)">{{ court.name }}</button>
                                    </li>
                                </ul>
                            </div>
                        </template>
                    </template>
                </template>
            </template>

            <div v-else class="text-base-content/50">Zadejte spisovou značku, průběžně ji ověříme.</div>
        </div>
    </div>
</template>
