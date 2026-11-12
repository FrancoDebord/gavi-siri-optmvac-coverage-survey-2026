@props(['severity'])
<span {{ $attributes->merge(['class' => 'badge badge-severity-'.$severity]) }}>{{ \App\Models\QualityRule::SEVERITIES[$severity] ?? $severity }}</span>
