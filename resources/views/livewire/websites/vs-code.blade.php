<div class="vsc-launch" @if (! $error && ! $moveTask) wire:init="launch" @endif>
    <div class="vsc-launch-card">
        <i class="codicon codicon-vscode vsc-launch-logo"></i>
        <h1>{{ $website->name }}</h1>

        @if ($moveTask)
            <div class="vsc-launch-step" wire:poll.2s="checkMove"><i class="codicon codicon-loading codicon-modifier-spin"></i> {{ $moveStatus }}</div>
            <p class="vsc-muted" style="max-width: 460px">This runs in the background and can take a minute while the app restarts. You can leave this page open; VS Code opens when it is done.</p>
        @elseif ($error)
            <p class="vsc-launch-error"><i class="codicon codicon-error"></i> {{ $error }}</p>
            @if ($canMove)
                @php
                    $runs = match (\App\Support\SiteTypes::runtime($website->type)) {
                        \App\Support\SiteTypes::RUNTIME_PHP => ['its own PHP pool and workers', 'Its workers restart once.'],
                        \App\Support\SiteTypes::RUNTIME_NODE => ['its own PM2 process', 'Its app restarts once.'],
                        default => ['its own files', 'Visitors notice nothing.'],
                    };
                @endphp
                <p class="vsc-muted" style="max-width: 460px">Moving it gives the site its own Unix user and {{ $runs[0] }}, so it cannot touch other sites. {{ $runs[1] }} If it stops answering afterwards, it goes back to the shared user by itself.</p>
            @endif
            <div class="vsc-launch-actions">
                <a class="vsc-button-secondary" href="{{ route('websites.show', $website) }}">Back to the website</a>
                @if ($canMove)
                    <button type="button" class="vsc-button" style="width: auto; padding: 0 14px" wire:click="moveToOwnUser" wire:loading.attr="disabled"><i class="codicon codicon-loading codicon-modifier-spin" wire:loading wire:target="moveToOwnUser"></i> Move to its own user and open VS Code</button>
                @else
                    <button type="button" class="vsc-button" style="width: auto; padding: 0 14px" wire:click="retry" wire:loading.attr="disabled"><i class="codicon codicon-loading codicon-modifier-spin" wire:loading wire:target="retry"></i> Try again</button>
                @endif
            </div>
        @else
            @php
                $downloading = $installing !== null;
                $done = $downloading && $total > 0 && $installing >= $total;
                $percent = $downloading && $total > 0 ? min(100, (int) floor($installing / $total * 100)) : null;
            @endphp
            <p class="vsc-launch-step">{{ $downloading && ! $done ? 'Installing VS Code…' : 'Starting VS Code…' }}</p>
            <ol class="vsc-launch-steps" @if ($downloading) wire:poll.3s="launch" @endif>
                <li @class(['is-active' => $downloading && ! $done, 'is-done' => ! $downloading])>
                    <span class="vsc-step-icon">
                        @if ($downloading && ! $done)
                            <i class="codicon codicon-loading codicon-modifier-spin"></i>
                        @else
                            <i class="codicon codicon-pass-filled"></i>
                        @endif
                    </span>
                    <span class="vsc-step-body">
                        <b>Install VS Code on the server</b>
                        @if ($downloading)
                            <span class="vsc-launch-bar"><span style="width: {{ $percent ?? 0 }}%" @class(['is-unknown' => $percent === null])></span></span>
                            <small>
                                @if ($done)
                                    Unpacking…
                                @elseif ($percent !== null)
                                    {{ $percent }}% · {{ $installing }} of {{ $total }} MB
                                @else
                                    {{ $installing }} MB downloaded
                                @endif
                                · first time only, for every website on this server
                            </small>
                        @else
                            <small>Ready</small>
                        @endif
                    </span>
                </li>
                <li @class(['is-active' => ! $downloading || $done, 'is-waiting' => $downloading && ! $done])>
                    <span class="vsc-step-icon">
                        @if (! $downloading || $done)
                            <i class="codicon codicon-loading codicon-modifier-spin"></i>
                        @else
                            <i class="codicon codicon-circle-large-outline"></i>
                        @endif
                    </span>
                    <span class="vsc-step-body">
                        <b>Get the latest {{ $website->branch }} and start the editor</b>
                        <small>Runs as this website's own user, on its workspace</small>
                    </span>
                </li>
            </ol>
            <p class="vsc-muted">You can leave this page open: VS Code opens as soon as it is ready.</p>
        @endif
    </div>
</div>
