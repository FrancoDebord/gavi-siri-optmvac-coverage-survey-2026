<?php

namespace App\Services\Quality\Rules\Business;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\SubmissionContext;

class OffHoursRule extends BusinessRule
{
    protected function check(SubmissionContext $context): iterable
    {
        $start = $context->household->started_at;
        $end = $context->household->ended_at ?? $start;
        if (! $start) {
            return;
        }
        $open = $this->params->int('start_hour', 7);
        $close = $this->params->int('end_hour', 19);
        $tz = config('app.timezone');
        $startHour = (float) $start->copy()->setTimezone($tz)->format('G') + $start->minute / 60;
        $endHour = (float) $end->copy()->setTimezone($tz)->format('G') + $end->minute / 60;

        if ($startHour < $open || $endHour > $close || $end->toDateString() !== $start->toDateString()) {
            yield QueryCandidate::for(
                $context->root,
                sprintf('Interview hors heures de travail : %s – %s (plage %02dh–%02dh)', $start->setTimezone($tz)->format('d/m/Y H:i'), $end->setTimezone($tz)->format('H:i'), $open, $close),
                'start',
                $start->setTimezone($tz)->format('H:i'),
                sprintf('%02dh–%02dh', $open, $close),
            );
        }
    }
}
