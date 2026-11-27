FACTURES ET RAPPORTS D'ACTIVITÉ — {!! $space->name !!}

@if ($note)
{!! $note !!}

@endif
@foreach (\App\Models\AgentDocument::TYPES as $type => $label)
{!! $counts[$type] !!} {!! mb_strtolower($label) !!}(s)
@endforeach
{!! $rows->count() !!} agent(s), {!! number_format($size / 1048576, 1, ',', ' ') !!} Mo.{!! $attached ? ' Fichiers joints à ce message.' : '' !!}

Télécharger les documents (ZIP, un dossier par agent) :
{!! $link !!}
Lien personnel, valable jusqu'au {!! $expires->format('d/m/Y à H:i') !!}, sans compte.

RÉCAPITULATIF PAR AGENT
@foreach ($rows as $row)
- {!! $row['agent'] !!}{!! $row['team'] ? ' (binôme '.$row['team'].')' : '' !!} : @foreach (\App\Models\AgentDocument::TYPES as $type => $label){!! $label !!} {!! $row[$type] ? mb_strtolower($row[$type]['status']).($row[$type]['count'] > 1 ? ' ('.$row[$type]['count'].' fichiers)' : '') : 'absent' !!}{!! $loop->last ? '' : ' · ' !!}@endforeach

@endforeach

{!! $sender ? 'Envoyé par '.$sender->name.'. Pour toute question, répondez à ce message.' : 'Message envoyé par la plateforme.' !!}
AIRID — {!! config('app.name') !!}
