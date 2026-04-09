<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\Presentation;
use App\Models\Slide;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    // المقدم يبدأ جلسة جديدة
    public function start(Request $request, $presentationId)
    {
        $request->validate([
            'session_settings' => 'nullable|array',
        ]);

        $presentation = Presentation::where('user_id', auth()->id())
            ->findOrFail($presentationId);

        // أغلق أي جلسة سابقة نشطة
        Session::where('presentation_id', $presentationId)
            ->whereIn('status', ['waiting', 'active'])
            ->update(['status' => 'finished', 'ended_at' => now()]);

        // توليد كود فريد 6 أرقام
        do {
            $code = (string) random_int(100000, 999999);
        } while (Session::where('access_code', $code)->whereIn('status', ['waiting', 'active'])->exists());

        $session = Session::create([
            'presentation_id' => $presentationId,
            'access_code'     => $code,
            'status'          => 'waiting',
            'session_settings' => $request->session_settings ?? [
                'require_name'     => true,
                'show_leaderboard' => true,
                'allow_chat'       => false,
                'session_type'     => 'in_person',
            ],
        ]);

        return response()->json([
            'status' => true,
            'data'   => [
                'session_id'  => $session->id,
                'access_code' => $session->access_code,
                'join_url'    => config('app.url') . '/join/' . $session->access_code,
                'status'      => $session->status,
                'settings'    => $session->session_settings,
            ],
        ]);
    }

   public function launch($sessionId)
{
    $session = Session::whereHas('presentation', fn($q) =>
        $q->where('user_id', auth()->id())
    )->where('id', $sessionId)
     ->whereIn('status', ['waiting', 'active'])  // ← غيّري هنا
     ->firstOrFail();

    // فقط لو waiting تحوّلها لـ active
    if ($session->status === 'waiting') {
        $session->update([
            'status'     => 'active',
            'started_at' => now(),
        ]);
    }

    return response()->json([
        'status' => true,
        'data'   => ['status' => $session->status],
    ]);
}

public function changeSlide(Request $request, $sessionId)
{
    $request->validate([
        'slide_id'    => 'required|integer',
        'slide'       => 'nullable|array',
        'template_id' => 'nullable|integer',
    ]);

    $session = Session::whereHas('presentation', fn($q) =>
        $q->where('user_id', auth()->id())
    )->where('id', $sessionId)
     ->where('status', 'active')
     ->firstOrFail();

    $updateData = ['current_slide_id' => $request->slide_id];

    if ($request->has('slide') && $request->slide) {
        $updateData['current_slide_data'] = $request->slide;

        $layout       = $request->slide['layout']       ?? null;
        $questionData = $request->slide['questionData'] ?? null;
        $timer        = $questionData['timer']          ?? null;

        if ($layout === 'QUESTION' && $timer) {
            // ✅ سؤال جديد — ابدأ العداد
            $updateData['timer_duration']   = (int) $timer;
            $updateData['timer_started_at'] = now();
        } else {
            // ✅ شريحة عادية — امسح العداد
            $updateData['timer_duration']   = null;
            $updateData['timer_started_at'] = null;
        }
    }

    if ($request->has('template_id')) {
        $session->presentation->update(['template_id' => $request->template_id]);
    }

    $session->update($updateData);
    return response()->json(['status' => true]);
}

    // إنهاء الجلسة
    public function end($sessionId)
    {
        $session = Session::whereHas('presentation', fn($q) =>
            $q->where('user_id', auth()->id())
        )->where('id', $sessionId)
         ->whereIn('status', ['waiting', 'active'])
         ->firstOrFail();

        $session->update([
            'status'   => 'finished',
            'ended_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'data'   => [
                'participants_count' => $session->participants()->count(),
            ],
        ]);
    }

    // التحقق من الكود قبل الانضمام
    public function info($code)
    {
        $session = Session::where('access_code', $code)
            ->whereIn('status', ['waiting', 'active'])
            ->with('presentation:id,title')
            ->first();

        if (!$session) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid or expired code.',
            ], 404);
        }

        $settings = $session->session_settings ?? [];

        return response()->json([
            'status' => true,
            'data'   => [
                'session_id'         => $session->id,
                'session_status'     => $session->status,
                'presentation_title' => $session->presentation->title,
                'require_name'       => $settings['require_name'] ?? true,
                'participants_count' => $session->participants()->count(),
            ],
        ]);
    }

    // الانضمام للجلسة
    public function join(Request $request)
    {
        $request->validate([
            'code'         => 'required|string|size:6',
            'nickname'     => 'nullable|string|max:50',
            'device_token' => 'nullable|string',
        ]);

        $session = Session::where('access_code', $request->code)
            ->whereIn('status', ['waiting', 'active'])
            ->first();

        if (!$session) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid or expired code.',
            ], 404);
        }

        $settings     = $session->session_settings ?? [];
        $requireName  = $settings['require_name'] ?? true;

        if ($requireName && empty($request->nickname)) {
            return response()->json([
                'status'       => false,
                'message'      => 'Name is required for this session.',
                'require_name' => true,
            ], 422);
        }

        // device_token للتمييز بين الأجهزة
        $deviceToken = $request->device_token
            ?: ($request->header('X-Device-Token') ?: Str::uuid()->toString());

        // تجنب التكرار
        $participant = $session->participants()
            ->where('device_token', $deviceToken)
            ->first();

        if (!$participant) {
            $participant = $session->participants()->create([
                'nickname'     => $request->nickname ?: 'Anonymous',
                'device_token' => $deviceToken,
                'ip_address'   => $request->ip(),
            ]);
        }

        // جلب شرائح العرض
        $slides = $session->presentation
            ->slides()
            ->orderBy('order')
            ->get(['id', 'order', 'type', 'category', 'content', 'settings']);

        return response()->json([
            'status' => true,
            'data'   => [
                'participant_id'   => $participant->id,
                'device_token'     => $deviceToken,
                'nickname'         => $participant->nickname,
                'session_id'       => $session->id,
                'session_status'   => $session->status,
                'current_slide_id' => $session->current_slide_id,
                'presentation'     => [
                    'title'  => $session->presentation->title,
                    'slides' => $slides,
                ],
            ],
        ]);
    }
    // المشارك يسأل: هل تغيرت الشريحة؟
    public function status($sessionId)
    {
        $session = Session::findOrFail($sessionId);

        return response()->json([
            'status' => true,
            'data'   => [
                'session_status'   => $session->status,
                'current_slide_id' => $session->current_slide_id,
                'is_voting_open'   => $session->is_voting_open,
                'show_results'     => $session->show_results,
            ],
        ]);
    }

    // الجلسة النشطة الحالية 
    public function current($presentationId)
    {
        Presentation::where('user_id', auth()->id())->findOrFail($presentationId);

        $session = Session::where('presentation_id', $presentationId)
            ->whereIn('status', ['waiting', 'active'])
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['status' => true, 'data' => null]);
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'session_id'         => $session->id,
                'access_code'        => $session->access_code,
                'join_url'           => config('app.url') . '/join/' . $session->access_code,
                'status'             => $session->status,
                'participants_count' => $session->participants()->count(),
                'settings'           => $session->session_settings,
            ],
        ]);
    }

    public function currentSlide($sessionId)
{
    $session = Session::findOrFail($sessionId);

    if (!$session->current_slide_id) {
        return response()->json(['status' => true, 'data' => ['slide' => null]]);
    }

    $slide = $session->presentation
        ->slides()
        ->where('id', $session->current_slide_id)
        ->first();

    if (!$slide) {
        return response()->json(['status' => true, 'data' => ['slide' => null]]);
    }

    $content = is_string($slide->content)
        ? (json_decode($slide->content, true) ?? [])
        : ($slide->content ?? []);

    $slideData = array_merge($content, [
        'id'           => $slide->id,
        'layout'       => $content['layout']        ?? $slide->type ?? 'BLANK',
        'title'        => $content['title']         ?? '',
        'subtitle'     => $content['subtitle']      ?? '',
        'content'      => $content['content']       ?? '',
        'leftContent'  => $content['leftContent']   ?? '',
        'rightContent' => $content['rightContent']  ?? '',
        'images'       => $content['images']        ?? [],
        'shapes'       => $content['shapes']        ?? [],
        'tables'       => $content['tables']        ?? [],
        'elements'     => $content['elements']      ?? [],
        'background'   => $content['background']    ?? null,
        'titleStyle'   => $content['titleStyle']    ?? (object)[],
        'subtitleStyle'=> $content['subtitleStyle'] ?? (object)[],
        'contentStyle' => $content['contentStyle']  ?? (object)[],
        'questionData' => $content['questionData']  ?? null,
        'questionType' => $content['questionType']  ?? null,
    ]);

    // ✅ احسب الوقت المتبقي من السيرفر
    $timerInfo = null;
    if ($session->timer_started_at && $session->timer_duration) {
        $elapsed   = now()->diffInSeconds($session->timer_started_at);
        $remaining = max(0, $session->timer_duration - $elapsed);

        $timerInfo = [
            'duration'          => $session->timer_duration,
            'started_at'        => (string) $session->timer_started_at,
            'seconds_remaining' => (int) $remaining,
            'is_expired'        => $remaining <= 0,
        ];
    }

    return response()->json([
        'status' => true,
        'data'   => [
            'slide'       => $slideData,
            'template_id' => $session->presentation->template_id ?? 0,
            'timer'       => $timerInfo, // ✅ هذا الجديد
        ]
    ]);
}
    public function participants($sessionId)
    {
        $session = Session::whereHas('presentation', fn($q) =>
            $q->where('user_id', auth()->id())
        )->findOrFail($sessionId);

        $participants = $session->participants()
            ->orderBy('created_at')
            ->get(['id', 'nickname', 'created_at']);

        return response()->json([
            'status' => true,
            'data'   => [
                'session_status'    => $session->status,
                'participants_count' => $participants->count(),
                'participants'      => $participants,
            ],
        ]);
    }
    public function submitAnswer(Request $request, $id)
{
    $data = $request->validate([
        'slide_id'       => 'required|string',
        'answer_index'   => 'nullable|integer',
        'answer_value'   => 'nullable|string',
        'device_token'   => 'required|string',
        'time_taken'     => 'nullable|integer',
    ]);

    // إيجاد المشارك من device_token
    $participant = \App\Models\Participant::where('session_id', $id)
        ->where('device_token', $data['device_token'])
        ->first();

    if (!$participant) {
        return response()->json(['status' => false, 'message' => 'Participant not found'], 404);
    }

    // منع التصويت مرتين
    $exists = \App\Models\Response::where('session_id', $id)
        ->where('slide_id', $data['slide_id'])
        ->where('participant_id', $participant->id)
        ->exists();

    if ($exists) {
        return response()->json(['status' => false, 'message' => 'Already answered'], 409);
    }

    $response = \App\Models\Response::create([
        'session_id'     => $id,
        'slide_id'       => $data['slide_id'],
        'participant_id' => $participant->id,
        'answer_index'   => $data['answer_index'] ?? null,
        'answer_value'   => $data['answer_value'] ?? null,
        'time_taken'     => $data['time_taken'] ?? 0,
    ]);

    return response()->json(['status' => true, 'data' => $response]);
}

public function revealResults(Request $request, $sessionId)
{
    $session = Session::whereHas('presentation', fn($q) =>
        $q->where('user_id', auth()->id())
    )->findOrFail($sessionId);
 
    $session->update(['show_results' => true]);
 
    return response()->json(['status' => true]);
}
 
public function hideResults(Request $request, $sessionId)
{
    $session = Session::whereHas('presentation', fn($q) =>
        $q->where('user_id', auth()->id())
    )->findOrFail($sessionId);
 
    $session->update(['show_results' => false]);
 
    return response()->json(['status' => true]);
}
 
public function slideResults($sessionId, $slideId)
{
    $session = Session::findOrFail($sessionId);
 
    $results = \App\Models\Response::where('session_id', $sessionId)
        ->where('slide_id', $slideId)
        ->selectRaw('answer_index, COUNT(*) as count')
        ->groupBy('answer_index')
        ->orderBy('answer_index')
        ->get();
 
    $total = \App\Models\Response::where('session_id', $sessionId)
        ->where('slide_id', $slideId)
        ->count();
 
    $correct = \App\Models\Response::where('session_id', $sessionId)
        ->where('slide_id', $slideId)
        ->where('is_correct', true)
        ->count();
 
    return response()->json([
        'status' => true,
        'data'   => [
            'results'          => $results,
            'total_responses'  => $total,
            'correct_count'    => $correct,
            'incorrect_count'  => $total - $correct,
            'correct_percent'  => $total > 0 ? round(($correct / $total) * 100) : 0,
            'show_results'     => $session->show_results ?? false,
        ]
    ]);
}
 
// ── 4) تقرير كامل بعد انتهاء الجلسة ─────────────────────────
public function generateReport($sessionId)
{
    $session = Session::whereHas('presentation', fn($q) =>
        $q->where('user_id', auth()->id())
    )->with('presentation')->findOrFail($sessionId);
 
    $participants = $session->participants()->count();
 
    // جلب كل الإجابات مع تجميعها
    $allResponses = \App\Models\Response::where('session_id', $sessionId)
        ->selectRaw('slide_id, answer_index, COUNT(*) as count, SUM(is_correct) as correct_count, AVG(time_taken) as avg_time')
        ->groupBy('slide_id', 'answer_index')
        ->get();
 
    // تجميع البيانات لكل شريحة
    $slideStats = [];
    foreach ($allResponses as $r) {
        $sid = $r->slide_id;
        if (!isset($slideStats[$sid])) {
            $slideStats[$sid] = [
                'slide_id'      => $sid,
                'total'         => 0,
                'correct'       => 0,
                'options'       => [],
                'avg_time'      => 0,
            ];
        }
        $slideStats[$sid]['total']         += $r->count;
        $slideStats[$sid]['correct']       += $r->correct_count;
        $slideStats[$sid]['avg_time']       = round($r->avg_time, 1);
        $slideStats[$sid]['options'][]      = [
            'index' => $r->answer_index,
            'count' => $r->count,
        ];
    }
 
    // إجمالي النقاط لكل مشارك
    $leaderboard = \App\Models\Response::where('session_id', $sessionId)
        ->join('participants', 'responses.participant_id', '=', 'participants.id')
        ->selectRaw('participants.nickname, SUM(responses.points) as total_points, SUM(responses.is_correct) as correct_answers')
        ->groupBy('participants.id', 'participants.nickname')
        ->orderByDesc('total_points')
        ->limit(20)
        ->get();
 
    $report = [
        'session_id'        => $sessionId,
        'presentation_title'=> $session->presentation->title,
        'total_participants'=> $participants,
        'total_questions'   => count($slideStats),
        'slide_stats'       => array_values($slideStats),
        'leaderboard'       => $leaderboard,
        'generated_at'      => now()->toDateTimeString(),
    ];
 
    // حفظ في جدول reports
    \App\Models\Report::updateOrCreate(
        ['session_id' => $sessionId],
        ['total_participants' => $participants, 'summary_data' => $report]
    );
 
    return response()->json([
        'status' => true,
        'data'   => $report,
    ]);
}
 
}
