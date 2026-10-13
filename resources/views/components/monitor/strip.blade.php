@props([
    'buckets',
])

{{-- Uptime strip: one block per time bucket, green when every check passed. --}}
<div class="mon-strip" role="img" aria-label="Uptime history">
    @foreach ($buckets as $bucket)
        <span class="is-{{ $bucket['state'] }}" title="{{ $bucket['label'] }}: {{ ['up' => 'up', 'down' => 'down', 'partial' => 'some checks failed', 'none' => 'no checks'][$bucket['state']] }}"></span>
    @endforeach
</div>
