import '@fontsource-variable/inter';
import '@fontsource-variable/jetbrains-mono';
import './select';

function showToast({ message, tone = 'info' }) {
    const container = document.getElementById('nexora-toasts');
    const template = document.getElementById('nexora-toast-template');
    if (!container || !template || typeof message !== 'string' || !message) return;

    const list = container.querySelector('[data-toast-list]');
    const toast = template.content.firstElementChild.cloneNode(true);
    const text = toast.querySelector('[data-toast-message]');
    toast.dataset.tone = ['success', 'info', 'error'].includes(tone) ? tone : 'info';
    text.setAttribute('role', tone === 'error' ? 'alert' : 'status');

    if (container.showPopover && !container.matches(':popover-open')) container.showPopover();
    list.append(toast);
    requestAnimationFrame(() => { text.textContent = message; });

    let timer;
    const dismiss = () => {
        clearTimeout(timer);
        toast.remove();
        if (!list.children.length && container.hidePopover && container.matches(':popover-open')) container.hidePopover();
    };
    const schedule = () => {
        clearTimeout(timer);
        if (tone !== 'error' && !toast.matches(':hover, :focus-within')) timer = setTimeout(dismiss, 5500);
    };

    toast.querySelector('button').addEventListener('click', dismiss);
    toast.addEventListener('pointerenter', () => clearTimeout(timer));
    toast.addEventListener('pointerleave', schedule);
    toast.addEventListener('focusin', () => clearTimeout(timer));
    toast.addEventListener('focusout', () => requestAnimationFrame(schedule));
    schedule();
}

function hydratePage() {
    window.NexoraTheme.apply();
    document.querySelectorAll('[data-flash-toast]').forEach(element => {
        showToast({ message: element.dataset.flashToast, tone: element.dataset.tone });
        element.remove();
    });
}

function registerRequestRecovery() {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, body, preventDefault }) => {
            if (response.aborted) return;

            const copy = document.querySelector('[data-recovery-copy]')?.dataset;
            if (!copy) return;

            preventDefault();
            const expired = response.status === 419;
            if (expired || (response.ok && body?.trim() === '')) {
                const dialog = document.getElementById('request-recovery');
                if (!dialog || dialog.open) return;

                dialog.querySelector('[data-recovery-message]').textContent = expired
                    ? copy.expired : copy.empty;
                dialog.showModal();
                return;
            }

            showToast({ tone: 'error', message: copy.failed });
        });
    });
}

if (window.Livewire) registerRequestRecovery();
else document.addEventListener('livewire:init', registerRequestRecovery, { once: true });

document.addEventListener('click', event => {
    if (event.target.closest('[data-reload-page]')) window.location.reload();
});
window.addEventListener('nexora-toast', event => showToast(event.detail));
function clearProfileSecrets() {
    document.querySelectorAll('[data-profile-secret]').forEach(element => element.remove());
}

document.addEventListener('livewire:navigating', clearProfileSecrets);
window.addEventListener('pagehide', clearProfileSecrets);
// Livewire replaces the root attributes before swapping the page body.
new MutationObserver(() => window.NexoraTheme.apply()).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme'],
});

document.addEventListener('livewire:navigated', hydratePage);
hydratePage();
