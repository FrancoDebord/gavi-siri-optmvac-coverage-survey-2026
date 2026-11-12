@php
    // Une tâche en attente depuis plus de 2 minutes : aucun worker ne traite la file (synchronisations bloquées).
    $stalled = config('queue.default') === 'database'
        ? \Illuminate\Support\Facades\DB::table(config('queue.connections.database.table', 'jobs'))
            ->whereNull('reserved_at')->where('created_at', '<', now()->subMinutes(2)->getTimestamp())->min('created_at')
        : null;
@endphp
@if ($stalled)
    <div class="alert alert-warning d-flex gap-2 small" role="alert">
        <i class="bi bi-hourglass-split"></i>
        <div>
            <strong>Des tâches attendent depuis {{ \Carbon\Carbon::createFromTimestamp($stalled)->diffForHumans(null, true) }}</strong> (synchronisation, moteur de queries) :
            aucun processus ne traite la file d'attente. Sur le serveur, lancez <code>php artisan queue:work</code>
            (et <code>php artisan schedule:work</code> pour les synchronisations automatiques), ou démarrez l'application avec <code>composer dev</code>.
        </div>
    </div>
@endif
