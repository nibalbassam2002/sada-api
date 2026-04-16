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
     ->whereIn('status', ['waiting', 'active']) 
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
     ->whereIn('status', ['waiting', 'active'])
     ->firstOrFail();

    $updateData = ['current_slide_id' => $request->slide_id];

    if ($request->has('slide') && $request->slide) {
        $updateData['current_slide_data'] = $request->slide;

        $layout       = $request->slide['layout'] ?? null;
        $questionData = $request->slide['questionData'] ?? null;
        
        $isQuestion = ($layout === 'QUESTION' || !empty($questionData));

        if ($isQuestion) {
            // قراءة القيم من إعدادات السؤال (مع القيم الافتراضية)
            $totalDuration = (int) ($questionData['total_duration'] ?? 900);   // 15 دقيقة
            $userDuration  = (int) ($questionData['user_duration'] ?? 30);     // 30 ثانية
            
            $now = now();
            
            // هل هذا سؤال جديد أم نفس السؤال؟
            $previousSlideId = $session->current_slide_id;
            $isNewQuestion = ($previousSlideId != $request->slide_id);
            
            if ($isNewQuestion || !$session->question_started_at) {
                // سؤال جديد → نبدأ الوقت من الآن
                $updateData['question_started_at'] = $now;
            } else {
                // نفس السؤال → نبقى الوقت القديم
                $updateData['question_started_at'] = $session->question_started_at;
            }
            
            $updateData['question_total_duration'] = $totalDuration;
            $updateData['question_user_duration'] = $userDuration;
            
            // هل انتهى الوقت الكلي للسؤال؟
            $expectedEnd = $updateData['question_started_at']->copy()->addSeconds($totalDuration);
            if ($now->greaterThanOrEqualTo($expectedEnd)) {
                $updateData['question_ended_at'] = $expectedEnd;
                $updateData['timer_expired'] = true;
            } else {
                $updateData['question_ended_at'] = null;
                $updateData['timer_expired'] = false;
                $updateData['timer_duration'] = null;
                $updateData['timer_started_at'] = null;
            }
        } else {
            // ليس سؤالاً → نمسح كل شيء
            $updateData['question_started_at'] = null;
            $updateData['question_ended_at'] = null;
            $updateData['question_total_duration'] = null;
            $updateData['question_user_duration'] = null;
            $updateData['timer_duration'] = null;
            $updateData['timer_started_at'] = null;
            $updateData['timer_expired'] = false;
        }
    }

    if ($request->has('template_id')) {
        $session->presentation->update(['template_id' => $request->template_id]);
    }

    $session->update($updateData);
    
    return response()->json([
        'status' => true,
        'data'   => [
            'question_started_at' => $session->fresh()->question_started_at,
            'question_total_duration' => $session->fresh()->question_total_duration,
            'question_user_duration' => $session->fresh()->question_user_duration,
            'question_ended_at' => $session->fresh()->question_ended_at,
        ]
    ]);
}
  public function end($sessionId)
{
    $session = Session::whereHas('presentation', fn($q) =>
        $q->where('user_id', auth()->id())
    )->where('id', $sessionId)
     ->firstOrFail();

    if ($session->status === 'finished') {
        return response()->json(['status' => true, 'data' => ['already_ended' => true]]);
    }

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
        'layout'       => $content['layout'] ?? $slide->type ?? 'BLANK',
        'title'        => $content['title'] ?? '',
        'subtitle'     => $content['subtitle'] ?? '',
        'content'      => $content['content'] ?? '',
        'leftContent'  => $content['leftContent'] ?? '',
        'rightContent' => $content['rightContent'] ?? '',
        'images'       => $content['images'] ?? [],
        'shapes'       => $content['shapes'] ?? [],
        'tables'       => $content['tables'] ?? [],
        'elements'     => $content['elements'] ?? [],
        'background'   => $content['background'] ?? null,
        'titleStyle'   => $content['titleStyle'] ?? (object)[],
        'subtitleStyle'=> $content['subtitleStyle'] ?? (object)[],
        'contentStyle' => $content['contentStyle'] ?? (object)[],
        'questionData' => $content['questionData'] ?? null,
        'questionType' => $content['questionType'] ?? null,
    ]);

    // ✅ معلومات السؤال (مهمة للفرونت)
    $questionInfo = null;
    
    if ($session->question_started_at && $session->question_total_duration) {
        $now = now();
        $questionEndsAt = $session->question_started_at->copy()->addSeconds($session->question_total_duration);
        
        // هل انتهى السؤال يدوياً أم تلقائياً؟
        $isManuallyClosed = !is_null($session->question_ended_at);
        $isTimeExpired = $now->greaterThanOrEqualTo($questionEndsAt);
        $isExpired = $isManuallyClosed || $isTimeExpired;
        
        if (!$isExpired) {
            $questionInfo = [
                'is_active' => true,
                'total_duration' => $session->question_total_duration,
                'user_duration' => $session->question_user_duration ?? 30,
                'question_started_at' => $session->question_started_at->toISOString(),
                'question_ends_at' => $questionEndsAt->toISOString(),
            ];
        } else {
            $questionInfo = [
                'is_active' => false,
                'reason' => $isManuallyClosed ? 'manual' : 'timeout',
                'question_ended_at' => $session->question_ended_at ?? $questionEndsAt->toISOString(),
            ];
        }
    }

    return response()->json([
        'status' => true,
        'data'   => [
            'slide' => $slideData,
            'template_id' => $session->presentation->template_id ?? 0,
            'question_info' => $questionInfo,
        ]
    ]);
}
public function expireTimer($sessionId)
{
    $session = Session::findOrFail($sessionId);
    $session->update(['timer_expired' => true]);
    return response()->json(['status' => true]);
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

    $session = Session::findOrFail($id);
    $participant = $session->participants()
        ->where('device_token', $data['device_token'])
        ->first();

    if (!$participant) {
        return response()->json(['status' => false, 'message' => 'Participant not found'], 404);
    }

    // 1. التحقق: هل يوجد سؤال نشط؟
    if (!$session->question_started_at || !$session->question_total_duration) {
        return response()->json(['status' => false, 'message' => 'No active question'], 403);
    }
    
    $now = now();
    $questionEndsAt = $session->question_started_at->copy()->addSeconds($session->question_total_duration);
    
    // 2. التحقق: هل انتهى الوقت الكلي للسؤال؟
    if ($now->greaterThanOrEqualTo($questionEndsAt) || $session->question_ended_at) {
        return response()->json([
            'status' => false, 
            'message' => 'Question has expired globally'
        ], 403);
    }
    
    // 3. التحقق: هل وقت المستخدم الشخصي انتهى؟
    $userQuestionKey = "user_{$participant->id}_question_{$data['slide_id']}_started_at";
    $userStartedAt = cache()->get($userQuestionKey);
    
    if (!$userStartedAt) {
        return response()->json([
            'status' => false,
            'message' => 'You must load the question first'
        ], 403);
    }
    
    $userDuration = $session->question_user_duration ?? 30;
    $userDeadline = $userStartedAt->copy()->addSeconds($userDuration);
    
    if ($now->greaterThan($userDeadline)) {
        return response()->json([
            'status' => false,
            'message' => 'Your time to answer has expired'
        ], 403);
    }

    // 4. التحقق من عدم تكرار الإجابة
    $exists = \App\Models\Response::where('session_id', $id)
        ->where('slide_id', $data['slide_id'])
        ->where('participant_id', $participant->id)
        ->exists();

    if ($exists) {
        return response()->json(['status' => false, 'message' => 'Already answered'], 409);
    }

    // 5. حفظ الإجابة
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
 
public function generateReport($sessionId)
{
    $session = Session::whereHas('presentation', fn($q) =>
        $q->where('user_id', auth()->id())
    )->with('presentation')->findOrFail($sessionId);
 
    $participants = $session->participants()->count();
 
    $allResponses = \App\Models\Response::where('session_id', $sessionId)
        ->selectRaw('slide_id, answer_index, COUNT(*) as count, SUM(is_correct) as correct_count, AVG(time_taken) as avg_time')
        ->groupBy('slide_id', 'answer_index')
        ->get();
 
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
 
    \App\Models\Report::updateOrCreate(
        ['session_id' => $sessionId],
        ['total_participants' => $participants, 'summary_data' => $report]
    );
 
    return response()->json([
        'status' => true,
        'data'   => $report,
    ]);
}
public function getUserRemainingTime($sessionId, Request $request)
{
    $request->validate([
        'device_token' => 'required|string',
    ]);

    $session = Session::findOrFail($sessionId);
    $participant = $session->participants()
        ->where('device_token', $request->device_token)
        ->first();

    if (!$participant) {
        return response()->json(['status' => false, 'message' => 'Participant not found'], 404);
    }

    // هل يوجد سؤال نشط؟
    if (!$session->question_started_at || !$session->question_total_duration) {
        return response()->json([
            'status' => true,
            'data' => [
                'is_active' => false,
                'reason' => 'no_active_question'
            ]
        ]);
    }

    $now = now();
    $questionEndsAt = $session->question_started_at->copy()->addSeconds($session->question_total_duration);
    
    // هل انتهى الوقت الكلي للسؤال أو أغلقه المقدم يدوياً؟
    if ($now->greaterThanOrEqualTo($questionEndsAt) || $session->question_ended_at) {
        return response()->json([
            'status' => true,
            'data' => [
                'is_active' => false,
                'reason' => 'question_closed'
            ]
        ]);
    }

    // مفتاح التخزين لكل مشارك لكل سؤال
    $userQuestionKey = "user_{$participant->id}_question_{$session->current_slide_id}_started_at";
    
    $userStartedAt = cache()->get($userQuestionKey);
    
    if (!$userStartedAt) {
        $userStartedAt = $now;
        cache()->put($userQuestionKey, $userStartedAt, 3600);
    }
    
    $userDuration = $session->question_user_duration ?? 30;
    $userDeadline = $userStartedAt->copy()->addSeconds($userDuration);
    $remainingForUser = $userDeadline->diffInSeconds($now, false);
    
    if ($remainingForUser <= 0) {
        return response()->json([
            'status' => true,
            'data' => [
                'is_active' => false,
                'reason' => 'user_time_expired'
            ]
        ]);
    }
    
    return response()->json([
        'status' => true,
        'data' => [
            'is_active' => true,
            'remaining_seconds' => (int) $remainingForUser,
            'user_deadline' => $userDeadline->toISOString(),
            'user_duration' => $userDuration,
        ]
    ]);
}
public function closeQuestion($sessionId)
{
    $session = Session::whereHas('presentation', fn($q) =>
        $q->where('user_id', auth()->id())
    )->where('id', $sessionId)->firstOrFail();

    $session->update([
        'question_ended_at' => now(),
        'timer_expired' => true,
    ]);

    return response()->json([
        'status' => true,
        'message' => 'Question closed manually'
    ]);
}
}
