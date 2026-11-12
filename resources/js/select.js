import TomSelect from 'tom-select';

const normalize = (value, multiple) => multiple
    ? (Array.isArray(value) ? value : []).map(String)
    : String(value ?? '');

const same = (left, right) => JSON.stringify(left) === JSON.stringify(right);

/**
 * Places the open list under the field, or above it when the screen has more
 * room there, and keeps it within the viewport.
 */
const place = (control) => {
    const dropdown = control.dropdown;
    const box = control.control.getBoundingClientRect();
    const gap = 6;
    const margin = 12;
    const below = window.innerHeight - box.bottom - gap - margin;
    const above = box.top - gap - margin;
    const upwards = below < 220 && above > below;
    dropdown.style.setProperty('--dropdown-max', `${Math.max(120, Math.min(256, (upwards ? above : below) - 12))}px`);
    dropdown.style.left = `${box.left}px`;
    dropdown.style.width = `${box.width}px`;
    dropdown.style.top = upwards ? 'auto' : `${box.bottom + gap}px`;
    dropdown.style.bottom = upwards ? `${window.innerHeight - box.top + gap}px` : 'auto';
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nexoraSelect', () => {
        let control;
        let label;

        return {
            init() {
                const select = this.$el.querySelector('select');
                const { model, live, noResults } = this.$el.dataset;
                const multiple = select.multiple;

                if (model) {
                    const value = normalize(this.$wire.$get(model), multiple);
                    if (multiple) {
                        Array.from(select.options).forEach(option => { option.selected = value.includes(option.value); });
                    } else {
                        select.value = value;
                    }
                }

                label = select.labels[0];
                // The list is drawn outside the field, fixed to the screen, so a
                // modal or a card that hides its overflow never cuts it off.
                const parent = this.$el.closest('dialog') ?? document.body;
                control = new TomSelect(select, {
                    dropdownParent: parent,
                    dropdownClass: 'ts-dropdown select-dropdown',
                    allowEmptyOption: !multiple,
                    create: false,
                    ...(multiple ? { plugins: ['remove_button'], hidePlaceholder: true } : {}),
                    render: {
                        no_results: () => {
                            const message = document.createElement('div');
                            message.className = 'no-results';
                            message.textContent = noResults;
                            return message;
                        },
                    },
                });

                control.positionDropdown = () => place(control);
                this.reposition = () => { if (control.isOpen) place(control); };
                window.addEventListener('resize', this.reposition);
                window.addEventListener('scroll', this.reposition, true);

                if (model) {
                    control.on('change', value => this.$wire.$set(model, normalize(value, multiple), live !== undefined));
                    this.$watch(() => this.$wire.$get(model), value => {
                        const selected = normalize(value, multiple);
                        if (!same(normalize(control.getValue(), multiple), selected)) control.setValue(selected, true);
                    });
                }
            },
            destroy() {
                window.removeEventListener('resize', this.reposition);
                window.removeEventListener('scroll', this.reposition, true);
                if (control && label?.htmlFor === control.focus_node.id) label.htmlFor = control.input.id;
                control?.destroy();
            },
        };
    });
});
