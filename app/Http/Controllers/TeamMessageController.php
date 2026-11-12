<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Collector;
use App\Models\HealthZone;
use App\Models\Team;
use App\Models\TeamMessage;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Messages de l'investigateur principal aux superviseurs, binômes et agents.
 * Les superviseurs lisent ceux de leur zone (et ceux qui leur sont adressés) et les relaient sur le terrain.
 */
class TeamMessageController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $messages = TeamMessage::visibleTo($user)->with(['sender:id,name', 'healthZone:id,name'])
            ->withExists(['readers as read_by_me' => fn ($q) => $q->whereKey($user->id)])
            ->withCount('readers')
            ->latest('id')->paginate(20);

        $zones = HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']);

        return view('messages.index', [
            'messages' => $messages,
            'canSend' => $user->can('send messages'),
            'unread' => TeamMessage::visibleTo($user)->unreadBy($user)->count(),
            'zones' => $zones,
            'supervisors' => User::role(User::ROLE_SUPERVISOR)->where('is_active', true)->with(['healthZone:id,name', 'commune:id,name'])->orderBy('name')->get(['id', 'name', 'health_zone_id', 'commune_id']),
            'teams' => Team::with('healthZone:id,name')->orderBy('code')->get(['id', 'code', 'name', 'health_zone_id']),
            'collectors' => Collector::with(['team:id,code', 'healthZone:id,name'])->orderBy('name')->get(['id', 'name', 'code', 'team_id', 'health_zone_id']),
            'defaults' => $request->only(['target_type', 'target_id', 'kind']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('send messages'), 403);
        // Liste unique « Destinataire » du formulaire : « type:id » (ex. team:12, all:).
        if ($request->filled('recipient')) {
            [$type, $id] = array_pad(explode(':', (string) $request->input('recipient'), 2), 2, null);
            $request->merge(['target_type' => $type, 'target_id' => $id !== '' ? $id : null]);
        }
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(TeamMessage::KINDS))],
            'target_type' => ['required', Rule::in(array_keys(TeamMessage::TARGETS))],
            'target_id' => [Rule::requiredIf($request->input('target_type') !== 'all'), 'nullable', 'integer'],
            'body' => ['required', 'string', 'max:2000'],
        ], [], ['kind' => 'type de message', 'target_type' => 'destinataire', 'target_id' => 'destinataire', 'body' => 'message']);

        $targetId = $data['target_type'] === 'all' ? null : (int) $data['target_id'];
        $zoneId = match ($data['target_type']) {
            'all' => null,
            'zone' => HealthZone::whereKey($targetId)->value('id'),
            'supervisor' => ($supervisor = User::role(User::ROLE_SUPERVISOR)->find($targetId)) ? $supervisor->supervisedZoneId() : false,
            'team' => Team::whereKey($targetId)->exists() ? Team::whereKey($targetId)->value('health_zone_id') : false,
            'collector' => Collector::whereKey($targetId)->exists() ? Collector::whereKey($targetId)->value('health_zone_id') : false,
        };
        if ($zoneId === false || ($data['target_type'] === 'zone' && ! $zoneId)) {
            throw ValidationException::withMessages(['target_id' => 'Destinataire introuvable.']);
        }

        $message = TeamMessage::create([
            'sender_id' => $request->user()->id,
            'target_type' => $data['target_type'],
            'target_id' => $targetId,
            'health_zone_id' => $zoneId,
            'kind' => $data['kind'],
            'body' => $data['body'],
        ]);
        AuditLog::record('message.sent', $message, ['type' => $message->kind, 'destinataire' => $message->targetLabel()]);

        return redirect()->route('messages.index')->with('success', 'Message envoyé : '.$message->targetLabel().'.');
    }

    /** Marque un message (ou, sans message, tous les messages visibles) comme lu par l'utilisateur. */
    public function markRead(Request $request, ?TeamMessage $message = null): RedirectResponse
    {
        $user = $request->user();
        $ids = $message
            ? TeamMessage::visibleTo($user)->whereKey($message->id)->pluck('id')
            : TeamMessage::visibleTo($user)->unreadBy($user)->pluck('id');
        abort_if($message && $ids->isEmpty(), 403);

        DB::table('team_message_reads')->insertOrIgnore($ids->map(fn ($id) => ['team_message_id' => $id, 'user_id' => $user->id, 'read_at' => now()])->all());

        return back()->with('success', $ids->count() > 1 ? $ids->count().' messages marqués comme lus.' : 'Message marqué comme lu.');
    }
}
