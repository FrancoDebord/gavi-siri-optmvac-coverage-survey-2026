<?php

use App\Services\Quality\RuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suggestions de résolution plus courtes : texte des règles mis à jour (sauf s'il a été personnalisé dans l'interface),
 * puis suggestion recalculée sur leurs queries. Les suggestions propres à un cas (TEAM_VILLAGE) sont recalculées par
 * le prochain contrôle qualité.
 */
return new class extends Migration
{
    private const PREVIOUS = [
        'DICT_REQUIRED' => 'Contacter le binôme (ou revisiter le ménage) pour obtenir la réponse à « {question} », puis compléter la soumission par une édition dans ODK/Kobo.',
        'DICT_CONSTRAINT' => 'Vérifier la valeur saisie pour « {question} » ({valeur}) : elle doit respecter « {attendu} ». Corriger s\'il s\'agit d\'une erreur de saisie, sinon justifier.',
        'DICT_CHOICE' => 'La valeur {valeur} n\'existe pas dans la liste de choix de « {question} ». Vérifier la version du formulaire utilisée sur la tablette et corriger la réponse.',
        'DICT_TYPE' => 'Corriger le format de « {question} » (attendu : {attendu}).',
        'DICT_RELEVANT' => 'Vérifier les réponses qui conditionnent « {question} » (saut) : supprimer la valeur si la question ne devait pas être posée, ou corriger la réponse filtre.',
        'OTHER_SPECIFY' => 'Compléter la précision « autre » de « {question} » auprès du binôme ou du répondant.',
        'DUP_HOUSEHOLD' => 'Comparer les deux soumissions ({attendu}) avec le binôme : rejeter le doublon sur le serveur ou corriger l\'identifiant du ménage.',
        'DUP_CHILD' => 'Vérifier avec le binôme si l\'enfant a été saisi deux fois et supprimer l\'enregistrement en double.',
        'HH_CODE' => 'Corriger l\'identifiant du ménage dans ODK Central ({valeur}) au format attendu : {attendu}. Vérifier avec le binôme la grappe, la sous-grappe et le numéro d\'ordre.',
        'DUP_CARD' => 'Retourner au ménage : s\'il s\'agit du même enfant, supprimer l\'enregistrement en double ; s\'il s\'agit de jumeaux, photographier et saisir le carnet de chaque enfant.',
        'DUP_SUBMISSION' => 'Identifier la soumission à conserver ({attendu}) et rejeter l\'autre sur le serveur (statut « rejeté » ODK, validation Kobo).',
        'ELIG_AGE' => 'Vérifier la date de naissance sur le document. Si elle est exacte, l\'enfant n\'était pas éligible : retirer ses données de vaccination ou justifier.',
        'ELIG_AGE_DOB' => 'Recouper l\'âge déclaré ({valeur}) et la date de naissance ({attendu}) avec le caregiver ou le document, puis corriger la valeur erronée.',
        'VAC_BEFORE_DOB' => 'Relire sur le carnet la date « {question} » ({valeur}) et la date de naissance : l\'erreur peut porter sur l\'une ou l\'autre (jour/mois inversés, année). Corriger celle qui est fausse.',
        'VAC_AFTER_INTERVIEW' => 'La date « {question} » ({valeur}) est postérieure à l\'interview : relire le carnet et corriger (erreur d\'année probable).',
        'VAC_DOSE_ORDER' => 'Relire sur le carnet les deux dates en cause : l\'erreur peut porter sur « {question} » ou sur la dose précédente (doses inversées, jour et mois inversés, année). Corriger celle qui est fausse.',
        'VAC_DOSE_INTERVAL' => 'Vérifier sur le carnet les dates des deux doses ({valeur} d\'écart). Si elles sont exactes, justifier (rattrapage).',
        'VAC_MIN_AGE' => 'Vérifier la date « {question} » et la date de naissance : corriger celle qui est fausse. Si elles sont exactes, justifier (dose administrée précocement).',
        'VAC_STATUS_MISMATCH' => 'Harmoniser le statut « reçu » et la date selon le carnet : corriger « {question} » ou supprimer la date.',
        'CARD_NO_DATES' => 'Le carnet a été vu : saisir les dates de vaccination, ou le motif de date manquante pour chaque vaccin.',
        'PHOTO_CHECK' => 'Corriger « {question} » dans ODK Central d\'après la photo : {attendu} au lieu de {valeur}.',
        'CONSENT_REFUSED_DATA' => 'Vérifier le statut de l\'enquête et le consentement avec le binôme : corriger le statut ou supprimer les données saisies sans consentement.',
        'CONSENT_NO_CAREGIVER' => 'Vérifier pourquoi aucun caregiver n\'a été saisi : compléter le module ou corriger le statut du ménage.',
        'GPS_MISSING' => 'Reprendre le point GPS lors d\'une revisite, ou justifier (panne GPS, refus).',
        'GPS_ACCURACY' => 'Reprendre le point GPS à l\'extérieur avec une meilleure précision (valeur actuelle : {valeur}) en cas de revisite, sinon justifier.',
        'GPS_CLUSTER_DISTANCE' => 'Vérifier que le ménage appartient bien à la grappe déclarée ({valeur} du centre) : corriger la grappe ou le village, ou justifier.',
        'GPS_OUTSIDE_COMMUNE' => 'Vérifier la commune et le village déclarés ainsi que la position GPS, puis corriger la localisation.',
        'GEO_HIERARCHY' => 'Corriger le village, l\'arrondissement ou la commune sélectionnés pour qu\'ils soient cohérents ({attendu}).',
        'ROSTER_MEMBERS' => 'Vérifier avec le binôme le nombre de personnes du ménage ({valeur} déclarées, {attendu}) : corriger le nombre déclaré, ou signaler les membres en trop ou manquants.',
        'ROSTER_CAREGIVERS' => 'Vérifier avec le binôme le nombre de mères / gardien(ne)s ({valeur} déclaré(s), {attendu}) : corriger le nombre déclaré, ou signaler le caregiver en trop ou manquant.',
        'ROSTER_CHILDREN' => 'Vérifier avec le caregiver le nombre d\'enfants de moins de 5 ans ({valeur} déclaré(s), {attendu}) : corriger le nombre déclaré, ou signaler l\'enfant en trop ou manquant.',
        'TEAM_VILLAGE' => 'Vérifier avec le binôme le village enquêté ({attendu}) : corriger le village coché ou l\'agent sélectionné, ou justifier.',
        'PARA_DURATION' => 'Échanger avec le binôme sur le déroulement de l\'interview ({valeur}) ; justifier, ou contrôler le ménage.',
        'PARA_OFF_HOURS' => 'Vérifier l\'heure de la tablette et les conditions de l\'interview ({valeur}) avec le binôme, puis justifier.',
        'PARA_TODAY_START' => 'Vérifier et régler la date et l\'heure de la tablette du binôme, puis justifier la soumission.',
        'PARA_DAILY_VOLUME' => 'Revoir avec le superviseur la charge du binôme ce jour-là ({valeur} interviews) et contrôler un échantillon de ses interviews.',
        'PARA_LATE_SUBMISSION' => 'Rappeler au binôme d\'envoyer les formulaires chaque jour et vérifier la connectivité de la tablette.',
        'OUTLIER' => 'Vérifier la valeur {valeur} de « {question} » avec le binôme ; corriger s\'il s\'agit d\'une erreur de saisie, sinon justifier.',
    ];

    public function up(): void
    {
        foreach (self::PREVIOUS as $code => $previous) {
            $current = RuleCatalog::SUGGESTIONS[$code] ?? null;
            if ($current === null) {
                continue;
            }
            $updated = DB::table('quality_rules')->where('code', $code)->where(fn ($q) => $q->whereNull('suggestion')->orWhere('suggestion', $previous))
                ->update(['suggestion' => $current, 'updated_at' => now()]);
            if (! $updated || $code === 'TEAM_VILLAGE') {
                continue;
            }
            DB::table('data_queries')->where('rule_code', $code)->select(['id', 'variable', 'variable_label', 'observed_value', 'expected'])
                ->orderBy('id')->chunkById(500, function ($queries) use ($current) {
                    foreach ($queries as $query) {
                        DB::table('data_queries')->where('id', $query->id)->update(['suggestion' => mb_substr(strtr($current, [
                            '{question}' => $query->variable_label ?? $query->variable ?? '—',
                            '{variable}' => $query->variable ?? '—',
                            '{valeur}' => $query->observed_value ?? 'vide',
                            '{attendu}' => $query->expected ?? '—',
                        ]), 0, 2000)]);
                    }
                });
        }
    }

    public function down(): void
    {
        //
    }
};
