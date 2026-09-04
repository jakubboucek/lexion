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

import {computed, ref, watch} from 'vue';
import {Status} from './validation.js';

const props = defineProps({
    result: {type: Object, default: null},   // message content (may be one check behind)
    status: {type: String, required: true},
    stale: {type: Boolean, default: false},
    failed: {type: Boolean, default: false},
});

const emit = defineEmits(['applySuggestion', 'pickCourt']);

// Case files and hearing venues as one ranked list - files (bold) first.
const evidenceCourts = computed(() => {
    const data = props.result;
    if (!data?.ok) {
        return [];
    }
    return [
        ...(data.cachedCourts ?? []).map((court) => ({...court, tier: 'cached'})),
        ...(data.hearingCourts ?? []).map((court) => ({...court, tier: 'hearing'})),
    ];
});

const senateCourts = computed(() => (props.result?.ok ? props.result.senateCourts ?? [] : []));

// The senate tier folds by size: up to 5 courts show in full, up to 10 show
// the first 3 with a "show the rest" link, and an even longer list (a senate
// number shared by half the country says very little) hides entirely behind
// one link. Expanding is per answer - a new answer folds it again.
const senateExpanded = ref(false);
watch(() => props.result, () => {
    senateExpanded.value = false;
});
const senateCollapsed = computed(() => senateCourts.value.length > 10 && !senateExpanded.value);
const senateVisible = computed(() => {
    const list = senateCourts.value;
    if (senateExpanded.value || list.length <= 5) {
        return list;
    }
    return list.slice(0, 3);
});
const senateHiddenCount = computed(() => senateCourts.value.length - senateVisible.value.length);

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

                        <!-- One ranked candidate list: case files on record (bold) first,
                             then hearing venues. A hearing names the court of the ROOM,
                             hence the "(nařízené jednání)" qualifier on those items.
                             Spacing: the list hugs its own heading, blocks keep their
                             distance from each other via mt-2 on the block. -->
                        <div v-if="!result.fixedCourt && evidenceCourts.length > 0" class="text-base-content/70 mt-2">
                            Spis evidujeme u těchto soudů – {{ result.preselectedKod ? 'soud předvybrán:' : 'vyberte ten správný:' }}
                            <ul class="list-disc list-inside">
                                <li v-for="court in evidenceCourts" :key="court.kod">
                                    <button type="button" class="link link-primary" :class="{'font-bold': court.tier === 'cached'}"
                                            @click="emit('pickCourt', court.kod)">{{ court.name }}</button
                                    ><template v-if="court.tier === 'hearing'"> (nařízené jednání)</template>
                                </li>
                            </ul>
                        </div>

                        <!-- Senate tier: the weakest hint - the work schedules only say the
                             senate exists at that court, nothing about this very case. -->
                        <template v-if="!result.fixedCourt && senateCourts.length > 0">
                            <div v-if="senateCourts.length === 1 && evidenceCourts.length === 0" class="text-success">
                                Soud určen podle senátu {{ result.senateLabel }}: {{ senateCourts[0].name }}
                            </div>
                            <div v-else-if="senateCollapsed" class="mt-2">
                                <button type="button" class="link link-primary" @click="senateExpanded = true"
                                >Zobrazit soudy s aktivním senátem {{ result.senateLabel }} ({{ senateCourts.length }} soudů)</button>
                            </div>
                            <div v-else class="text-base-content/70 mt-2">
                                Senát {{ result.senateLabel }} je aktivní{{ evidenceCourts.length > 0 ? ' i' : '' }}
                                {{ senateCourts.length === 1 ? 'na soudu:' : 'na soudech:' }}
                                <ul class="list-disc list-inside">
                                    <li v-for="court in senateVisible" :key="court.kod">
                                        <button type="button" class="link link-primary" @click="emit('pickCourt', court.kod)">{{ court.name }}</button>
                                    </li>
                                    <!-- The expander is the list's own last item - an ellipsis
                                         continuation, not a standalone control. -->
                                    <li v-if="senateHiddenCount > 0">
                                        <button type="button" class="link link-primary" @click="senateExpanded = true"
                                                :aria-label="`Zobrazit ${senateHiddenCount >= 5 ? 'dalších' : 'další'} ${senateHiddenCount} soud${senateHiddenCount >= 5 ? 'ů' : 'y'}`"
                                        >… a {{ senateHiddenCount >= 5 ? `dalších ${senateHiddenCount} soudů` : `další ${senateHiddenCount} soudy` }}</button>
                                    </li>
                                </ul>
                            </div>
                        </template>
                    </template>
                </template>
            </template>
        </div>
    </div>
</template>
