<div class="vsc-launch" @if (! $error) wire:init="launch" @endif>
    <div class="vsc-launch-card">
        <i class="codicon codicon-vscode vsc-launch-logo"></i>
        <h1>{{ $workspace->name }}</h1>

        @if ($error)
            <p class="vsc-launch-error"><i class="codicon codicon-error"></i> {{ $error }}</p>
            <div class="vsc-launch-actions">
                <a class="vsc-button-secondary" href="{{ route('workspaces.index') }}">Back to workspaces</a>
                <button type="button" class="vsc-button" style="width: auto; padding: 0 14px" wire:click="retry" wire:loading.attr="disabled"><i class="codicon codicon-loading codicon-modifier-spin" wire:loading wire:target="retry"></i> Try again</button>
            </div>
        @else
            @php
                $downloading = $installing !== null;
                $percent = $downloading && $total > 0 ? min(100, (int) floor($installing / $total * 100)) : null;
            @endphp
            <p class="vsc-launch-step">{{ $downloading ? 'Installing VS Code…' : 'Starting VS Code…' }}</p>
            <ol class="vsc-launch-steps" @if ($downloading) wire:poll.3s="launch" @endif>
                <li @class(['is-active' => $downloading, 'is-done' => ! $downloading])>
                    <span class="vsc-step-icon">
                        <i @class(['codicon', 'codicon-loading codicon-modifier-spin' => $downloading, 'codicon-pass-filled' => ! $downloading])></i>
                    </span>
                    <span class="vsc-step-body">
                        <b>Install VS Code on the server</b>
                        @if ($downloading)
                            <span class="vsc-launch-bar"><span style="width: {{ $percent ?? 0 }}%" @class(['is-unknown' => $percent === null])></span></span>
                            <small>{{ $percent !== null ? $percent.'% · '.$installing.' of '.$total.' MB' : $installing.' MB downloaded' }}</small>
                        @else
                            <small>Ready</small>
                        @endif
                    </span>
                </li>
                <li @class(['is-active' => ! $downloading, 'is-waiting' => $downloading])>
                    <span class="vsc-step-icon">
                        <i @class(['codicon', 'codicon-loading codicon-modifier-spin' => ! $downloading, 'codicon-circle-large-outline' => $downloading])></i>
                    </span>
                    <span class="vsc-step-body">
                        <b>Get the latest code of {{ $workspace->websites->count() }} {{ \Illuminate\Support\Str::plural('project', $workspace->websites->count()) }} and start the editor</b>
                        <small>{{ $workspace->websites->pluck('name')->implode(', ') }}</small>
                    </span>
                </li>
            </ol>
            <p class="vsc-muted">You can leave this page open: VS Code opens as soon as it is ready.</p>
        @endif
    </div>
</div>
