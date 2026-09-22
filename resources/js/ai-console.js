// The console's one piece of client code: copy buttons over the deployment blocks and
// reset-to-default over the live settings. Both are progressive enhancement — the page is
// fully usable with scripting off, this only adds the copy and reset conveniences.

document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.copyTarget);
        if (target === null) {
            return;
        }

        navigator.clipboard.writeText(target.textContent).then(() => {
            const original = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(() => {
                button.textContent = original;
            }, 1500);
        });
    });
});

document.querySelectorAll('[data-reset-for]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.resetFor);
        if (input === null) {
            return;
        }

        if (input.type === 'checkbox') {
            input.checked = input.dataset.default === '1';
            return;
        }

        input.value = input.dataset.default;
    });
});
