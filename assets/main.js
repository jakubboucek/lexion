import './css/app.css';
import './strip-tracking-url-params.js';
import './copy-button.js';
import './dialog.js';

// The spisovka tool is a Vue island in its own chunk, loaded only on pages
// that carry it - the rest of the app pays nothing for it.
if (document.getElementById('spisovka-app')) {
    import('./spisovka/full.js').then((island) => island.init());
}

// Initialize Nette Forms on page load
import netteForms from 'nette-forms';

netteForms.initOnLoad();
