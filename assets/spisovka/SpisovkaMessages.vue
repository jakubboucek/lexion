<script setup>
// Messages under the file-number field: recognized value, court determination,
// errors, one-click fixes and the court shortlists. Pure rendering of the
// validation state - the decisions live in validation.js.
import {computed} from 'vue';

const props = defineProps({
    result: {type: Object, default: null},
    showErrors: {type: Boolean, default: false},
    pending: {type: Boolean, default: false},
    failed: {type: Boolean, default: false},
});

const emit = defineEmits(['applySuggestion', 'pickCourt']);

const hasErrors = computed(() => props.result !== null && (!props.result.ok || props.result.errors.length > 0));

// "Reward early, punish late": while the field is pristine, an erroneous
// result shows nothing at all - not even the warnings that come with it.
// Only positive feedback may interrupt someone who is still typing.
const visible = computed(() => props.result !== null && (props.showErrors || !hasErrors.value));
</script>

<template>
    <!-- role=status + aria-live: a screen reader announces the outcome without
         stealing focus; polite so it waits for a pause in typing (FE-4). -->
    <div class="empty:hidden text-sm" role="status" aria-live="polite">
        <div v-if="failed" class="text-warning">
            Ověření značky se nepodařilo — zkuste to prosím znovu, kontrolu jinak provedeme až při odeslání.
        </div>

        <template v-else-if="visible">
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

        <div v-else-if="pending" class="text-base-content/50">Ověřuji značku…</div>
    </div>
</template>
