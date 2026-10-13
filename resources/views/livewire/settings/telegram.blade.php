<div class="flex flex-col gap-6">
    <x-page-header title="Telegram bot" summary="Get a message in Telegram when a deploy starts, finishes, or fails. Works with private chats, groups, and forum topics.">
        <x-slot:actions>
            <button class="btn" type="button" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                <x-menu-icon name="rocket" />
                <span wire:loading.remove wire:target="sendTest">Send test message</span>
                <span wire:loading wire:target="sendTest">Sending…</span>
            </button>
            <button class="btn btn-primary" type="submit" form="telegram-settings">Save</button>
        </x-slot:actions>
    </x-page-header>

    <form id="telegram-settings" class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]" wire:submit="save">
        <div class="flex min-w-0 flex-col gap-6">
            <x-layer-card title="Bot" helper="Create a bot with @BotFather and paste its token. It is stored encrypted.">
                <div class="flex flex-col gap-4">
                    <label class="flex items-center justify-between gap-4 rounded-lg border border-line bg-recessed px-3 py-2.5">
                        <span>
                            <span class="block text-[13.5px] font-medium">Send notifications</span>
                            <span class="block text-[12px] text-muted">Turn every Telegram message on or off.</span>
                        </span>
                        <input type="checkbox" wire:model="enabled">
                    </label>

                    <x-secret-input label="Bot token" model="token" secret="telegram_bot_token" :saved="$hasToken" :placeholder="$hasToken ? null : '123456789:AAH...'" />
                    @if ($hasToken)
                        <div class="flex items-center justify-between gap-3 text-[13px]">
                            <span class="status-badge" style="--status-color: var(--color-success)">Token saved</span>
                            <x-confirm-delete
                                title="Remove the bot token?"
                                name="telegram-token"
                                action="forgetToken"
                                button="Remove token"
                                :actions="['The bot token will be removed and notifications turned off.']"
                            />
                        </div>
                    @endif
                </div>
            </x-layer-card>

            <x-layer-card title="Destination" helper="Where messages go. Add the bot to your group first.">
                <x-slot:actions>
                    <button class="btn" type="button" wire:click="detect" wire:loading.attr="disabled" wire:target="detect">
                        <x-menu-icon name="search" />
                        <span wire:loading.remove wire:target="detect">Find chats</span>
                        <span wire:loading wire:target="detect">Looking…</span>
                    </button>
                </x-slot:actions>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="field">
                        <span>Chat ID</span>
                        <input class="input font-mono" wire:model="chatId" autocomplete="off" placeholder="-1001234567890">
                        <small class="text-muted">Groups and channels start with -100.</small>
                        @error('chatId') <small class="error">{{ $message }}</small> @enderror
                    </label>
                    <label class="field">
                        <span>Thread ID <span class="font-normal text-muted">(optional)</span></span>
                        <input class="input font-mono" wire:model="threadId" inputmode="numeric" autocomplete="off" placeholder="42">
                        <small class="text-muted">For forum groups: the topic to post in.</small>
                        @error('threadId') <small class="error">{{ $message }}</small> @enderror
                    </label>
                </div>

                @if ($detected !== [])
                    <div class="mt-4 overflow-hidden rounded-lg border border-line">
                        @foreach ($detected as $index => $chat)
                            <div class="flex items-center justify-between gap-3 border-t border-line px-3 py-2 first:border-t-0" wire:key="chat-{{ $chat['chat_id'] }}-{{ $chat['thread_id'] }}">
                                <div class="min-w-0">
                                    <p class="truncate text-[13.5px] font-medium">{{ $chat['title'] }}</p>
                                    <p class="font-mono text-[12px] text-muted">{{ $chat['chat_id'] }}@if ($chat['thread_id'] !== '') · thread {{ $chat['thread_id'] }}@endif · {{ $chat['type'] }}</p>
                                </div>
                                <button class="btn" type="button" wire:click="useChat({{ $index }})">Use</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-layer-card>

            <x-layer-card title="Events" helper="Choose what sends a message. Give an event its own topic to split alerts (empty uses the thread above)." flush>
                @foreach ($eventGroups as $group => $options)
                    <div class="border-t border-line first:border-t-0">
                        <p class="px-4 pb-1 pt-3 text-[11px] font-medium text-muted">{{ $group }}</p>
                        @foreach ($options as $key => $label)
                            <div class="flex flex-wrap items-center gap-3 px-4 py-2" wire:key="event-{{ $key }}">
                                <label class="flex min-w-0 flex-1 items-center gap-2.5 text-[13.5px]">
                                    <input type="checkbox" wire:model="events" value="{{ $key }}">
                                    <span>{{ $label }}</span>
                                </label>
                                <input class="input h-7 w-28 font-mono text-[12px]" wire:model="threads.{{ $key }}" inputmode="numeric" placeholder="Topic ID" aria-label="Topic ID for {{ $label }}" title="Topic (thread) ID for this event">
                                @error('threads.'.$key) <small class="error w-full text-right">{{ $message }}</small> @enderror
                            </div>
                        @endforeach
                        @if ($group === 'Server')
                            <div class="flex flex-wrap items-center gap-3 px-4 pb-3 pt-1 text-[13px]">
                                <span class="text-muted">Alert when disk usage reaches</span>
                                <span class="flex items-center gap-1">
                                    <input class="input h-7 w-16 text-center font-mono text-[12px]" wire:model="diskThreshold" inputmode="numeric" aria-label="Disk usage limit in percent">
                                    <span class="text-muted">%</span>
                                </span>
                                @error('diskThreshold') <small class="error">{{ $message }}</small> @enderror
                            </div>
                        @endif
                    </div>
                @endforeach
            </x-layer-card>
        </div>

        <x-layer-card title="Setup" helper="About two minutes.">
            <ol class="flex list-decimal flex-col gap-2.5 pl-4 text-[13px] text-ink-dim">
                <li>Open <code>@BotFather</code> in Telegram, send <code>/newbot</code>, and copy the token.</li>
                <li>Add the bot to your group. For a forum group, post one message in the topic you want.</li>
                <li>Paste the token above, then click <b>Find chats</b> and <b>Use</b> on your group or topic.</li>
                <li>Turn on <b>Send notifications</b>, save, and send a test message.</li>
            </ol>
            <p class="mt-3 text-[12px] text-muted">Find chats reads the bot's recent messages. If a group is missing, send a new message there and try again. Bots in groups need privacy mode off, or a message that mentions them.</p>
        </x-layer-card>
    </form>
</div>
