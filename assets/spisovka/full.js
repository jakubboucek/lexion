// Variant B entry: the whole tool is the island. The mount point carries the
// starting data (court codelist + prefill) the server put there.

import {createApp} from 'vue';
import SpisovkaForm from './SpisovkaForm.vue';

export function init() {
    const root = document.getElementById('spisovka-app');
    if (!root?.dataset.state) {
        return;
    }
    createApp(SpisovkaForm, {state: JSON.parse(root.dataset.state)}).mount(root);
}
