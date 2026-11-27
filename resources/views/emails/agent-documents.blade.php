@extends('emails.layout')

@section('title', 'Factures et rapports d\'activité')
@section('preheader', $counts->sum().' document(s) · '.$space->name.' · lien de téléchargement valable jusqu\'au '.$expires->format('d/m/Y').'.')

@section('content')
    <p style="margin:0 0 6px; font-size:13px; font-weight:600; letter-spacing:1px; text-transform:uppercase; color:#c10202;">Factures et rapports d'activité</p>
    <h1 style="margin:0 0 16px; font-size:22px; line-height:30px; color:#1f1f1f;">{{ $space->name }}</h1>

    @if ($note)
        <p style="margin:0 0 16px; font-size:15px; line-height:24px;">{!! nl2br(e($note)) !!}</p>
    @endif

    <p style="margin:0 0 16px; font-size:15px; line-height:24px;">
        @foreach (\App\Models\AgentDocument::TYPES as $type => $label)
            <strong>{{ $counts[$type] }}</strong> {{ mb_strtolower($label) }}{{ $counts[$type] > 1 ? 's' : '' }}@if (! $loop->last) et @endif
        @endforeach
        de <strong>{{ $rows->count() }}</strong> agent(s), {{ number_format($size / 1048576, 1, ',', ' ') }} Mo.
        @if ($attached) Les fichiers sont joints à ce message ; ils sont aussi téléchargeables en un seul fichier ZIP. @endif
    </p>

    {{-- Bouton de téléchargement --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">
        <tr>
            <td style="border-radius:8px; background-color:#c10202;">
                <a href="{{ $link }}" style="display:inline-block; padding:13px 28px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:8px;">Télécharger les documents (ZIP)</a>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 24px; font-size:12px; line-height:18px; color:#706d6b;">Lien personnel, valable jusqu'au {{ $expires->format('d/m/Y à H:i') }}, sans compte. Un dossier par agent dans l'archive.</p>

    {{-- Récapitulatif par agent --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px; line-height:18px; border-collapse:collapse; margin:0 0 20px;">
        <tr>
            <th align="left" style="padding:8px; background-color:#1f1f1f; color:#ffffff;">Agent</th>
            @foreach (\App\Models\AgentDocument::TYPES as $label)
                <th align="left" style="padding:8px; background-color:#1f1f1f; color:#ffffff;">{{ $label }}</th>
            @endforeach
        </tr>
        @foreach ($rows as $row)
            <tr>
                <td style="padding:7px 8px; border-bottom:1px solid #ece8e6;">{{ $row['agent'] }}@if ($row['team'])<div style="font-size:11px; color:#706d6b;">Binôme {{ $row['team'] }}</div>@endif</td>
                @foreach (array_keys(\App\Models\AgentDocument::TYPES) as $type)
                    <td style="padding:7px 8px; border-bottom:1px solid #ece8e6;">
                        @if ($row[$type])
                            {{ $row[$type]['status'] }}@if ($row[$type]['count'] > 1) <span style="color:#706d6b;">({{ $row[$type]['count'] }} fichiers)</span>@endif
                        @else
                            <span style="color:#706d6b;">—</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endsection

@section('footer')
    {{ $sender ? 'Envoyé par '.$sender->name.'. Pour toute question, répondez à ce message.' : 'Message envoyé par la plateforme.' }}
@endsection
