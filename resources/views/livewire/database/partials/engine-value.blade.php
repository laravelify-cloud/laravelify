@if ($value === null)
<span class="db-null">NULL</span>
@else
<span class="db-cell" title="{{ \Illuminate\Support\Str::limit((string) $value, 2000) }}">{{ \Illuminate\Support\Str::limit((string) $value, 120) }}</span>
@endif
