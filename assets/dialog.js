// Opens native <dialog> modals (daisyUI .modal) from [data-dialog-open]
// triggers holding the dialog's id. Event delegation - works for any trigger
// present now or added later. Closing is native: forms with method="dialog"
// and the Esc key need no JS.

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-dialog-open]');
    if (!trigger) {
        return;
    }
    const dialog = document.getElementById(trigger.dataset.dialogOpen);
    if (dialog instanceof HTMLDialogElement) {
        dialog.showModal();
    }
});
