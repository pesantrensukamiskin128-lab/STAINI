<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetingMinutes;
use App\Models\Event;
use App\Models\LecturerPosition;
use App\Helpers\QrCodeHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingMinutesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = MeetingMinutes::with(['creator','reviewer','signer','notulis','chairman','event']);
        if (!$user->can('notulen.create')) {
            $query->where(fn ($q) => $q->where('created_by',$user->id)->orWhere('reviewer_id',$user->id)->orWhere('signer_id',$user->id)->orWhere('notulis_id',$user->id));
        }
        return response()->json($query->when($request->search, fn($q) => $q->where('title','like',"%{$request->search}%"))
            ->when($request->status, fn($q) => $q->where('status',$request->status))
            ->orderByDesc('meeting_date')->paginate($request->per_page ?? 15));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data = $this->applyEventSnapshot($data);
        $data['created_by'] = auth()->id();
        $data['status'] = 'DRAFT';
        return response()->json(['message'=>'Notulen berhasil dibuat.','data'=>MeetingMinutes::create($data)->load(['event','reviewer','signer','notulis','chairman'])], 201);
    }

    public function show(MeetingMinutes $meetingMinute): JsonResponse
    {
        return response()->json($meetingMinute->load(['creator','reviewer','signer','notulis','chairman','event']));
    }

    public function update(Request $request, MeetingMinutes $meetingMinute): JsonResponse
    {
        if (!in_array($meetingMinute->status, ['DRAFT','REVISI_PEMERIKSA','REVISI_PENANDATANGAN'])) return response()->json(['message'=>'Notulen hanya dapat diedit saat draft/revisi.'],422);
        $meetingMinute->update($this->applyEventSnapshot($this->validated($request, true)));
        return response()->json(['message'=>'Notulen berhasil diupdate.','data'=>$meetingMinute->fresh()->load(['event','reviewer','signer','notulis','chairman'])]);
    }

    public function send(MeetingMinutes $meetingMinute): JsonResponse
    {
        if (!in_array($meetingMinute->status,['DRAFT','REVISI_PEMERIKSA','REVISI_PENANDATANGAN'])) return response()->json(['message'=>'Status notulen tidak dapat dikirim.'],422);
        $meetingMinute->update(['status'=>$meetingMinute->reviewer_id ? 'MENUNGGU_PEMERIKSA' : 'MENUNGGU_PENANDATANGAN','revision_note'=>null]);
        $target = $meetingMinute->reviewer_id ?: $meetingMinute->signer_id;
        if ($target) \App\Models\AppNotification::send($target,'Notulen Menunggu','Notulen "'.$meetingMinute->title.'" perlu ditindaklanjuti.','warning','/agenda/notulen/'.$meetingMinute->id);
        return response()->json(['message'=>'Notulen berhasil dikirim.','data'=>$meetingMinute->fresh()]);
    }

    public function review(Request $request, MeetingMinutes $meetingMinute): JsonResponse
    {
        if ($meetingMinute->status !== 'MENUNGGU_PEMERIKSA') return response()->json(['message'=>'Notulen tidak menunggu pemeriksaan.'],422);
        if ($request->input('action') === 'revise') {
            $request->validate(['revision_note'=>'required|string']);
            $meetingMinute->update(['status'=>'REVISI_PEMERIKSA','revision_note'=>$request->revision_note]);
            if ($meetingMinute->created_by) \App\Models\AppNotification::send($meetingMinute->created_by, 'Notulen Perlu Revisi', 'Notulen "'.$meetingMinute->title.'" dikembalikan oleh pemeriksa. Catatan: '.$request->revision_note, 'warning', '/agenda/notulen/'.$meetingMinute->id);
        } else {
            $meetingMinute->update(['status'=>'MENUNGGU_PENANDATANGAN','reviewed_at'=>now()]);
            foreach ([$meetingMinute->signer_id, $meetingMinute->notulis_id] as $target) {
                if ($target) \App\Models\AppNotification::send($target, 'Notulen Menunggu Tanda Tangan', 'Notulen "'.$meetingMinute->title.'" telah diperiksa dan menunggu tanda tangan Anda.', 'warning', '/agenda/notulen/'.$meetingMinute->id);
            }
        }
        return response()->json(['message'=>'Status pemeriksaan diperbarui.','data'=>$meetingMinute->fresh()]);
    }

    public function sign(Request $request, MeetingMinutes $meetingMinute): JsonResponse
    {
        if ($meetingMinute->status !== 'MENUNGGU_PENANDATANGAN') return response()->json(['message'=>'Notulen tidak menunggu tanda tangan.'],422);
        if ($request->input('action') === 'revise') {
            $request->validate(['revision_note'=>'required|string']);
            $meetingMinute->update(['status'=>'REVISI_PENANDATANGAN','revision_note'=>$request->revision_note]);
            if ($meetingMinute->created_by) \App\Models\AppNotification::send($meetingMinute->created_by, 'Notulen Perlu Revisi', 'Notulen "'.$meetingMinute->title.'" dikembalikan oleh penandatangan. Catatan: '.$request->revision_note, 'warning', '/agenda/notulen/'.$meetingMinute->id);
        } else {
            $userId = auth()->id();
            if ($meetingMinute->signer_id === $userId) {
                $meetingMinute->signed_at = now();
                if ($meetingMinute->notulis_id === $userId) {
                    $meetingMinute->notulis_signed_at = $meetingMinute->signed_at;
                }
            } elseif ($meetingMinute->notulis_id === $userId) {
                $meetingMinute->notulis_signed_at = now();
            } else {
                return response()->json(['message'=>'Anda bukan penandatangan atau notulis yang ditunjuk.'],403);
            }
            $meetingMinute->save();
            $meetingMinute->refresh();
            $otherSigner = $userId === $meetingMinute->signer_id ? $meetingMinute->notulis_id : $meetingMinute->signer_id;
            if ($otherSigner && $otherSigner !== $userId && $meetingMinute->status !== 'DITANDATANGANI') {
                \App\Models\AppNotification::send($otherSigner, 'Notulen Menunggu Tanda Tangan', 'Notulen "'.$meetingMinute->title.'" telah ditandatangani oleh pihak lain dan menunggu tanda tangan Anda.', 'warning', '/agenda/notulen/'.$meetingMinute->id);
            }
            $hasSigner = $meetingMinute->signed_at !== null;
            $hasNotulis = !$meetingMinute->notulis_id || $meetingMinute->notulis_signed_at !== null;
            if ($hasSigner && $hasNotulis) {
                $meetingMinute->status = 'DITANDATANGANI';
                $meetingMinute->number = $meetingMinute->number ?: $meetingMinute->generateNumber();
                $meetingMinute->save();
                if ($meetingMinute->created_by !== $userId) {
                    \App\Models\AppNotification::send($meetingMinute->created_by, 'Notulen Ditandatangani', 'Notulen "'.$meetingMinute->title.'" telah lengkap ditandatangani.', 'success', '/agenda/notulen/'.$meetingMinute->id);
                }
            }
        }
        return response()->json(['message'=>'Tanda tangan berhasil dicatat.','data'=>$meetingMinute->fresh()]);
    }

    public function share(MeetingMinutes $meetingMinute): JsonResponse
    {
        abort_unless($meetingMinute->status === 'DITANDATANGANI', 422, 'Notulen harus ditandatangani sebelum dibagikan.');
        $meetingMinute->load(['event.attendances.user', 'signer', 'notulis', 'chairman', 'reviewer']);
        $recipients = collect([$meetingMinute->signer, $meetingMinute->notulis, $meetingMinute->chairman, $meetingMinute->reviewer])
            ->merge($meetingMinute->event?->attendances?->pluck('user') ?? collect())
            ->filter(fn ($user) => $user?->is_active && $user->id !== auth()->id())
            ->unique('id');
        foreach ($recipients as $recipient) {
            \App\Models\AppNotification::send(
                $recipient->id,
                'Notulen Rapat Dibagikan',
                "Notulen \"{$meetingMinute->title}\" telah ditandatangani dan dibagikan kepada peserta rapat.",
                'info',
                '/agenda/notulen/'.$meetingMinute->id
            );
        }
        return response()->json(['message' => "Notulen dibagikan kepada {$recipients->count()} peserta.", 'recipients' => $recipients->count()]);
    }

    public function destroy(MeetingMinutes $meetingMinute): JsonResponse { $meetingMinute->delete(); return response()->json(['message'=>'Notulen berhasil dihapus.']); }

    public function signers(): JsonResponse
    {
        return response()->json($this->activeLecturers());
    }

    public function notulis(): JsonResponse
    {
        return response()->json($this->activeLecturers());
    }

    public function previewPdf(MeetingMinutes $meetingMinute) { return $this->pdf($meetingMinute, true); }
    public function downloadPdf(MeetingMinutes $meetingMinute) { if (!in_array($meetingMinute->status,['DITANDATANGANI','TERKIRIM'])) abort(422,'Notulen belum ditandatangani.'); return $this->pdf($meetingMinute, false); }

    private function pdf(MeetingMinutes $m, bool $preview)
    {
        $institution = \App\Models\Institution::first();
        $url = rtrim((string)config('app.frontend_url'),'/').'/verify/notulen/'.$m->verification_token;
        $pdf = Pdf::loadView('pdf.meeting-minutes',['minutes'=>$m->load(['signer','notulis','chairman','event']),'institution'=>$institution,'letterheadUrl'=>$institution?->letterhead_path ? storage_path('app/public/'.$institution->letterhead_path) : null,'verifyUrl'=>$url,'qrVerification'=>$preview ? '' : QrCodeHelper::generateWithLogo($url,140),'isFinal'=>!$preview])->setPaper('a4','portrait');
        return $preview ? $pdf->stream(($m->number ?: 'notulen-preview').'.pdf') : $pdf->download(str_replace('/','-',$m->number).'.pdf');
    }

    private function validated(Request $request, bool $sometimes=false): array
    {
        $p = $sometimes ? 'sometimes|' : '';
        $data = $request->validate([
            'event_id'=>$p.'nullable|exists:events,id','title'=>$p.'required|string|max:255','meeting_date'=>$p.'required|date',
            'location'=>'nullable|string|max:255','identity'=>'nullable|string','participants'=>'nullable|array','agenda'=>'nullable|string',
            'proceedings'=>'nullable|string','decisions'=>'nullable|string','follow_ups'=>'nullable|array','closing'=>'nullable|string',
            'reviewer_id'=>'nullable|exists:users,id','signer_id'=>$p.'required|exists:users,id',
            'chairman_id'=>'nullable|exists:users,id',
            'notulis_id'=>'nullable|exists:users,id',
        ]);
        if (array_key_exists('chairman_id', $data) && $data['chairman_id']) {
            $data['signer_id'] = $data['chairman_id'];
        }
        return $data;
    }

    private function activeLecturers()
    {
        return \App\Models\Lecturer::with('user')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->orderBy('full_name')->get()->map(fn ($lecturer) => [
                'user_id' => $lecturer->user?->id,
                'name' => $lecturer->display_name ?: $lecturer->user?->name,
                'position_name' => $lecturer->activePositions()->pluck('position_name')->filter()->implode(', '),
            ])->filter(fn ($lecturer) => $lecturer['user_id'])->values();
    }

    private function applyEventSnapshot(array $data): array
    {
        if (!array_key_exists('event_id', $data)) {
            return $data;
        }
        if (!$data['event_id']) {
            $data['participants'] = [];
            return $data;
        }

        $event = Event::with('attendances.user')->findOrFail($data['event_id']);
        $data['title'] = $event->title;
        $data['meeting_date'] = $event->event_date?->format('Y-m-d');
        $data['location'] = $event->location;
        $data['participants'] = $event->attendances->map(fn ($attendance) => [
            'name' => $attendance->user?->name ?? $attendance->guest_name ?? 'Tamu',
            'position' => $attendance->user?->lecturer?->activePositions?->pluck('position_name')->filter()->implode(', ')
                ?: ($attendance->guest_position ?: $attendance->guest_institution ?: 'Peserta'),
            'method' => $attendance->method,
            'attended_at' => $attendance->attended_at?->toDateTimeString(),
        ])->values()->all();

        return $data;
    }
}
