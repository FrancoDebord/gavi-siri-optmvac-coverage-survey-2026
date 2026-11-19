<?php

namespace App\Services\Quality;

/** Anomalie détectée par une règle, avant enregistrement comme query. */
final class QueryCandidate
{
    public function __construct(
        public string $message,
        public ?string $variable = null,
        public mixed $observed = null,
        public ?string $expected = null,
        public ?Scope $scope = null,
        public string $discriminator = '',
        /** Suggestion propre à ce cas ; sinon celle de la règle. */
        public ?string $suggestion = null,
        /** Valeur à proposer pour la correction (pré-remplie), déduite par la règle ; et d'où elle vient. */
        public ?string $suggestedValue = null,
        public ?string $suggestedNote = null,
        /** Binôme concerné en plus de celui de l'agent sélectionné (ex. binôme du compte ODK) : la query lui est confiée. */
        public ?int $teamId = null,
    ) {}

    public static function for(Scope $scope, string $message, ?string $variable = null, mixed $observed = null, ?string $expected = null): self
    {
        return new self($message, $variable, $observed, $expected, $scope);
    }

    /** Clé stable : une même anomalie retrouvée à la synchro suivante garde la même query. */
    public function fingerprint(string $ruleCode, int $householdId): string
    {
        return hash('sha256', implode('|', [$ruleCode, $householdId, $this->scope?->path ?? '', $this->variable ?? '', $this->discriminator]));
    }

    public function observedAsString(): ?string
    {
        if ($this->observed === null || $this->observed === '') {
            return null;
        }
        if (is_array($this->observed)) {
            return json_encode($this->observed, JSON_UNESCAPED_UNICODE);
        }

        return mb_substr((string) $this->observed, 0, 1000);
    }
}
