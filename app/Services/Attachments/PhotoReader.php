<?php

namespace App\Services\Attachments;

use Anthropic\Client;
use App\Models\AppSetting;
use App\Models\DataQuery;
use App\Services\Cleaning\CorrectionApplier;
use App\Services\Dictionary\Dictionary;
use App\Services\Quality\QueryFocus;
use RuntimeException;
use Throwable;

/**
 * Lecture automatique (Claude, vision) de la vraie valeur d'une question sur les photos de la soumission
 * (carnet, acte de naissance…). Le résultat n'est qu'une suggestion « lue par l'IA, à vérifier » : l'agent la
 * confirme en corrigeant, et la correction suit le circuit normal de validation. Rien n'est appliqué d'office.
 *
 * Activée par l'administrateur (Paramètres) et seulement si une clé ANTHROPIC_API_KEY est configurée :
 * les photos sont alors envoyées à l'API Anthropic.
 */
class PhotoReader
{
    /** Côté le plus long envoyé (pixels) : au-delà, l'API réduit l'image de toute façon. */
    private const MAX_SIDE = 1568;

    private const MAX_PHOTOS = 4;

    public function __construct(private readonly SubmissionPhotos $photos) {}

    public static function configured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    public static function enabled(): bool
    {
        return self::configured() && (bool) AppSetting::get(AppSetting::AI_READING);
    }

    /**
     * @return array{found: bool, value: ?string, confidence: string, photo: ?string, note: string}
     *
     * @throws RuntimeException lecture impossible (pas de photo, API indisponible…)
     */
    public function read(DataQuery $query): array
    {
        if (! $query->variable || ! $query->household) {
            throw new RuntimeException('Query sans variable ni ménage : rien à lire.');
        }
        $photos = array_slice($this->photos->forQuery($query), 0, self::MAX_PHOTOS);
        if ($photos === []) {
            throw new RuntimeException('Aucune photo dans cette soumission.');
        }

        $content = [];
        foreach ($photos as $i => $photo) {
            try {
                $path = $this->photos->path($query->household, $photo['filename']);
            } catch (Throwable $e) {
                continue;
            }
            $content[] = ['type' => 'text', 'text' => 'Photo '.($i + 1).' : '.$photo['label']];
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($this->jpeg($path))]];
        }
        if ($content === []) {
            throw new RuntimeException('Photos indisponibles (téléchargement depuis ODK Central impossible).');
        }
        $content[] = ['type' => 'text', 'text' => $this->instructions($query)];

        $answer = $this->ask($content, self::SCHEMA);
        $value = CorrectionApplier::normalize($answer['value'] ?? null);
        $index = $answer['photo'] ?? null;

        return [
            'found' => (bool) ($answer['found'] ?? false) && $value !== null,
            'value' => $value,
            'confidence' => in_array($answer['confidence'] ?? null, ['haute', 'moyenne', 'faible'], true) ? $answer['confidence'] : 'faible',
            'photo' => is_int($index) && isset($photos[$index - 1]) ? $photos[$index - 1]['label'] : null,
            'note' => mb_substr(trim((string) ($answer['note'] ?? '')), 0, 200),
        ];
    }

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'found' => ['type' => 'boolean', 'description' => 'true si la valeur est lisible sur une photo'],
            'value' => ['type' => ['string', 'null'], 'description' => 'valeur lue, au format demandé ; null si illisible ou absente'],
            'photo' => ['type' => ['integer', 'null'], 'description' => 'numéro de la photo où la valeur a été lue'],
            'confidence' => ['type' => 'string', 'enum' => ['haute', 'moyenne', 'faible']],
            'note' => ['type' => 'string', 'description' => 'en français, une phrase : ce qui a été lu et où'],
        ],
        'required' => ['found', 'value', 'photo', 'confidence', 'note'],
        'additionalProperties' => false,
    ];

    private function instructions(DataQuery $query): string
    {
        $dictionary = Dictionary::current();
        $definition = $dictionary->variable($query->variable) ?? [];
        $type = $definition['base_type'] ?? 'text';
        $focus = QueryFocus::for($query, $dictionary);
        $question = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($definition['label'] ?? $query->variable_label ?? $query->variable))));

        $format = match ($type) {
            'date' => 'une date au format AAAA-MM-JJ',
            'integer' => 'un nombre entier',
            'decimal' => 'un nombre (point décimal)',
            default => 'le texte tel qu\'écrit',
        };
        $choices = '';
        if (! empty($definition['list_name'])) {
            $list = collect($dictionary->choices($definition['list_name']) ?? [])
                ->map(fn ($label, $code) => '- '.$code.' : '.trim(strip_tags((string) $label)))->implode("\n");
            $format = 'le code d\'une des modalités ci-dessous';
            $choices = "\nModalités possibles :\n".$list;
        }

        return <<<TXT
        Enquête de couverture vaccinale (Bénin). Ces photos ont été prises par l'enquêteur dans le ménage (carnet de vaccination, acte de naissance…).
        Question du formulaire : « {$question} » (variable {$query->variable}){$this->member($focus['member'])}.
        Valeur saisie par l'enquêteur : {$this->shown($focus['raw'])}.
        Problème relevé : {$query->message}

        Lisez sur les photos la vraie valeur de cette question et donnez {$format}.{$choices}
        Ne déduisez rien : si la valeur n'est pas lisible ou n'apparaît sur aucune photo, répondez found=false et value=null.
        Pour une date, attention à l'ordre jour/mois sur les documents béninois (JJ/MM/AAAA).
        TXT;
    }

    private function member(?string $member): string
    {
        return $member ? ', '.$member : '';
    }

    private function shown(?string $value): string
    {
        return $value === null || $value === '' ? '(vide)' : $value;
    }

    /** Photo réduite et recompressée en JPEG (taille et coût de l'envoi). */
    private function jpeg(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('Photo illisible : '.basename($path));
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        if ($scale < 1) {
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            imagedestroy($image);
            $image = $resized;
        }
        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * Un appel à l'API (sortie JSON conforme au schéma). Isolé pour être remplacé dans les tests.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function ask(array $content, array $schema): array
    {
        if (! self::configured()) {
            throw new RuntimeException('Lecture IA non configurée (clé ANTHROPIC_API_KEY absente).');
        }
        try {
            $client = new Client(apiKey: (string) config('services.anthropic.key'), requestOptions: ['timeout' => 90.0]);
            $message = $client->messages->create(
                model: (string) config('services.anthropic.model'),
                maxTokens: 8000,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Lecture IA indisponible : '.$e->getMessage(), 0, $e);
        }
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('Lecture IA refusée pour ces photos.');
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $decoded = json_decode($block->text, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        throw new RuntimeException('Réponse IA inexploitable.');
    }
}
