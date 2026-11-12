@props(['status'])
<span {{ $attributes->merge(['class' => 'badge text-bg-'.(\App\Models\DataQuery::STATUS_COLORS[$status] ?? 'secondary')]) }}>{{ \App\Models\DataQuery::STATUSES[$status] ?? $status }}</span>
