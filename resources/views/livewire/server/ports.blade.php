<div class="flex flex-col gap-4">
    <p class="text-muted">These ports are already taken on this machine. A new site without a domain needs a free HTTP port. 8081–8090 are published from the Docker test container.</p>
    <p><a class="underline" href="{{ route('server.index') }}" wire:navigate>Back to services</a></p>

    <section class="card overflow-hidden">
        <table class="table">
            <thead>
                <tr>
                    <th>Port</th>
                    <th>Reserved for</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ports as $port)
                    <tr>
                        <td>{{ $port['port'] }}</td>
                        <td>{{ $port['owner'] }}</td>
                        <td>{{ $port['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
