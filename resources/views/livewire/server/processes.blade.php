<div class="flex flex-col gap-4">
    <p class="text-muted">PM2 apps and Supervisor programs on this machine. Restart or delete a process, then read its pid, logs, or the project <code>.env</code>. A GitHub push writes the same deployment log shown on the website.</p>
    <p><a class="underline" href="{{ route('server.index') }}" wire:navigate>Back to services</a></p>

    <section class="card overflow-hidden">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Manager</th>
                    <th>PID</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['manager'] }}</td>
                        <td>{{ $row['pid'] !== '' ? $row['pid'] : '—' }}</td>
                        <td>{{ $row['status'] }}<br><span class="text-muted">{{ $row['detail'] }}</span></td>
                        <td class="text-right">
                            <button class="btn" type="button" wire:click="restart('{{ $row['manager'] }}', '{{ $row['name'] }}')">Restart</button>
                            <button class="btn" type="button" wire:click="logs('{{ $row['manager'] }}', '{{ $row['name'] }}')">Logs</button>
                            <button class="btn" type="button" wire:click="env('{{ $row['name'] }}')">.env</button>
                            <x-confirm-delete
                                title="Confirm {{ $row['manager'] === 'pm2' ? 'process deletion' : 'process stop' }}?"
                                :name="$row['name']"
                                :action="'delete(\''.$row['manager'].'\', \''.$row['name'].'\')'"
                                :button="$row['manager'] === 'pm2' ? 'Delete' : 'Stop'"
                                :actions="[$row['manager'] === 'pm2' ? 'This PM2 process will be deleted.' : 'This Supervisor program will be stopped.']"
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-muted">No PM2 or Supervisor processes are running. They appear after a site is applied.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    @if ($inspectionTitle !== '')
        <section class="card flex flex-col gap-2 p-4">
            <h2 class="font-medium">{{ $inspectionTitle }}</h2>
            <pre class="textarea max-h-96 overflow-auto whitespace-pre-wrap">{{ $inspection }}</pre>
        </section>
    @endif
</div>
