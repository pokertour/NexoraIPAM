/**
 * Patch plan: VLAN highlighting, and a card that shows everything about a
 * port on hover or keyboard focus — VLAN, state, outlet and its description.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('patchPlan', () => ({
        vlan: null,
        card: null,
        cardStyle: '',

        show(element) {
            try {
                this.card = JSON.parse(element.dataset.card);
            } catch {
                this.card = null;

                return;
            }
            const box = (element.querySelector('.patch-jack') ?? element).getBoundingClientRect();
            const width = 260;
            const left = Math.min(window.innerWidth - width - 12, Math.max(12, box.left + box.width / 2 - width / 2));
            const below = box.bottom + 10;
            // Above the port when the card would leave the screen at the bottom.
            this.cardStyle = below + 160 > window.innerHeight
                ? `left: ${left}px; bottom: ${window.innerHeight - box.top + 10}px; width: ${width}px`
                : `left: ${left}px; top: ${below}px; width: ${width}px`;
        },

        hide() {
            this.card = null;
        },
    }));
});
