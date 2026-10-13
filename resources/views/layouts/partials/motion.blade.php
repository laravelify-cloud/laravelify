<div class="nav-progress" id="laravelify-progress" hidden>
    <span></span>
</div>
<script>
    (function () {
        var bar = document.getElementById('laravelify-progress');
        if (!bar) {
            return;
        }

        var pending = 0;
        var showTimer;

        function show() {
            pending += 1;
            window.clearTimeout(showTimer);
            showTimer = window.setTimeout(function () {
                if (pending > 0) {
                    bar.hidden = false;
                }
            }, 280);
        }

        function hide() {
            pending = Math.max(0, pending - 1);
            if (pending === 0) {
                window.clearTimeout(showTimer);
                bar.hidden = true;
            }
        }

        document.addEventListener('livewire:init', function () {
            Livewire.hook('commit', function (payload) {
                show();
                var done = function () { hide(); };
                payload.succeed(done);
                payload.fail(done);
            });
        });

        document.addEventListener('livewire:navigating', show);
        document.addEventListener('livewire:navigated', function () {
            pending = 0;
            window.clearTimeout(showTimer);
            bar.hidden = true;
        });

        window.setInterval(function () {
            if (bar.hidden) {
                pending = 0;
            }
        }, 8000);
    })();

    window.websitePage = function () {
        var allowed = ['general', 'environment', 'runtime', 'nginx', 'security', 'processes', 'deployments', 'monitoring', 'logs', 'health', 'terminal', 'files', 'danger'];
        var params = new URLSearchParams(window.location.search);
        var requested = params.get('tab');
        var tab = allowed.indexOf(requested) === -1 ? 'general' : requested;

        return {
            tab: tab,
            linksOpen: false,
            groups: { settings: true, observe: true, operations: true },
            dirty: window.__laravelifyUnsaved === true,
            setTab: function (next) {
                this.tab = next;
                var url = new URL(window.location.href);
                url.searchParams.set('tab', next);
                history.replaceState(history.state, '', url);
            },
            markDirty: function (event) {
                var field = event.target;
                if (!field || !field.closest || !field.closest('form')) {
                    return;
                }
                this.dirty = true;
                window.__laravelifyUnsaved = true;
            },
            clearDirty: function () {
                this.dirty = false;
                window.__laravelifyUnsaved = false;
            },
            init: function () {
                this.setTab(this.tab);

                if (window.__laravelifyUnsavedBound) {
                    return;
                }

                window.__laravelifyUnsavedBound = true;
                window.addEventListener('beforeunload', function (event) {
                    if (!window.__laravelifyUnsaved) {
                        return;
                    }
                    event.preventDefault();
                    event.returnValue = 'You have unsaved changes. Leave this page?';
                });
                document.addEventListener('livewire:navigate', function (event) {
                    if (!window.__laravelifyUnsaved) {
                        return;
                    }
                    if (!window.confirm('You have unsaved changes. Leave this page?')) {
                        event.preventDefault();
                    } else {
                        window.__laravelifyUnsaved = false;
                    }
                });
            },
        };
    };

    document.addEventListener('pointerdown', function (event) {
        var target = event.target.closest('button, .btn, .choice');
        if (event.button !== 0 || !target || target.disabled || target.getAttribute('aria-disabled') === 'true') {
            return;
        }

        var rect = target.getBoundingClientRect();
        var size = Math.max(rect.width, rect.height);
        var ripple = document.createElement('span');
        ripple.className = 'ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (event.clientX - rect.left - target.clientLeft - size / 2) + 'px';
        ripple.style.top = (event.clientY - rect.top - target.clientTop - size / 2) + 'px';
        target.appendChild(ripple);
        ripple.addEventListener('animationend', function () {
            ripple.remove();
        });
    });
</script>
