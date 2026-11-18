<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\User;
use App\Support\DateInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Piste d'audit unifiée (ALCOA+) : journal système (connexions, paramètres, imports, exports, gels…), historique
 * des queries (affectations, réponses, validations, signatures) et registre des corrections (propositions et
 * décisions), filtrable par utilisateur, source, action et période ; export CSV.
 */
class AuditLogController extends Controller
{
    public const SOURCES = ['journal' => 'Journal système', 'query' => 'Queries', 'correction' => 'Corrections'];

    public function index(Request $request): View
    {
        [$filters, $query] = $this->trail($request);

        return view('audit.index', [
            'filters' => $filters,
            'entries' => $query->orderByDesc('occurred_at')->orderByDesc('ref_id')->paginate(50)->withQueryString(),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'actions' => AuditLog::select('action')->distinct()->orderBy('action')->pluck('action'),
            'names' => User::pluck('name', 'id'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$filters, $query] = $this->trail($request);
        AuditLog::record('export.piste_audit', null, array_filter($filters));
        $names = User::pluck('name', 'id');

        return response()->streamDownload(function () use ($query, $names) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['date', 'utilisateur', 'source', 'action', 'reference', 'objet', 'details'], ',', '"', '');
            foreach ($query->orderBy('occurred_at')->lazy(1000) as $entry) {
                $row = self::describe($entry);
                fputcsv($out, [$entry->occurred_at, $names[$entry->user_id] ?? 'Système', self::SOURCES[$entry->source], $row['action'], $row['reference'], $row['object'], $row['details']], ',', '"', '');
            }
            fclose($out);
        }, 'piste-audit-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Union des trois sources, mêmes colonnes, puis filtres. */
    private function trail(Request $request): array
    {
        $filters = [
            'user_id' => $request->integer('user_id') ?: null,
            'source' => array_key_exists((string) $request->input('source'), self::SOURCES) ? $request->input('source') : null,
            'action' => is_string($request->input('action')) && $request->input('action') !== '' ? $request->input('action') : null,
            'du' => DateInput::parse($request->input('du')),
            'au' => DateInput::parse($request->input('au')),
        ];

        $journal = DB::table('audit_logs')->select(['created_at as occurred_at', 'user_id', DB::raw("'journal' as source"), 'action', 'subject_id as ref_id', 'subject_type as ref_type', 'properties as detail']);
        $queries = DB::table('query_events')->select(['created_at as occurred_at', 'user_id', DB::raw("'query' as source"), 'type as action', 'data_query_id as ref_id', 'to_status as ref_type', 'comment as detail']);
        $proposed = DB::table('data_corrections')->select(['proposed_at as occurred_at', 'proposed_by as user_id', DB::raw("'correction' as source"), DB::raw("'proposee' as action"), 'id as ref_id', 'variable as ref_type', 'new_value as detail']);
        $decided = DB::table('data_corrections')->whereNotNull('decided_at')->select(['decided_at as occurred_at', 'decided_by as user_id', DB::raw("'correction' as source"), 'status as action', 'id as ref_id', 'variable as ref_type', 'decision_comment as detail']);

        $query = DB::query()->fromSub($journal->unionAll($queries)->unionAll($proposed)->unionAll($decided), 'trail')
            ->when($filters['user_id'], fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['source'], fn ($q, $source) => $q->where('source', $source))
            ->when($filters['action'], fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['du'], fn ($q, $date) => $q->where('occurred_at', '>=', $date.' 00:00:00'))
            ->when($filters['au'], fn ($q, $date) => $q->where('occurred_at', '<=', $date.' 23:59:59'));

        return [$filters, $query];
    }

    /** @return array{action: string, reference: string, object: string, details: string} */
    public static function describe(object $entry): array
    {
        return match ($entry->source) {
            'query' => [
                'action' => match ($entry->action) {
                    'status' => 'Statut'.($entry->ref_type ? ' → '.(DataQuery::STATUSES[$entry->ref_type] ?? $entry->ref_type) : ''),
                    'assignment' => 'Affectation',
                    'correction' => 'Corrections validées',
                    'comment' => 'Commentaire',
                    default => 'Système'.($entry->ref_type ? ' → '.(DataQuery::STATUSES[$entry->ref_type] ?? $entry->ref_type) : ''),
                },
                'reference' => DataQuery::codeFor((int) $entry->ref_id),
                'object' => 'Query',
                'details' => (string) $entry->detail,
            ],
            'correction' => [
                'action' => $entry->action === 'proposee' ? 'Correction proposée' : 'Correction '.mb_strtolower(DataCorrection::STATUSES[$entry->action] ?? $entry->action),
                'reference' => 'C-'.str_pad((string) $entry->ref_id, 6, '0', STR_PAD_LEFT),
                'object' => $entry->ref_type === DataCorrection::EXCLUSION ? 'Exclusion de la soumission' : (string) $entry->ref_type,
                'details' => $entry->action === 'proposee' ? 'Nouvelle valeur : '.DataCorrection::display($entry->detail) : (string) $entry->detail,
            ],
            default => [
                'action' => (string) $entry->action,
                'reference' => $entry->ref_type ? class_basename($entry->ref_type).' #'.$entry->ref_id : '',
                'object' => $entry->ref_type ? class_basename($entry->ref_type) : '',
                'details' => (string) $entry->detail,
            ],
        };
    }
}
