import TomSelect from 'tom-select';

const normalize = (value, multiple) => multiple
    ? (Array.isArray(value) ? value : []).map(String)
    : String(value ?? '');

const same = (left, right) => JSON.stringify(left) === JSON.stringify(right);

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
                control = new TomSelect(select, {
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

                if (model) {
                    control.on('change', value => this.$wire.$set(model, normalize(value, multiple), live !== undefined));
                    this.$watch(() => this.$wire.$get(model), value => {
                        const selected = normalize(value, multiple);
                        if (!same(normalize(control.getValue(), multiple), selected)) control.setValue(selected, true);
                    });
                }
            },
            destroy() {
                if (control && label?.htmlFor === control.focus_node.id) label.htmlFor = control.input.id;
                control?.destroy();
            },
        };
    });
});
