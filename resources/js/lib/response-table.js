import { fmt, pct } from './format';
import { initTableCopy, recapTable } from './ppt-tables';

/*
 * Récapitulatif des taux de réponse (composant x-response-table) : tableau de bord de progression et espace de
 * l'investigateur principal. Colonnes : all().response de ProgressStats (une par zone et son bras, puis l'ensemble).
 */
/** Libellés du récapitulatif des taux de réponse, en français et en anglais. */
const RESPONSE_LABELS = {
    fr: {
        empty: 'Aucun ménage visité pour ces filtres',
        visited: 'Ménages visités',
        no_children: 'Ménages sans enfant de moins de 5 ans',
        with_children: 'Ménages avec enfant(s) de moins de 5 ans',
        refused: 'Refus',
        no_access: 'Concession inaccessible',
        no_respondent: 'Personne à interroger introuvable',
        other: 'Autre issue',
        responded: 'Ont répondu',
        response_rate: 'Taux de réponse des ménages',
        caregivers: 'Caregivers interviewés',
        under5: 'Enfants de moins de 5 ans recensés',
        eligible: 'Enfants de 12 à 35 mois',
        girls: 'Filles (12–35 mois)',
        boys: 'Garçons (12–35 mois)',
    },
    en: {
        empty: 'No household visited for these filters',
        visited: 'Number of households surveyed',
        no_children: 'Households without children <5 yrs',
        with_children: 'Households with children <5 yrs',
        refused: 'Refused',
        no_access: 'No access',
        no_respondent: 'No respondent',
        other: 'Other outcome',
        responded: 'Responded',
        response_rate: 'Household response rate',
        caregivers: 'Number of caregivers',
        under5: 'Number of children <5 yrs listed',
        eligible: 'Number of children 12–35 months',
        girls: 'Girls (12–35 months)',
        boys: 'Boys (12–35 months)',
    },
};
let lastColumns = null;

/** Récapitulatif des taux de réponse (une colonne par zone sanitaire et son bras, puis l'ensemble), en fr ou en. */
export function renderResponseTable(columns = lastColumns) {
    lastColumns = columns;
    const el = document.getElementById('response-table');
    if (!el) return;
    const lang = document.querySelector('[name="response-lang"]:checked')?.value === 'en' ? 'en' : 'fr';
    const t = RESPONSE_LABELS[lang];
    if (!columns?.length) {
        el.innerHTML = `<p class="text-muted text-center mb-0">${t.empty}</p>`;

        return;
    }
    // Nombres : séparateur de milliers « , » et décimale « . » en anglais.
    const num = (v) => (lang === 'en' ? Number(v ?? 0).toLocaleString('en-US') : fmt(v));
    const rate = (v) => (v === null || v === undefined ? '—' : lang === 'en' ? `${v.toFixed(1)}%` : pct(v));
    const row = (key) => ({ label: t[key], value: (c) => num(c[key]) });
    const sections = [
        [row('visited'), row('no_children'), row('with_children')],
        [
            row('refused'), row('no_access'), row('no_respondent'),
            ...(columns.some((c) => c.other > 0) ? [row('other')] : []),
            row('responded'),
            { label: t.response_rate, value: (c) => rate(c.response_rate), strong: true },
        ],
        [row('caregivers'), row('under5'), row('eligible'), row('girls'), row('boys')],
    ];
    el.innerHTML = recapTable(columns, sections, (c) => c.header?.[lang] ?? c.label);
}

/** Bouton « Copier » et choix de la langue (Français / English) ; columns : données déjà connues (page serveur). */
export function initResponseTable(root, columns = undefined) {
    initTableCopy(root);
    document.querySelectorAll('[name="response-lang"]').forEach((input) => input.addEventListener('change', () => renderResponseTable()));
    if (columns !== undefined) renderResponseTable(columns);
}
