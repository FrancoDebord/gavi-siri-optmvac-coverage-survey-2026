<?php

namespace Database\Seeders;

use App\Models\Cluster;
use App\Models\Collector;
use App\Models\Commune;
use App\Models\DataQuery;
use App\Models\DataSource;
use App\Models\HealthZone;
use App\Models\QueryEvent;
use App\Models\User;
use App\Models\Village;
use App\Services\Dictionary\CsvReader;
use App\Services\Dictionary\Dictionary;
use App\Services\Import\SubmissionProcessor;
use App\Services\Quality\QualityEngine;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoSubmissionGenerator;
use Illuminate\Database\Seeder;

/**
 * Données fictives pour tester les dashboards : ~40 grappes, 14 jours de collecte,
 * ~10 % de soumissions avec une anomalie injectée, utilisateurs de démonstration
 * et quelques queries déjà traitées.
 *
 * php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    /** Centres approximatifs des zones sanitaires (lat, lon). */
    private const ZONE_CENTERS = ['1' => [8.88, 2.60], '2' => [7.80, 2.20]];

    public function run(): void
    {
        if (HealthZone::count() === 0) {
            $this->call(DatabaseSeeder::class);
        }
        mt_srand(2026);

        $dictionary = Dictionary::current();
        $generator = new DemoSubmissionGenerator($dictionary);
        $processor = new SubmissionProcessor($dictionary);
        $smcCycles = collect(CsvReader::read(config('gavi.settings_path').'/l2.csv'))->pluck('smc_cycles', 'name');

        // 20 grappes par zone sanitaire, avec cibles.
        $clusters = [];
        $communeCenters = [];
        foreach (HealthZone::all() as $zone) {
            $collectors = Collector::where('health_zone_id', $zone->id)->pluck('code')->all();
            $villages = Village::where('health_zone_id', $zone->id)->with('arrondissement', 'commune')->orderBy('id')->get()->shuffle(2026)->take(20);
            foreach ($villages->values() as $i => $village) {
                $zoneCenter = self::ZONE_CENTERS[$zone->code] ?? [8.0, 2.3];
                $center = $communeCenters[$village->commune_id] ??= [$zoneCenter[0] + mt_rand(-150, 150) / 1000, $zoneCenter[1] + mt_rand(-150, 150) / 1000];
                $cluster = Cluster::where('code', $village->code)->first();
                $cluster?->update(['target_households' => 15, 'target_children' => 12]);
                $clusters[] = [
                    'zone' => $zone->code,
                    'commune' => $village->commune?->code,
                    'arrondissement' => $village->arrondissement?->code,
                    'village' => $village->code,
                    'collector' => $collectors[$i % max(1, count($collectors))] ?? 'demo',
                    'center' => [$center[0] + (mt_rand(-80, 80) / 1000), $center[1] + (mt_rand(-80, 80) / 1000)],
                    'smc_cycles' => (int) ($smcCycles[$village->commune?->code] ?? 4),
                    'progress' => mt_rand(0, 100) <= 15 ? 0 : mt_rand(30, 115) / 100,
                ];
            }
        }

        $days = 14;
        $firstDay = CarbonImmutable::today()->subDays($days);
        $count = 0;
        $anomalies = [];
        foreach ($clusters as $c => $cluster) {
            $households = (int) round(15 * $cluster['progress']);
            for ($h = 1; $h <= $households; $h++) {
                $date = $firstDay->addDays(min($days - 1, intdiv(($h - 1) * $days, max(1, $households)) + mt_rand(0, 1)));
                $code = sprintf('%s-%03d', $cluster['village'], $h);
                $anomaly = mt_rand(1, 100) <= 12 ? DemoSubmissionGenerator::ANOMALIES[array_rand(DemoSubmissionGenerator::ANOMALIES)] : null;
                if ($anomaly === 'duplicate_household' && $h > 1) {
                    $code = sprintf('%s-%03d', $cluster['village'], $h - 1);
                }

                $result = $generator->make($cluster, $code, $date, $anomaly === 'duplicate_household' ? null : $anomaly);
                $processor->process($result['submission'], ['source_type' => 'demo']);
                $anomalies[$anomaly ?? 'aucune'] = ($anomalies[$anomaly ?? 'aucune'] ?? 0) + 1;
                $count++;
            }
        }
        ksort($anomalies);
        $this->command?->info("{$count} soumissions fictives générées. Anomalies injectées : ".collect($anomalies)->map(fn ($n, $k) => "{$k}={$n}")->implode(', '));

        $this->demoUsers();
        $this->demoSources();

        $run = app(QualityEngine::class)->run('seed');
        $this->command?->info("Moteur de queries : {$run->opened_count} queries ouvertes sur {$run->households_checked} ménages.");

        $this->simulateQueryWorkflow();
    }

    private function demoUsers(): void
    {
        $zone = HealthZone::orderBy('code')->first();
        $commune = Commune::where('health_zone_id', $zone?->id)->first();
        $users = [
            ['Data manager', 'datamanager@gavi.local', User::ROLE_DATA_MANAGER, null, null],
            ['Superviseur '.$zone?->name, 'superviseur@gavi.local', User::ROLE_SUPERVISOR, $zone?->id, null],
            ['Superviseur commune '.$commune?->name, 'superviseur.commune@gavi.local', User::ROLE_SUPERVISOR, $zone?->id, $commune?->id],
            ['Lecteur', 'lecteur@gavi.local', User::ROLE_READER, null, null],
        ];
        foreach ($users as [$name, $email, $role, $zoneId, $communeId]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name, 'password' => 'Demo!2026', 'health_zone_id' => $zoneId, 'commune_id' => $communeId,
            ])->syncRoles([$role]);
        }
    }

    private function demoSources(): void
    {
        DataSource::firstOrCreate(['name' => 'ODK Central (exemple)'], [
            'type' => DataSource::TYPE_ODK,
            'server_url' => 'https://odk.example.org',
            'credentials' => ['email' => 'collecte@example.org', 'password' => 'à-renseigner'],
            'project_id' => '1',
            'form_id' => 'cov26c4v2',
            'sync_interval_minutes' => 30,
            'is_active' => false,
        ]);
        DataSource::firstOrCreate(['name' => 'KoboToolbox (exemple)'], [
            'type' => DataSource::TYPE_KOBO,
            'server_url' => 'https://kf.kobotoolbox.org',
            'credentials' => ['token' => 'à-renseigner'],
            'asset_uid' => 'aXXXXXXXXXXXXXXXXXXXXX',
            'sync_interval_minutes' => 60,
            'is_active' => false,
        ]);
    }

    /** Fait avancer une partie des queries dans le cycle de vie, pour peupler le dashboard. */
    private function simulateQueryWorkflow(): void
    {
        $manager = User::where('email', 'datamanager@gavi.local')->first();
        $queries = DataQuery::where('status', DataQuery::STATUS_OPEN)->inRandomOrder()->limit((int) (DataQuery::count() * 0.45))->get();

        foreach ($queries as $i => $query) {
            $path = match ($i % 4) {
                0 => [DataQuery::STATUS_SENT],
                1 => [DataQuery::STATUS_SENT, DataQuery::STATUS_JUSTIFIED],
                2 => [DataQuery::STATUS_SENT, DataQuery::STATUS_JUSTIFIED, DataQuery::STATUS_VALIDATED],
                3 => [DataQuery::STATUS_SENT, DataQuery::STATUS_CORRECTED, DataQuery::STATUS_VALIDATED],
            };
            $at = CarbonImmutable::parse($query->detected_at);
            $from = $query->status;
            foreach ($path as $step => $status) {
                $at = $at->addHours(mt_rand(4, 40));
                QueryEvent::create([
                    'data_query_id' => $query->id,
                    'user_id' => $manager?->id,
                    'type' => 'status',
                    'from_status' => $from,
                    'to_status' => $status,
                    'comment' => match ($status) {
                        DataQuery::STATUS_JUSTIFIED => 'Vérifié avec le binôme : la valeur est correcte (confirmée sur le carnet).',
                        DataQuery::STATUS_CORRECTED => 'Correction faite par le binôme sur la tablette.',
                        default => null,
                    },
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
                $from = $status;
            }
            $query->forceFill([
                'status' => $from,
                'resolved_at' => in_array($from, [DataQuery::STATUS_JUSTIFIED, DataQuery::STATUS_CORRECTED, DataQuery::STATUS_VALIDATED], true) ? $at : null,
                'closed_at' => $from === DataQuery::STATUS_VALIDATED ? $at : null,
            ])->save();
        }
    }
}
