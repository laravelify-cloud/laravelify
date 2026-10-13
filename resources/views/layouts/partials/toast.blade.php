<style>
    .toast-stack {
        position: fixed;
        right: 1rem;
        bottom: 1rem;
        z-index: 80;
        display: flex;
        width: min(24rem, calc(100% - 2rem));
        flex-direction: column-reverse;
        gap: 0.5rem;
    }

    .toast {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        border-radius: 10px;
        border: 1px solid var(--color-line);
        background: var(--color-card);
        color: var(--color-ink);
        padding: 0.8rem 2.2rem 0.8rem 0.8rem;
        box-shadow: 0 12px 30px rgb(0 0 0 / 0.28);
    }

    .toast-mark {
        display: grid;
        width: 1.75rem;
        height: 1.75rem;
        flex: none;
        place-items: center;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 700;
    }

    .toast-success .toast-mark {
        background: color-mix(in srgb, var(--color-accent) 18%, transparent);
        color: var(--color-accent);
    }

    .toast-error .toast-mark {
        background: color-mix(in srgb, var(--color-danger) 18%, transparent);
        color: var(--color-danger);
    }

    .toast-warning .toast-mark {
        background: color-mix(in srgb, #f5b942 18%, transparent);
        color: #f5b942;
    }

    .toast p {
        margin: 0;
        font-size: 0.84rem;
        line-height: 1.35;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .toast-close {
        position: absolute;
        top: 0.35rem;
        right: 0.35rem;
        border: 0;
        background: transparent;
        color: var(--color-muted);
        cursor: pointer;
        font-size: 1rem;
        line-height: 1;
    }

    .toast {
        position: relative;
    }

    .toast-in { transition: opacity 200ms ease-out, transform 200ms ease-out; }
    .toast-in-from { opacity: 0; transform: translateY(8px); }
    .toast-in-to { opacity: 1; transform: none; }
    .toast-out { transition: opacity 150ms ease-in, transform 150ms ease-in; }
    .toast-out-from { opacity: 1; transform: none; }
    .toast-out-to { opacity: 0; transform: translateY(4px); }
</style>

<script>
    window.toast = function (message, type = 'success') {
        window.dispatchEvent(new CustomEvent('toast-show', {
            detail: { message: String(message ?? ''), type: type || 'success' },
        }));
    };
</script>

<ul class="toast-stack" x-data="{ items: [] }" x-cloak @toast-show.window="
    const id = Date.now() + Math.random();
    items.push({ id, message: $event.detail.message, type: $event.detail.type, open: true });
    setTimeout(() => {
        const row = items.find((item) => item.id === id);
        if (row) row.open = false;
        setTimeout(() => { items = items.filter((item) => item.id !== id); }, 200);
    }, 4200);
">
    <template x-for="item in items" :key="item.id">
        <li class="toast" :class="item.type === 'error' ? 'toast-error' : (item.type === 'warning' ? 'toast-warning' : 'toast-success')" x-show="item.open" x-transition:enter="toast-in" x-transition:enter-start="toast-in-from" x-transition:enter-end="toast-in-to" x-transition:leave="toast-out" x-transition:leave-start="toast-out-from" x-transition:leave-end="toast-out-to">
            <span class="toast-mark" x-text="item.type === 'error' ? '!' : (item.type === 'warning' ? '!' : '✓')"></span>
            <p x-text="item.message"></p>
            <button class="toast-close" type="button" @click="item.open = false" aria-label="Dismiss">×</button>
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
