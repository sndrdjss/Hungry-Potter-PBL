'use strict';

document.querySelectorAll('[data-payment-entry]').forEach((form) => {
    const method = form.querySelector('[data-payment-method]');
    const amount = form.querySelector('[data-payment-amount]');
    const tendered = form.querySelector('[data-payment-tendered]');
    const reference = form.querySelector('[data-payment-reference]');
    const change = form.querySelector('[data-payment-change]');

    function update() {
        const cash = method?.value === 'cash';
        if (tendered) {
            tendered.disabled = !cash;
            tendered.required = cash;
        }
        if (reference) {
            reference.disabled = cash;
            reference.required = !cash;
        }
        if (change && amount && tendered) {
            const difference = Math.max(0, Math.round((Number(tendered.value) - Number(amount.value)) * 100) / 100);
            change.textContent = cash ? `Change: ₱${difference.toFixed(2)}` : 'Enter the successful external payment reference.';
        }
    }

    method?.addEventListener('change', update);
    amount?.addEventListener('input', update);
    tendered?.addEventListener('input', update);
    update();
});
