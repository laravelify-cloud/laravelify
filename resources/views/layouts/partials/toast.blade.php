<style>
    .toast-stack {
        position: fixed;
        right: 1rem;
        bottom: 1rem;
        z-index: 100;
        display: flex;
        width: min(26rem, calc(100% - 2rem));
        flex-direction: column-reverse;
        gap: 0.5rem;
        pointer-events: none;
    }

    .toast {
        --toast-tone: var(--color-success);
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        border-radius: 12px;
        border: 1px solid color-mix(in srgb, var(--toast-tone) 25%, var(--color-line));
        background: var(--color-card);
        color: var(--color-ink);
        padding: 0.85rem 4.6rem 0.95rem 0.85rem;
        box-shadow: 0 18px 40px rgb(0 0 0 / 0.32), 0 0 0 1px rgb(0 0 0 / 0.04);
        pointer-events: auto;
    }

    .toast-error { --toast-tone: var(--color-danger); }
    .toast-warning { --toast-tone: var(--color-warning); }

    .toast-mark {
        display: grid;
        width: 2rem;
        height: 2rem;
        flex: none;
        place-items: center;
        border-radius: 999px;
        background: color-mix(in srgb, var(--toast-tone) 15%, transparent);
        color: var(--toast-tone);
    }

    .toast-mark svg { width: 1rem; height: 1rem; }

    .toast-title {
        margin: 0;
        font-size: 0.86rem;
        font-weight: 600;
        line-height: 1.3;
    }

    .toast-message {
        margin: 0.15rem 0 0;
        color: var(--color-ink-dim);
        font-size: 0.82rem;
        line-height: 1.45;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .toast-buttons {
        position: absolute;
        top: 0.55rem;
        right: 0.5rem;
        display: flex;
        gap: 2px;
    }

    .toast-button {
        display: grid;
        width: 1.75rem;
        height: 1.75rem;
        place-items: center;
        border-radius: 6px;
        color: var(--color-muted);
        transition: background 120ms, color 120ms;
    }

    .toast-button:hover {
        background: color-mix(in srgb, var(--color-ink) 7%, transparent);
        color: var(--color-ink);
    }

    .toast-button svg { width: 0.95rem; height: 0.95rem; }
    .toast-button.is-copied { color: var(--color-success); }

    .toast-timer {
        position: absolute;
        left: 0;
        bottom: 0;
        height: 2px;
        background: var(--toast-tone);
        opacity: 0.55;
        transform-origin: left;
    }

    .toast-in { transition: opacity 200ms ease-out, transform 200ms ease-out; }
    .toast-in-from { opacity: 0; transform: translateY(8px) scale(0.98); }
    .toast-in-to { opacity: 1; transform: none; }
    .toast-out { transition: opacity 150ms ease-in, transform 150ms ease-in; }
    .toast-out-from { opacity: 1; transform: none; }
    .toast-out-to { opacity: 0; transform: translateX(12px); }

    @keyframes toast-countdown { from { transform: scaleX(1); } to { transform: scaleX(0); } }
</style>

<script>
    window.toast = function (message, type = 'success') {
        window.dispatchEvent(new CustomEvent('toast-show', {
            detail: { message: String(message ?? ''), type: type || 'success' },
        }));
    };

    window.toastStack = function () {
        return {
            items: [],

            add(detail) {
                const type = ['error', 'warning'].includes(detail.type) ? detail.type : 'success';
                const item = {
                    id: Date.now() + Math.random(),
                    type: type,
                    title: type === 'error' ? 'Something went wrong' : (type === 'warning' ? 'Heads up' : 'Done'),
                    message: detail.message,
                    open: true,
                    copied: false,
                    // Errors stay longer: they are the ones people read twice or copy.
                    duration: type === 'error' ? 9000 : 5000,
                    paused: false,
                    timer: null,
                    left: 0,
                    started: 0,
                };

                this.items.push(item);
                this.items = this.items.slice(-4);
                this.schedule(item, item.duration);
            },

            schedule(item, delay) {
                clearTimeout(item.timer);
                item.left = delay;
                item.started = Date.now();
                item.timer = setTimeout(() => this.close(item), delay);
            },

            // Hovering stops the countdown (and its line), so a toast can be read or copied.
            pause(item) {
                item.paused = true;
                clearTimeout(item.timer);
                item.left = Math.max(800, item.left - (Date.now() - item.started));
            },

            resume(item) {
                item.paused = false;
                this.schedule(item, item.left);
            },

            close(item) {
                item.open = false;
                setTimeout(() => { this.items = this.items.filter((row) => row.id !== item.id); }, 200);
            },

            copy(item) {
                if (! navigator.clipboard) {
                    return;
                }

                navigator.clipboard.writeText(item.message).then(() => {
                    item.copied = true;
                    setTimeout(() => item.copied = false, 1500);
                });
            },
        };
    };
</script>

<ul class="toast-stack" x-data="toastStack()" x-cloak @toast-show.window="add($event.detail)" aria-live="polite">
    <template x-for="item in items" :key="item.id">
        <li
            class="toast"
            :class="'toast-' + item.type"
            :role="item.type === 'error' ? 'alert' : 'status'"
            x-show="item.open"
            @mouseenter="pause(item)"
            @mouseleave="resume(item)"
            x-transition:enter="toast-in"
            x-transition:enter-start="toast-in-from"
            x-transition:enter-end="toast-in-to"
            x-transition:leave="toast-out"
            x-transition:leave-start="toast-out-from"
            x-transition:leave-end="toast-out-to"
        >
            <span class="toast-mark">
                <template x-if="item.type === 'success'"><x-menu-icon name="check" /></template>
                <template x-if="item.type !== 'success'"><x-menu-icon name="danger" /></template>
            </span>
            <div class="min-w-0 flex-1">
                <p class="toast-title" x-text="item.title"></p>
                <p class="toast-message" x-text="item.message"></p>
            </div>
            <div class="toast-buttons">
                <button class="toast-button" :class="item.copied && 'is-copied'" type="button" @click="copy(item)" :title="item.copied ? 'Copied' : 'Copy the message'" :aria-label="item.copied ? 'Copied' : 'Copy the message'">
                    <template x-if="! item.copied"><x-menu-icon name="copy" /></template>
                    <template x-if="item.copied"><x-menu-icon name="check" /></template>
                </button>
                <button class="toast-button" type="button" @click="close(item)" title="Dismiss" aria-label="Dismiss"><x-menu-icon name="close" /></button>
            </div>
            <span class="toast-timer" :style="`width: 100%; animation: toast-countdown ${item.duration}ms linear forwards; animation-play-state: ${item.paused ? 'paused' : 'running'}`"></span>
        </li>
    </template>
</ul>

@php($pendingToast = session('toast'))
@if (is_array($pendingToast))
    <script>
        document.addEventListener('livewire:initialized', () => {
            window.toast(@js($pendingToast['message'] ?? ''), @js($pendingToast['type'] ?? 'success'));
        }, { once: true });
    </script>
@endif
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('toast', (payload) => {
            const data = Array.isArray(payload) ? payload[0] : payload;
            if (!data || !data.message) {
                return;
            }
            window.toast(data.message, data.type || 'success');
        });
    });
</script>
