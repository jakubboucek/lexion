// Copy-to-clipboard for [data-copy] buttons (spisovka chips, @spisovka.latte).
// Event delegation - works for any button present now or added later. On
// success the copy icon briefly swaps to the check icon (the two spans are
// pre-rendered in the button, JS only toggles `hidden`).

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button || !navigator.clipboard) {
        return;
    }
    navigator.clipboard.writeText(button.dataset.copy).then(() => {
        const [copyIcon, checkIcon] = button.querySelectorAll('span');
        if (!copyIcon || !checkIcon) {
            return;
        }
        copyIcon.classList.add('hidden');
        checkIcon.classList.remove('hidden');
        setTimeout(() => {
            copyIcon.classList.remove('hidden');
            checkIcon.classList.add('hidden');
        }, 1500);
    });
});
