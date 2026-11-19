<?php

namespace App\Services\Export;

use App\Models\DataCorrection;
use App\Models\DataExport;
use App\Services\Dictionary\Dictionary;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Construit le fichier d'un export : CSV (ou SQL) dans un ZIP, avec manifeste JSON (empreinte SHA-256 et nombre de
 * lignes de chaque fichier, version du formulaire et de l'application, auteur, date) et notice LISEZMOI.
 * La copie SQL est chiffrée en AES-256 avec le mot de passe saisi par l'administrateur (non conservé).
 */
class ExportPackager
{
    public static function directory(): string
    {
        return rtrim((string) (config('gavi.exports_path') ?: storage_path('app/private/exports')), '/\\');
    }

    public function build(DataExport $export, ?string $password = null): void
    {
        $work = self::directory().'/tmp-'.$export->id.'-'.Str::random(8);
        File::ensureDirectoryExists($work);
        $now = now();
        $stamp = $now->format('Ymd-His');
        $name = match ($export->type) {
            DataExport::TYPE_RAW => "base-brute-{$stamp}",
            DataExport::TYPE_CLEAN => "base-nettoyee-{$stamp}",
            DataExport::TYPE_ARCHIVE => "archive-donnees-{$stamp}",
            DataExport::TYPE_SQL => "copie-base-{$stamp}",
        };

        try {
            $exporter = (new DatasetExporter)->on($now);
            $files = match ($export->type) {
                DataExport::TYPE_RAW => self::prefix('', $exporter->writeBase($work, 'brute')),
                DataExport::TYPE_CLEAN => self::prefix('', $exporter->writeBase($work, 'nettoyee')) + $exporter->writeRegistry($work),
                DataExport::TYPE_ARCHIVE => self::prefix('base_brute/', $exporter->writeBase("{$work}/base_brute", 'brute'))
                    + self::prefix('base_nettoyee/', $exporter->writeBase("{$work}/base_nettoyee", 'nettoyee'))
                    + $exporter->writeRegistry($work) + $exporter->writeQueries($work) + $exporter->writeDictionary($work),
                DataExport::TYPE_SQL => $this->sql($work),
            };

            $manifest = $this->manifest($export, $work, $files);
            file_put_contents("{$work}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            file_put_contents("{$work}/LISEZMOI.txt", $this->readme($export, $manifest));

            $zipPath = self::directory()."/{$name}.zip";
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de créer le fichier ZIP.');
            }
            foreach ([...array_keys($files), 'manifest.json', 'LISEZMOI.txt'] as $file) {
                $zip->addFile("{$work}/{$file}", $file);
                if ($export->type === DataExport::TYPE_SQL) {
                    $zip->setEncryptionName($file, ZipArchive::EM_AES_256, (string) $password);
                }
            }
            $zip->close();

            $export->update([
                'status' => DataExport::STATUS_DONE,
                'file_path' => $zipPath,
                'file_name' => "{$name}.zip",
                'file_size' => filesize($zipPath),
                'sha256' => hash_file('sha256', $zipPath),
                'manifest' => $manifest,
                'finished_at' => now(),
                'expires_at' => now()->addDays(DataExport::RETENTION_DAYS),
            ]);
        } finally {
            File::deleteDirectory($work);
        }
    }

    private function sql(string $work): array
    {
        $counts = (new SqlDumper)->dump("{$work}/base.sql");

        return ['base.sql' => ['rows' => array_sum($counts), 'description' => count($counts).' tables : structure et données ('.collect($counts)->map(fn ($n, $t) => "{$t} {$n}")->implode(', ').')']];
    }

    private static function prefix(string $prefix, array $files): array
    {
        return collect($files)->mapWithKeys(fn ($info, $file) => [$prefix.$file => $info])->all();
    }

    private function manifest(DataExport $export, string $work, array $files): array
    {
        $revision = is_file(base_path('REVISION')) ? trim((string) file_get_contents(base_path('REVISION'))) : null;

        return [
            'export' => $export->typeLabel(),
            'reference' => 'EXP-'.str_pad((string) $export->id, 6, '0', STR_PAD_LEFT),
            'genere_le' => now()->toIso8601String(),
            'genere_par' => $export->requester?->name,
            'application' => config('app.name'),
            'revision_application' => $revision,
            'version_formulaire' => Dictionary::current()->formVersion?->version,
            'corrections_validees' => DataCorrection::where('status', DataCorrection::STATUS_VALIDATED)->count(),
            'chiffrement' => $export->type === DataExport::TYPE_SQL ? 'ZIP AES-256 (mot de passe communiqué séparément, non conservé par la plateforme)' : null,
            'fichiers' => collect($files)->map(fn ($info, $file) => [
                'fichier' => $file,
                'description' => $info['description'],
                'lignes' => $info['rows'],
                'octets' => filesize("{$work}/{$file}"),
                'sha256' => hash_file('sha256', "{$work}/{$file}"),
            ])->values()->all(),
        ];
    }

    private function readme(DataExport $export, array $manifest): string
    {
        $lines = [
            $manifest['export'].' – '.$manifest['application'],
            str_repeat('=', 72),
            'Référence : '.$manifest['reference'].' · généré le '.$manifest['genere_le'].' par '.($manifest['genere_par'] ?? '—'),
            'Version du formulaire : '.($manifest['version_formulaire'] ?? '—').' · révision de l\'application : '.($manifest['revision_application'] ?? '—'),
            '',
            'Principes (bonnes pratiques cliniques / GCP) :',
            '- Les données d\'ODK ne sont jamais modifiées. La base brute reproduit chaque soumission telle que reçue,',
            '  y compris les soumissions exclues de la base nettoyée (colonne exclue_base_nettoyee).',
            '- La base nettoyée applique à la source les corrections validées par le data manager, dans leur ordre de',
            '  validation ; les soumissions exclues (doublons) en sont écartées.',
            '- Le registre des corrections conserve, pour chaque correction, la valeur reçue d\'ODK, la valeur remplacée,',
            '  la nouvelle valeur, le motif, la source, l\'auteur, la décision et la signature électronique.',
            '- Les membres des repeats sont identifiés par leur chemin (ex. cg[1]/smc_cov_hh_cg_child_2023[2]),',
            '  la même notation que les queries et le registre.',
            '',
            'Format : CSV UTF-8 (avec BOM), séparateur virgule, guillemets doubles, une ligne d\'en-tête.',
            'Intégrité : manifest.json donne l\'empreinte SHA-256 et le nombre de lignes de chaque fichier ;',
            'vérification, par exemple : sha256sum <fichier>.csv (Linux) ou certutil -hashfile <fichier>.csv SHA256 (Windows).',
            'Noms des tables : ceux de l\'export CSV d\'ODK Central préfixés de « app_brute_ » (base brute) ou',
            '« app_nettoyee_ » (base nettoyée) et de la date de l\'export : app_brute_AAAAMMJJ_<formulaire>.csv pour les ménages,',
            'app_brute_AAAAMMJJ_<formulaire>-<repeat>.csv pour chaque repeat (de même avec app_nettoyee_).',
            '',
            'Fichiers :',
        ];
        foreach ($manifest['fichiers'] as $file) {
            $lines[] = sprintf('- %s (%d lignes) : %s', $file['fichier'], $file['lignes'], $file['description']);
        }
        if ($export->type === DataExport::TYPE_SQL) {
            $lines[] = '';
            $lines[] = 'Restauration : importer base.sql dans une base MySQL vide (mysql < base.sql, ou phpMyAdmin).';
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
