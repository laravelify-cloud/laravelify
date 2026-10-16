/*
 * The Database tool's rows grid: an Alpine component that loads AG Grid (its own file) only when a
 * table page opens, and takes each page of rows the server renders.
 */
window.dbGrid = function () {
    return {
        update: null,
        pending: null,

        async init() {
            const source = document.querySelector('[data-db-grid-payload]');
            this.pending = source ? JSON.parse(source.dataset.dbGridPayload) : null;

            const { mountGrid } = await import('./db-grid');

            if (this.pending) {
                this.update = mountGrid(this.$refs.grid, this.$wire, this.pending);
                this.pending = null;
            }
        },

        receive(payload) {
            if (this.update) {
                this.update(payload);
            } else {
                this.pending = payload;
            }
        },
    };
};
