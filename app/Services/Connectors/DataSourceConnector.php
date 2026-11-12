<?php

namespace App\Services\Connectors;

use App\Models\FormVersion;
use App\Services\Import\NormalizedSubmission;
use Carbon\CarbonInterface;

interface DataSourceConnector
{
    /**
     * Vérifie l'accès au serveur et au formulaire.
     *
     * @return array{ok: bool, message: string, details: array<string, mixed>}
     */
    public function testConnection(): array;

    /**
     * Récupère les soumissions (toutes, ou postérieures à $since) page par page.
     * $onPage reçoit chaque lot de soumissions normalisées.
     *
     * @param  callable(list<NormalizedSubmission>): void  $onPage
     * @return int nombre de soumissions récupérées
     */
    public function fetchSubmissions(?CarbonInterface $since, callable $onPage): int;

    /**
     * Télécharge le data dictionary et le charge comme nouvelle version de formulaire.
     *
     * @return FormVersion
     */
    public function importDictionary();
}
