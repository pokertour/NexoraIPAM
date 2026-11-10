const NS = 'http://www.w3.org/2000/svg';

/**
 * Draws the cables between racks of a row as bundles, like a cable tray:
 * one trunk per pair of racks, with a count, running under the rack headers
 * between the gutters of the two racks; each item joins the trunk with a
 * short stub. Tracing a cable or a bundle draws the exact route on top.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('rackRoom', () => ({
        frame: null,
        resize: null,
        mutations: null,
        hotBundle: null,
        details: {},
        groups: {},

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
            this.details = {};
            this.groups = {};

            // Ends of each cable: its item, its rack and the rack's cable gutter.
            const ends = cable => [cable.a, cable.b].map(id => {
                const item = this.$el.querySelector(`[data-item="${id}"]`);
                const frame = item?.closest('.rack-frame');
                const rack = item?.closest('[data-rack]');
                if (!item || !frame || !rack) return null;
                const itemBox = item.getBoundingClientRect();
                return { item: id, rack: rack.dataset.rack, x: itemBox.right - box.left, y: itemBox.top + itemBox.height / 2 - box.top, gutter: frame.getBoundingClientRect().right - box.left };
            });

            // One bundle per pair of racks, so twenty uplinks to the core make one trunk, not twenty lines.
            const bundles = new Map();
            for (const cable of cables) {
                const pair = ends(cable);
                if (pair.includes(null)) continue;
                pair.sort((left, right) => left.gutter - right.gutter);
                const id = pair.map(end => end.rack).join('-');
                if (!bundles.has(id)) bundles.set(id, { id, left: pair[0].rack, right: pair[1].rack, span: pair[1].gutter - pair[0].gutter, cables: [] });
                bundles.get(id).cables.push({ ...cable, ends: pair });
            }
            const list = [...bundles.values()].sort((left, right) => left.span - right.span);

            // Lanes: one height per bundle in the tray, one column per bundle in each rack gutter.
            const columns = {};
            list.forEach((bundle, index) => {
                bundle.lane = index;
                for (const rack of [bundle.left, bundle.right]) {
                    columns[rack] = (columns[rack] ?? -1) + 1;
                    bundle[rack === bundle.left ? 'leftColumn' : 'rightColumn'] = columns[rack];
                }
            });
            const trayHeight = Math.max(52, 16 + list.length * 7);
            if (this.$el.style.getPropertyValue('--tray') !== `${trayHeight}px`) {
                this.$el.style.setProperty('--tray', `${trayHeight}px`);
                return;
            }
            const bodies = [...this.$el.querySelectorAll('.rack-body')].map(body => body.getBoundingClientRect().top - box.top);
            const trayTop = (bodies.length ? Math.max(...bodies) : 0) + 8;
            const radius = 5;
            const column = (gutter, index) => gutter + 7 + (index % 8) * 6;

            for (const bundle of list) {
                const tray = trayTop + bundle.lane * 7;
                const [leftEnds, rightEnds] = [0, 1].map(side => bundle.cables.map(cable => cable.ends[side]));
                const leftX = column(leftEnds[0].gutter, bundle.leftColumn);
                const rightX = column(rightEnds[0].gutter, bundle.rightColumn);
                const leftBottom = Math.max(...leftEnds.map(end => end.y));
                const rightBottom = Math.max(...rightEnds.map(end => end.y));
                const sources = [...new Set(bundle.cables.map(cable => cable.source))];
                const group = document.createElementNS(NS, 'g');
                group.dataset.bundle = bundle.id;
                group.dataset.source = sources.length === 1 ? sources[0] : 'mixed';

                const trunk = [
                    `M${leftX} ${leftBottom}`, `V${tray + radius}`, `Q${leftX} ${tray} ${leftX + radius} ${tray}`,
                    `H${rightX - radius}`, `Q${rightX} ${tray} ${rightX} ${tray + radius}`, `V${rightBottom}`,
                ].join(' ');
                const stubs = [...leftEnds.map(end => [end, leftX]), ...rightEnds.map(end => [end, rightX])]
                    .map(([end, x]) => `M${end.x} ${end.y} H${x}`).join(' ');
                for (const [d, kind] of [[trunk, 'trunk'], [stubs, 'stub']]) {
                    const path = document.createElementNS(NS, 'path');
                    path.setAttribute('d', d);
                    path.classList.add(`cable-${kind}`);
                    group.append(path);
                }

                const title = document.createElementNS(NS, 'title');
                title.textContent = bundle.cables.map(cable => cable.label).join('\n');
                group.append(title);
                if (bundle.cables.length > 1) {
                    const badge = document.createElementNS(NS, 'g');
                    badge.classList.add('cable-badge');
                    const middle = (leftX + rightX) / 2;
                    const label = String(bundle.cables.length);
                    const pill = document.createElementNS(NS, 'rect');
                    const pillWidth = 14 + label.length * 7;
                    Object.entries({ x: middle - pillWidth / 2, y: tray - 8, width: pillWidth, height: 16, rx: 8 }).forEach(([name, value]) => pill.setAttribute(name, value));
                    const text = document.createElementNS(NS, 'text');
                    Object.entries({ x: middle, y: tray + 4 }).forEach(([name, value]) => text.setAttribute(name, value));
                    text.textContent = label;
                    badge.append(pill, text);
                    group.append(badge);
                }
                group.addEventListener('mouseenter', () => { this.hotBundle = bundle.id; this.highlight(this.hot); });
                group.addEventListener('mouseleave', () => { this.hotBundle = null; this.highlight(this.hot); });
                svg.append(group);
                this.groups[bundle.id] = bundle.cables.map(cable => cable.key);

                // The exact route of each cable, drawn over the bundle when it is traced.
                for (const cable of bundle.cables) {
                    const [left, right] = cable.ends;
                    this.details[cable.key] = {
                        source: cable.source,
                        items: [left.item, right.item],
                        d: [
                            `M${left.x} ${left.y}`, `H${leftX - radius}`, `Q${leftX} ${left.y} ${leftX} ${left.y - radius}`,
                            `V${tray + radius}`, `Q${leftX} ${tray} ${leftX + radius} ${tray}`,
                            `H${rightX - radius}`, `Q${rightX} ${tray} ${rightX} ${tray + radius}`,
                            `V${right.y - radius}`, `Q${rightX} ${right.y} ${rightX - radius} ${right.y}`, `H${right.x}`,
                        ].join(' '),
                    };
                }
            }
            this.highlight(this.hot);
        },

        highlight(key) {
            const svg = this.$el.querySelector('[data-room-cables]');
            if (!svg) return;
            svg.querySelectorAll('.cable-detail').forEach(path => path.remove());
            this.$el.querySelectorAll('.rack-item.is-bundle-hot').forEach(item => item.classList.remove('is-bundle-hot'));

            const keys = this.hotBundle ? (this.groups?.[this.hotBundle] ?? []) : (key && this.details?.[key] ? [key] : []);
            for (const cableKey of keys) {
                const detail = this.details[cableKey];
                const path = document.createElementNS(NS, 'path');
                path.setAttribute('d', detail.d);
                path.classList.add('cable-detail');
                path.dataset.source = detail.source;
                svg.append(path);
                detail.items.forEach(id => this.$el.querySelector(`[data-item="${id}"]`)?.classList.add('is-bundle-hot'));
            }
            this.$el.classList.toggle('is-tracing', keys.length > 0);
        },
    }));
});
