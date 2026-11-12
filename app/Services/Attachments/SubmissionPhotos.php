<?php

namespace App\Services\Attachments;

use App\Models\DataQuery;
use App\Models\DataSource;
use App\Models\Household;
use App\Models\PhotoCheck;
use App\Services\Connectors\ConnectorException;
use App\Services\Connectors\ConnectorFactory;
use App\Services\Connectors\OdkCentralConnector;
use App\Services\Dictionary\Dictionary;

/**
 * Photos prises dans le formulaire (variables de type image : carnet, acte de naissance…).
 * Elles sont téléchargées depuis ODK Central à la première consultation, puis gardées
 * dans storage/app/private/attachments/{ménage}.
 */
class SubmissionPhotos
{
    public function __construct(private readonly ConnectorFactory $connectors) {}

    /**
     * Photos utiles à une query : celles de l'enfant concerné, sinon celles du ménage.
     * La photo consultée pour une vérification PHOTO_CHECK est mise en avant (en premier) ;
     * pour DUP_CARD, les photos des enfants nés le même jour sont affichées ensemble pour comparaison.
     *
     * @return list<array{variable: string, label: string, filename: string, url: string, highlight: bool}>
     */
    public function forQuery(DataQuery $query): array
    {
        $household = $query->household;
        if (! $household) {
            return [];
        }
        if ($query->rule_code === 'DUP_CARD' && $query->child?->date_of_birth) {
            return $household->children()->whereDate('date_of_birth', $query->child->date_of_birth)->orderBy('id')->get()
                ->flatMap(fn ($child) => $this->photos($household, $child->data ?? [], null, 'Enfant n°'.$child->position.' – '))
                ->values()->all();
        }

        $checked = $query->rule_code === 'PHOTO_CHECK'
            ? PhotoCheck::where('submission_uuid', $query->submission_uuid)->where('instance_path', $query->instance_path)
                ->where('variable', $query->variable)->value('photo')
            : null;
        $photos = $this->photos($household, $query->child?->data ?? $household->data ?? [], $checked);
        usort($photos, fn ($a, $b) => $b['highlight'] <=> $a['highlight']);

        return $photos;
    }

    /** @return list<array{variable: string, label: string, filename: string, url: string, highlight: bool}> */
    private function photos(Household $household, array $data, ?string $checked, string $prefix = ''): array
    {
        $dictionary = Dictionary::current();
        $photos = [];
        foreach ($this->imageVariables() as $variable) {
            $filename = $data[$variable] ?? null;
            if (! is_string($filename) || $filename === '') {
                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($dictionary->variable($variable)['label'] ?? $variable))));
            $photos[] = [
                'variable' => $variable,
                'label' => $prefix.$label,
                'filename' => $filename,
                'url' => route('households.photo', [$household, $filename]),
                'highlight' => $filename === $checked,
            ];
        }

        return $photos;
    }

    /** Le fichier fait-il partie des photos de cette soumission (ménage, caregivers, enfants) ? */
    public function belongsTo(Household $household, string $filename): bool
    {
        $household->loadMissing(['caregivers', 'children']);
        $sets = [$household->data ?? [], ...$household->caregivers->pluck('data')->all(), ...$household->children->pluck('data')->all()];
        foreach ($sets as $data) {
            foreach ($this->imageVariables() as $variable) {
                if (($data[$variable] ?? null) === $filename) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Chemin local de la photo, téléchargée au besoin.
     *
     * @throws ConnectorException si la source ne permet pas de récupérer la pièce jointe
     */
    public function path(Household $household, string $filename): string
    {
        $path = storage_path('app/private/attachments/'.$household->id.'/'.basename($filename));
        if (is_file($path)) {
            return $path;
        }

        $source = $household->dataSource;
        if (! $source || $source->type !== DataSource::TYPE_ODK) {
            throw new ConnectorException('Photo disponible uniquement pour les soumissions synchronisées depuis ODK Central.');
        }
        $connector = $this->connectors->make($source);
        assert($connector instanceof OdkCentralConnector);
        $content = $connector->attachment($household->submission_uuid, $filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);

        return $path;
    }

    /** @return list<string> variables de type image du dictionnaire actif */
    private function imageVariables(): array
    {
        return collect(Dictionary::current()->variables())
            ->filter(fn ($v) => ($v['base_type'] ?? null) === 'image')
            ->keys()->all();
    }
}
