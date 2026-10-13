<script>
    /*
     * Live monitoring. These poll small JSON endpoints every 2 seconds and update
     * only their own numbers and charts, so the page never re-renders. Polling stops
     * while the browser tab is hidden or the panel is not on screen.
     */
    window.monitorFormat = {
        ms: function (value) {
            if (value === null || value === undefined) return '—';
            return value >= 1000 ? (value / 1000).toFixed(value >= 10000 ? 0 : 2).replace(/\.?0+$/, '') + 's' : Math.round(value) + 'ms';
        },
        bytes: function (value) {
            if (value === null || value === undefined) return '—';
            var units = ['B', 'KB', 'MB', 'GB', 'TB'];
            var index = 0;
            while (value >= 1024 && index < units.length - 1) { value /= 1024; index++; }
            return (index === 0 ? Math.round(value) : value.toFixed(1)) + ' ' + units[index];
        },
        number: function (value) {
            if (value === null || value === undefined) return '—';
            return value >= 1000000 ? (value / 1000000).toFixed(1) + 'M' : value >= 1000 ? (value / 1000).toFixed(1) + 'k' : String(value);
        },
        ago: function (seconds) {
            return seconds < 5 ? 'now' : seconds < 60 ? seconds + 's ago' : Math.floor(seconds / 60) + 'm ago';
        },
    };

    /**
     * SVG path for a rolling series, right-aligned so new points enter from the right.
     */
    window.monitorPath = function (values, slots, max, width, height) {
        var path = '';
        var open = false;
        var step = width / Math.max(1, slots - 1);
        var offset = slots - values.length;

        values.forEach(function (value, index) {
            if (value === null || value === undefined) { open = false; return; }
            var x = ((offset + index) * step).toFixed(1);
            var y = (height - Math.min(1, value / Math.max(max, 0.0001)) * (height - 4) - 2).toFixed(1);
            path += (open ? ' L' : ' M') + x + ',' + y;
            open = true;
        });

        return path.trim();
    };

    function monitorVisible(el) {
        return !document.hidden && el.offsetParent !== null;
    }

    /**
     * A website's requests as they happen: last-60-seconds counters, a per-second
     * bar chart, and a streaming feed.
     */
    window.monitorLive = function (url, feedSize) {
        return {
            url: url,
            feedSize: feedSize || 12,
            offset: -1,
            tracking: true,
            paused: false,
            failed: false,
            busy: false,
            now: Math.floor(Date.now() / 1000),
            slow: 2000,
            seconds: {},
            feed: [],
            nextId: 1,
            timer: null,

            init: function () {
                var self = this;
                this.tick();
                this.timer = setInterval(function () { self.tick(); }, 2000);
            },

            destroy: function () {
                clearInterval(this.timer);
            },

            tick: function () {
                var self = this;

                if (this.busy || this.paused || !monitorVisible(this.$el)) return;
                this.busy = true;

                fetch(this.url + '?offset=' + this.offset, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { if (!response.ok) throw new Error(response.status); return response.json(); })
                    .then(function (data) {
                        self.tracking = data.tracking;
                        self.offset = data.offset;
                        self.now = data.now || Math.floor(Date.now() / 1000);
                        self.slow = data.slow || 2000;
                        self.failed = false;

                        var rows = data.requests || [];

                        rows.forEach(function (row) {
                            var second = self.seconds[row.t] || (self.seconds[row.t] = { n: 0, c: 0, s: 0, d: 0 });
                            second.n++;
                            second.d += row.d;
                            if (row.s >= 500) second.s++; else if (row.s >= 400) second.c++;
                        });

                        Object.keys(self.seconds).forEach(function (key) {
                            if (key < self.now - 120) delete self.seconds[key];
                        });

                        if (rows.length) {
                            var fresh = rows.slice(-self.feedSize).reverse().map(function (row) {
                                row.id = self.nextId++;
                                return row;
                            });
                            self.feed = fresh.concat(self.feed).slice(0, self.feedSize);
                        }
                    })
                    .catch(function () { self.failed = true; })
                    .finally(function () { self.busy = false; });
            },

            get bars() {
                var bars = [];
                var max = 1;

                for (var t = this.now - 59; t <= this.now; t++) {
                    var second = this.seconds[t] || { n: 0, c: 0, s: 0 };
                    max = Math.max(max, second.n);
                    bars.push({ t: t, n: second.n, c: second.c, s: second.s });
                }

                return bars.map(function (bar) {
                    bar.h = bar.n ? Math.max(6, bar.n / max * 100) : 0;
                    return bar;
                });
            },

            get stats() {
                var total = 0, client = 0, server = 0, duration = 0;

                for (var t = this.now - 59; t <= this.now; t++) {
                    var second = this.seconds[t];
                    if (!second) continue;
                    total += second.n; client += second.c; server += second.s; duration += second.d;
                }

                return { total: total, client: client, server: server, avg: total ? Math.round(duration / total) : null, rps: (total / 60).toFixed(total >= 60 ? 1 : 2) };
            },

            codeClass: function (status) {
                return 'is-' + Math.max(1, Math.min(5, Math.floor(status / 100))) + 'xx';
            },

            riskClass: function (risk) {
                return risk >= 85 ? 'is-critical' : risk >= 65 ? 'is-high' : risk >= 40 ? 'is-medium' : '';
            },

            time: function (timestamp) {
                var settings = window.laravelifyTime || {};
                try {
                    return new Date(timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: !!settings.hour12, timeZone: settings.timeZone });
                } catch (error) {
                    return new Date(timestamp * 1000).toLocaleTimeString([], { hour12: !!settings.hour12 });
                }
            },
        };
    };

    /**
     * The server right now: CPU, memory, load, and network every 2 seconds with
     * 3-minute rolling charts, and the busiest processes every 6 seconds.
     */
    window.serverLive = function (url) {
        return {
            url: url,
            slots: 90,
            points: [],
            current: null,
            processes: [],
            failed: false,
            busy: false,
            ticks: 0,
            timer: null,

            init: function () {
                var self = this;
                this.tick();
                this.timer = setInterval(function () { self.tick(); }, 2000);
            },

            destroy: function () {
                clearInterval(this.timer);
            },

            tick: function () {
                var self = this;

                if (this.busy || !monitorVisible(this.$el)) return;
                this.busy = true;
                var withProcesses = this.ticks % 3 === 0;
                this.ticks++;

                fetch(this.url + (withProcesses ? '?processes=1' : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                    .then(function (response) { if (!response.ok) throw new Error(response.status); return response.json(); })
                    .then(function (data) {
                        self.failed = false;
                        if (!data.available) { self.current = false; return; }
                        self.current = data;
                        self.points.push({ cpu: data.cpu, memory: data.memory, load: data.load ? data.load[0] : null, in: data.net_in, out: data.net_out });
                        if (self.points.length > self.slots) self.points.shift();
                        if (data.processes) self.processes = data.processes;
                    })
                    .catch(function () { self.failed = true; })
                    .finally(function () { self.busy = false; });
            },

            series: function (key) {
                return this.points.map(function (point) { return point[key]; });
            },

            path: function (key, max) {
                var values = this.series(key);
                if (max === undefined) {
                    max = Math.max.apply(null, values.concat([0]).map(function (value) { return value || 0; })) * 1.2 || 1;
                }
                // Fill one minute first, then widen to the full three minutes.
                return window.monitorPath(values, Math.max(30, values.length), max, 300, 64);
            },

            netMax: function () {
                var values = this.series('in').concat(this.series('out')).map(function (value) { return value || 0; });
                return Math.max.apply(null, values.concat([1024])) * 1.2;
            },

            tone: function (value) {
                return value === null || value === undefined ? '' : value >= 90 ? 'color: var(--mon-5xx)' : value >= 75 ? 'color: var(--mon-4xx)' : '';
            },
        };
    };
</script>
