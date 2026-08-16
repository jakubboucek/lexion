// Entry point of the spisovka island. The mount point carries three separate
// pieces of data: the endpoints it talks to, the visitor's starting values,
// and the court codelist - kept apart because they have nothing to do with
// each other (config, state, codelist).

import {createApp} from 'vue';
import SpisovkaForm from './SpisovkaForm.vue';

export function init() {
    const root = document.getElementById('spisovka-app');
    if (!root?.dataset.config) {
        return;
    }
    createApp(SpisovkaForm, {
        config: JSON.parse(root.dataset.config),
        state: JSON.parse(root.dataset.state),
        courts: JSON.parse(root.dataset.courts),
    }).mount(root);
}
