<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Dictionary\Dictionary;
use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;

/**
 * MAJ-11 : membre de moins de 15 ans (age_group ≤ 6) noté caregiver (q. 27). L'erreur peut venir de la réponse
 * « caregiver », de la tranche d'âge du membre ou de l'âge du caregiver interrogé : la query montre les trois, et la
 * fiche propose de corriger chacune. Le caregiver est retrouvé par ses initiales, sinon par son rang parmi les
 * membres notés caregivers (le formulaire ne relie pas les deux listes).
 */
class RosterYoungCaregiverRule extends RosterRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $members = self::members($context);
        $rows = fn (array $scopes) => array_map(fn (Scope $s) => ['path' => $s->path, 'data' => $s->data], $scopes);
        foreach ($members as $member) {
            if (! self::yes($member->own('person_caregiver')) || ! is_numeric($member->own('age_group')) || (int) $member->own('age_group') > 6) {
                continue;
            }
            $caregiver = self::matchCaregiver($rows($members), $rows($context->scopes('caregiver')), $member->path);
            $age = self::choice('age_group', $member->own('age_group'));
            $cgText = $caregiver
                ? sprintf('caregiver interrogé correspondant : n°%s%s, %s', self::position($caregiver['path']), self::initials($caregiver['data']['firstname'] ?? null),
                    is_numeric($caregiver['data']['age_years'] ?? null) ? $caregiver['data']['age_years'].' ans (q. 34)' : 'âge non renseigné')
                : 'aucun caregiver interrogé correspondant trouvé';

            yield QueryCandidate::for($member,
                sprintf('Membre n°%d%s : noté caregiver (q. 27), mais tranche d\'âge cochée %s (moins de 15 ans) ; %s.', $member->position,
                    self::initials($member->own('person_initials')), $age, $cgText),
                'person_caregiver',
                sprintf('Caregiver (q. 27) : Oui ; tranche d\'âge (q. 24) : %s ; %s', $age, $cgText),
                'Caregiver de 15 ans ou plus',
            );
        }
    }

    /**
     * Caregiver interrogé (repeat cg) correspondant à un membre du roster : mêmes initiales, sinon même rang parmi les
     * membres notés caregivers.
     *
     * @param  list<array{path: string, data: array}>  $members
     * @param  list<array{path: string, data: array}>  $caregivers
     * @return array{path: string, data: array}|null
     */
    public static function matchCaregiver(array $members, array $caregivers, string $memberPath): ?array
    {
        $member = collect($members)->firstWhere('path', $memberPath);
        $initials = mb_strtoupper(trim((string) ($member['data']['person_initials'] ?? '')));
        if ($initials !== '') {
            $same = array_values(array_filter($caregivers, fn ($c) => mb_strtoupper(trim((string) ($c['data']['firstname'] ?? ''))) === $initials));
            if (count($same) === 1) {
                return $same[0];
            }
        }
        $flagged = array_values(array_filter($members, fn ($m) => (string) ($m['data']['person_caregiver'] ?? '') === '1'));
        $rank = array_search($memberPath, array_column($flagged, 'path'), true);

        return $rank !== false ? ($caregivers[$rank] ?? null) : null;
    }

    private static function choice(string $list, mixed $code): string
    {
        return trim(strip_tags((string) (Dictionary::current()->choiceLabel($list, (string) $code) ?? $code)));
    }

    private static function initials(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? ' '.$value : '';
    }

    private static function position(string $path): string
    {
        return preg_match('/\[(\d+)\]$/', $path, $m) ? $m[1] : '?';
    }
}
