import TomSelect from 'tom-select';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('nexoraSelect', () => {
        let control;
        let label;

        return {
            init() {
                const select = this.$el.querySelector('select');
                const { model, live, noResults } = this.$el.dataset;

                if (model) {
                    select.value = String(this.$wire.$get(model) ?? '');
                }

                label = select.labels[0];
                control = new TomSelect(select, {
                    allowEmptyOption: true,
                    create: false,
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
                    control.on('change', value => this.$wire.$set(model, value, live !== undefined));
                    this.$watch(() => this.$wire.$get(model), value => {
                        const selected = String(value ?? '');
                        if (control.getValue() !== selected) control.setValue(selected, true);
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
