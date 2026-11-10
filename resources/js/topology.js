/**
 * Topology viewer: zoom (buttons, Ctrl + wheel, pinch), pan by dragging,
 * a minimap when zoomed in, search, and a focus that keeps a device and
 * its direct neighbours lit while the rest of the network fades.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('topologyView', ({ width, height, nodes, neighbours }) => ({
        width,
        height,
        nodes,
        neighbours,
        view: { x: 0, y: 0, scale: 1 },
        size: { width: 1, height: 1 },
        focused: null,
        query: '',
        missing: false,
        pointers: new Map(),
        dragged: false,

        init() {
            this.viewport = this.$refs.viewport;
            this.observer = new ResizeObserver(() => {
                const first = this.size.width === 1;
                this.measure();
                first ? this.reset() : this.apply();
            });
            this.observer.observe(this.viewport);
            // A drag must not open the device it ends on.
            this.viewport.addEventListener('click', (event) => {
                if (this.dragged) {
                    event.preventDefault();
                    event.stopPropagation();
                    this.dragged = false;
                }
            }, true);
        },

        destroy() {
            this.observer?.disconnect();
        },

        measure() {
            this.size = { width: Math.max(1, this.viewport.clientWidth), height: Math.max(1, this.viewport.clientHeight) };
        },

        get fitScale() {
            return Math.min(1.25, this.size.width / this.width, this.size.height / this.height);
        },

        get percent() {
            return `${Math.round(this.view.scale * 100)} %`;
        },

        /** The whole network is not on screen: the minimap shows where the view is. */
        get cropped() {
            return this.width * this.view.scale > this.size.width + 2 || this.height * this.view.scale > this.size.height + 2;
        },

        get far() {
            return this.view.scale < 0.6;
        },

        get lens() {
            return {
                x: this.view.x, y: this.view.y,
                width: this.size.width / this.view.scale, height: this.size.height / this.view.scale,
            };
        },

        /** Opens readable: the whole network when it fits at a fair size, otherwise the top of it at 85 %. */
        reset() {
            if (this.fitScale >= 0.7) {
                this.fit();
            } else {
                this.view.scale = 0.85;
                this.view.x = (this.width - this.size.width / this.view.scale) / 2;
                this.view.y = 0;
                this.apply();
            }
        },

        /** The whole network, centred across and from the top, where the Internet and the core are. */
        fit() {
            this.view.scale = this.fitScale;
            this.view.x = (this.width - this.size.width / this.view.scale) / 2;
            this.view.y = this.height * this.view.scale < this.size.height - 24 ? -12 / this.view.scale : 0;
            this.apply();
        },

        actualSize() {
            this.zoomAt(1 / this.view.scale, this.size.width / 2, this.size.height / 2);
        },

        zoom(factor) {
            this.zoomAt(factor, this.size.width / 2, this.size.height / 2);
        },

        /** Zooms around a point of the viewport, which stays under the pointer. */
        zoomAt(factor, clientX, clientY) {
            const scale = Math.min(2.5, Math.max(Math.min(0.15, this.fitScale), this.view.scale * factor));
            const pointX = this.view.x + clientX / this.view.scale;
            const pointY = this.view.y + clientY / this.view.scale;
            this.view.scale = scale;
            this.view.x = pointX - clientX / scale;
            this.view.y = pointY - clientY / scale;
            this.apply();
        },

        centerOn(x, y) {
            this.view.x = x - this.size.width / this.view.scale / 2;
            this.view.y = y - this.size.height / this.view.scale / 2;
            this.apply();
        },

        /** Keeps some of the network on screen, then draws the view. */
        apply() {
            const visibleWidth = this.size.width / this.view.scale;
            const visibleHeight = this.size.height / this.view.scale;
            const marginX = Math.min(visibleWidth, this.width) * 0.5;
            const marginY = Math.min(visibleHeight, this.height) * 0.5;
            this.view.x = Math.min(this.width - marginX, Math.max(marginX - visibleWidth, this.view.x));
            this.view.y = Math.min(this.height - marginY, Math.max(marginY - visibleHeight, this.view.y));
            this.$refs.svg.setAttribute('viewBox', `${this.view.x} ${this.view.y} ${visibleWidth} ${visibleHeight}`);
        },

        wheel(event) {
            if (!event.ctrlKey && !event.metaKey) return;
            event.preventDefault();
            const box = this.viewport.getBoundingClientRect();
            this.zoomAt(Math.exp(-event.deltaY * 0.0025), event.clientX - box.left, event.clientY - box.top);
        },

        down(event) {
            if (event.button !== 0 && event.pointerType === 'mouse') return;
            this.pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
            this.start = { x: event.clientX, y: event.clientY };
            this.dragged = false;
        },

        move(event) {
            const previous = this.pointers.get(event.pointerId);
            if (!previous) return;
            const current = { x: event.clientX, y: event.clientY };
            if (this.pointers.size === 2) {
                const [other] = [...this.pointers].filter(([id]) => id !== event.pointerId).map(([, point]) => point);
                const before = Math.hypot(previous.x - other.x, previous.y - other.y);
                const after = Math.hypot(current.x - other.x, current.y - other.y);
                const box = this.viewport.getBoundingClientRect();
                if (before > 0) this.zoomAt(after / before, (current.x + other.x) / 2 - box.left, (current.y + other.y) / 2 - box.top);
                this.dragged = true;
            } else {
                if (!this.dragged && Math.hypot(current.x - this.start.x, current.y - this.start.y) < 5) return;
                if (!this.dragged) this.viewport.setPointerCapture?.(event.pointerId);
                this.dragged = true;
                this.view.x -= (current.x - previous.x) / this.view.scale;
                this.view.y -= (current.y - previous.y) / this.view.scale;
                this.apply();
            }
            this.pointers.set(event.pointerId, current);
        },

        up(event) {
            this.pointers.delete(event.pointerId);
        },

        /** Moves the view to the point of the minimap that was clicked. */
        minimap(event) {
            // The minimap keeps the proportions of the network: undo its letterboxing.
            const box = event.currentTarget.getBoundingClientRect();
            const ratio = Math.min(box.width / this.width, box.height / this.height);
            const left = box.left + (box.width - this.width * ratio) / 2;
            const top = box.top + (box.height - this.height * ratio) / 2;
            this.centerOn((event.clientX - left) / ratio, (event.clientY - top) / ratio);
        },

        /** First click lights the device and its neighbours; a second one opens it. */
        select(event, id) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) return;
            event.preventDefault();
            if (this.focused === id) {
                this.open();

                return;
            }
            this.focused = id;
        },

        clear() {
            this.focused = null;
            this.missing = false;
        },

        open() {
            const node = this.node(this.focused);
            if (node) window.Livewire.navigate(node.url);
        },

        node(id) {
            return this.nodes.find((node) => node.id === id) ?? null;
        },

        lit(id) {
            return this.focused === null || this.focused === id || (this.neighbours[this.focused] ?? []).includes(id);
        },

        litLink(a, b) {
            return this.focused === null || this.focused === a || this.focused === b;
        },

        find() {
            const query = this.query.trim().toLowerCase();
            if (query === '') {
                this.clear();

                return;
            }
            const node = this.nodes.find((candidate) => candidate.name.toLowerCase() === query)
                ?? this.nodes.find((candidate) => candidate.name.toLowerCase().includes(query) || candidate.host.toLowerCase().includes(query));
            this.missing = !node;
            if (!node) return;
            this.focused = node.id;
            if (this.view.scale < 0.85) this.view.scale = 0.85;
            this.centerOn(node.x, node.y);
        },
    }));
});
