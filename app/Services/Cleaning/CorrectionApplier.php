<?php

namespace App\Services\Cleaning;

use App\Models\DataCorrection;
use App\Services\Import\NormalizedSubmission;
use Illuminate\Support\Facades\DB;

/**
 * Applique à une soumission source les corrections validées (dans l'ordre de validation) pour obtenir la version
 * nettoyée. Si la valeur reçue d'ODK n'est plus celle sur laquelle la correction a été décidée (ou si le membre a
 * disparu), la correction n'est pas appliquée et passe « en conflit » pour revue par le data manager.
 */
class CorrectionApplier
{
    /** @var array<string, true>|null soumissions ayant des corrections validées */
    private ?array $corrected = null;

    /**
     * @return array{0: NormalizedSubmission, 1: string} soumission nettoyée et empreinte des corrections ('' sans correction)
     */
    public function apply(NormalizedSubmission $source): array
    {
        $this->corrected ??= DataCorrection::where('status', DataCorrection::STATUS_VALIDATED)->distinct()->pluck('submission_uuid')->flip()->map(fn () => true)->all();
        if (! isset($this->corrected[$source->uuid])) {
            return [$source, ''];
        }

        $clean = clone $source;
        $applied = [];
        foreach (DataCorrection::where('submission_uuid', $source->uuid)->applicable()->get() as $correction) {
            $sourceValue = SubmissionPaths::exists($source, $correction->instance_path)
                ? SubmissionPaths::get($source, $correction->variable, $correction->instance_path)
                : false;
            if ($sourceValue === false || self::normalize($sourceValue) !== self::normalize($correction->source_value)) {
                DB::table('data_corrections')->where('id', $correction->id)->update([
                    'status' => DataCorrection::STATUS_CONFLICT,
                    'decision_comment' => trim(($correction->decision_comment ? $correction->decision_comment.' — ' : '').'Suspendue le '.now()->format('d/m/Y H:i').' : la valeur reçue d\'ODK a changé ('.$correction->displayValue(self::normalize($correction->source_value)).' → '.($sourceValue === false ? 'membre absent' : $correction->displayValue(self::normalize($sourceValue))).').'),
                    'updated_at' => now(),
                ]);

                continue;
            }
            SubmissionPaths::set($clean, $correction->variable, $correction->instance_path, $correction->new_value);
            if (! $correction->applied_at) {
                DB::table('data_corrections')->where('id', $correction->id)->update(['applied_at' => now()]);
            }
            $applied[] = $correction->id.':'.$correction->decided_at?->timestamp;
        }

        return [$clean, $applied ? implode(',', $applied) : ''];
    }

    /** Une validation ou un rejet a eu lieu : la liste des soumissions corrigées est relue. */
    public function forget(): void
    {
        $this->corrected = null;
    }

    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = is_scalar($value) ? trim((string) $value) : json_encode($value);

        return $value === '' ? null : $value;
    }
}
