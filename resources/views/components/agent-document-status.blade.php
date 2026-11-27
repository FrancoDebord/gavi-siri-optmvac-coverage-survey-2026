@props(['status'])
{{-- Statut d'une facture ou d'un rapport d'activité : reçu, validé, à refaire. --}}
@php($class = match ($status) { 'valide' => 'text-bg-success', 'a_refaire' => 'text-bg-danger', default => 'text-bg-secondary' })
<span {{ $attributes->merge(['class' => 'badge '.$class]) }}>{{ \App\Models\AgentDocument::STATUSES[$status] ?? $status }}</span>
