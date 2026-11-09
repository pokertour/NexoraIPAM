const NS = 'http://www.w3.org/2000/svg';

/**
 * Draws the cables between racks of a row: each one leaves its item on the
 * right, rises in the rack's cable gutter to a tray that runs under the
 * rack headers, along the row, and comes down the gutter of the other rack.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rackRoom', () => ({
        frame: null,
        resize: null,
        mutations: null,

        init() {
            const schedule = () => {
                cancelAnimationFrame(this.frame);
                this.frame = requestAnimationFrame(() => this.draw());
            };
            this.resize = new ResizeObserver(schedule);
            this.resize.observe(this.$el);
            // Ignore the changes made by draw() itself in the cable layer.
            this.mutations = new MutationObserver(records => {
                if (records.some(record => !record.target.closest?.('[data-room-cables]'))) schedule();
            });
            this.mutations.observe(this.$el, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-cables', 'style'] });
            this.$watch('hot', key => this.highlight(key));
            schedule();
        },

        destroy() {
            cancelAnimationFrame(this.frame);
            this.resize?.disconnect();
            this.mutations?.disconnect();
        },

        draw() {
            const svg = this.$el.querySelector('[data-room-cables]');
            if (!svg) return;
            let cables = [];
            try { cables = JSON.parse(this.$el.dataset.cables || '[]'); } catch { cables = []; }

            const box = this.$el.getBoundingClientRect();
            const width = this.$el.scrollWidth;
            const height = this.$el.scrollHeight;
            svg.setAttribute('width', width);
            svg.setAttribute('height', height);
            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            svg.replaceChildren();

            const point = (element, frame) => {
                const item = element.getBoundingClientRect();
                const rack = frame.getBoundingClientRect();
                return {
                    item: item.right - box.left,
                    gutter: rack.right - box.left,
                    y: item.top + item.height / 2 - box.top,
                };
            };

            // The tray sits in the free band between the rack headers and the frames.
            const bodies = [...this.$el.querySelectorAll('.rack-body')].map(body => body.getBoundingClientRect().top - box.top);
            const trayTop = (bodies.length ? Math.max(...bodies) : 0) + 8;

            cables.forEach((cable, index) => {
                const a = this.$el.querySelector(`[data-item="${cable.a}"]`);
                const b = this.$el.querySelector(`[data-item="${cable.b}"]`);
                if (!a || !b) return;
                const ends = [point(a, a.closest('.rack-frame')), point(b, b.closest('.rack-frame'))]
                    .sort((left, right) => left.gutter - right.gutter);
                const lane = index % 8;
                const tray = trayTop + lane * 5;
                const radius = 6;
                const [first, second] = ends.map((end, side) => ({ ...end, x: end.gutter + 8 + ((lane + side) % 4) * 9 }));

                const d = [
                    `M${first.item} ${first.y}`,
                    `H${first.x - radius}`, `Q${first.x} ${first.y} ${first.x} ${first.y - radius}`,
                    `V${tray + radius}`, `Q${first.x} ${tray} ${first.x + radius} ${tray}`,
                    `H${second.x - radius}`, `Q${second.x} ${tray} ${second.x} ${tray + radius}`,
                    `V${second.y - radius}`, `Q${second.x} ${second.y} ${second.x - radius} ${second.y}`,
                    `H${second.item}`,
                ].join(' ');

                const path = document.createElementNS(NS, 'path');
                path.setAttribute('d', d);
                path.dataset.key = cable.key;
                path.dataset.source = cable.source;
                if (cable.confirmed) path.dataset.confirmed = '';
                const title = document.createElementNS(NS, 'title');
                title.textContent = cable.label;
                path.append(title);
                path.addEventListener('mouseenter', () => { this.hot = cable.key; });
                path.addEventListener('mouseleave', () => { this.hot = null; });
                svg.append(path);
            });
            this.highlight(this.hot);
        },

        highlight(key) {
            this.$el.querySelectorAll('[data-room-cables] path').forEach(path => {
                path.classList.toggle('is-hot', key !== null && path.dataset.key === key);
            });
            this.$el.classList.toggle('is-tracing', key !== null && Boolean(this.$el.querySelector(`[data-room-cables] path[data-key="${key}"]`)));
        },
    }));
});
