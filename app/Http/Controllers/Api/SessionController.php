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
    // ══════════════════════════════════════════════════════
    // start
    // ══════════════════════════════════════════════════════
    public function start(Request $request, $presentationId)
    {
        $request->validate(['session_settings' => 'nullable|array']);

        $presentation = Presentation::where('user_id', auth()->id())
            ->findOrFail($presentationId);

        Session::where('presentation_id', $presentationId)
            ->whereIn('status', ['waiting', 'active'])
            ->update(['status' => 'finished', 'ended_at' => now()]);

        do {
            $code = (string) random_int(100000, 999999);
        } while (Session::where('access_code', $code)->whereIn('status', ['waiting', 'active'])->exists());

        $session = Session::create([
            'presentation_id'  => $presentationId,
            'access_code'      => $code,
            'status'           => 'waiting',
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

    // ══════════════════════════════════════════════════════
    // launch
    // ══════════════════════════════════════════════════════
    public function launch($sessionId)
    {
        $session = Session::whereHas('presentation', fn($q) =>
            $q->where('user_id', auth()->id())
        )->where('id', $sessionId)
         ->whereIn('status', ['waiting', 'active'])
         ->firstOrFail();

        if ($session->status === 'waiting') {
            $session->update(['status' => 'active', 'started_at' => now()]);
        }

        return response()->json(['status' => true, 'data' => ['status' => $session->status]]);
    }

    // ══════════════════════════════════════════════════════
    // changeSlide — ✅ يحفظ السؤال من الـ DB مباشرة
    // ══════════════════════════════════════════════════════
    public function changeSlide(Request $request, $sessionId)
    {
        $request->validate([
            'slide_id'    => 'required',
            'slide'       => 'nullable|array',
            'template_id' => 'nullable|integer',
        ]);

        $session = Session::whereHas('presentation', fn($q) =>
            $q->where('user_id', auth()->id())
        )->where('id', $sessionId)
         ->whereIn('status', ['waiting', 'active'])
         ->firstOrFail();

        $slideId    = (string) $request->slide_id;
        $updateData = ['current_slide_id' => $slideId];

        if ($request->has('slide') && $request->slide) {
            $updateData['current_slide_data'] = $request->slide;
        }

        if ($request->has('template_id')) {
            $session->presentation->update(['template_id' => $request->template_id]);
        }

        $session->update($updateData);

        // ✅ اجلب بيانات الشريحة من DB مباشرة — مش من الـ request
        $sessionQuestion = null;
        $dbSlide = $session->presentation->slides()->where('id', $slideId)->first();

        if ($dbSlide) {
            $content      = is_string($dbSlide->content)
                ? (json_decode($dbSlide->content, true) ?? [])
                : ($dbSlide->content ?? []);
            $layout       = $content['layout'] ?? $dbSlide->type ?? null;
            $questionData = $content['questionData'] ?? null;
            $isQuestion   = ($layout === 'QUESTION' || !empty($questionData));

            if ($isQuestion) {
                $totalDuration = (int) ($questionData['total_duration'] ?? 900);
                $userDuration  = (int) ($questionData['user_duration']  ?? 30);

                // ✅ ابحث أولاً — لو موجود لا تلمسه
                $sessionQuestion = \App\Models\SessionQuestion::where('session_id', $sessionId)
                    ->where('slide_id', $slideId)
                    ->first();

                if (!$sessionQuestion) {
                    // ✅ سؤال جديد — احفظه
                    $sessionQuestion = \App\Models\SessionQuestion::create([
                        'session_id'     => $sessionId,
                        'slide_id'       => $slideId,
                        'total_duration' => $totalDuration,
                        'user_duration'  => $userDuration,
                        'started_at'     => now(),
                        'ended_at'       => null,
                        'closed_reason'  => null,
                    ]);
                }
            }
        }

        return response()->json([
            'status' => true,
            'data'   => $sessionQuestion ? [
                'slide_id'       => $sessionQuestion->slide_id,
                'started_at'     => $sessionQuestion->started_at,
                'ends_at'        => $sessionQuestion->globalEndsAt(),
                'total_duration' => $sessionQuestion->total_duration,
                'user_duration'  => $sessionQuestion->user_duration,
                'is_expired'     => $sessionQuestion->isExpired(),
            ] : null,
        ]);
    }

    // ══════════════════════════════════════════════════════
    // end
    // ══════════════════════════════════════════════════════
    public function end($sessionId)
    {
        $session = Session::whereHas('presentation', fn($q) =>
            $q->where('user_id', auth()->id())
        )->where('id', $sessionId)->firstOrFail();

        if ($session->status === 'finished') {
            return response()->json(['status' => true, 'data' => ['already_ended' => true]]);
        }

        $session->update(['status' => 'finished', 'ended_at' => now()]);

        return response()->json([
            'status' => true,
            'data'   => ['participants_count' => $session->participants()->count()],
        ]);
    }

    // ══════════════════════════════════════════════════════
    // info
    // ══════════════════════════════════════════════════════
    public function info($code)
    {
        $session = Session::where('access_code', $code)
            ->whereIn('status', ['waiting', 'active'])
            ->with('presentation:id,title')
            ->first();

        if (!$session) {
            return response()->json(['status' => false, 'message' => 'Invalid or expired code.'], 404);
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

    // ══════════════════════════════════════════════════════
    // join
    // ══════════════════════════════════════════════════════
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
            return response()->json(['status' => false, 'message' => 'Invalid or expired code.'], 404);
        }

        $settings    = $session->session_settings ?? [];
        $requireName = $settings['require_name'] ?? true;

        if ($requireName && empty($request->nickname)) {
            return response()->json([
                'status'       => false,
                'message'      => 'Name is required for this session.',
                'require_name' => true,
            ], 422);
        }

        $deviceToken = $request->device_token
            ?: ($request->header('X-Device-Token') ?: Str::uuid()->toString());

        $participant = $session->participants()->where('device_token', $deviceToken)->first();

        if (!$participant) {
            $participant = $session->participants()->create([
                'nickname'     => $request->nickname ?: 'Anonymous',
                'device_token' => $deviceToken,
                'ip_address'   => $request->ip(),
            ]);
        }

        $slides = $session->presentation->slides()
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

    // ══════════════════════════════════════════════════════
    // status
    // ══════════════════════════════════════════════════════
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

    // ══════════════════════════════════════════════════════
    // current
    // ══════════════════════════════════════════════════════
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

    // ══════════════════════════════════════════════════════
    // currentSlide
    // ══════════════════════════════════════════════════════
    public function currentSlide($sessionId)
    {
        $session = Session::findOrFail($sessionId);

        if (!$session->current_slide_id) {
            return response()->json(['status' => true, 'data' => ['slide' => null]]);
        }

        $slide = $session->presentation->slides()
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
            'layout'       => $content['layout']       ?? $slide->type ?? 'BLANK',
            'title'        => $content['title']        ?? '',
            'subtitle'     => $content['subtitle']     ?? '',
            'content'      => $content['content']      ?? '',
            'leftContent'  => $content['leftContent']  ?? '',
            'rightContent' => $content['rightContent'] ?? '',
            'images'       => $content['images']       ?? [],
            'shapes'       => $content['shapes']       ?? [],
            'tables'       => $content['tables']       ?? [],
            'elements'     => $content['elements']     ?? [],
            'background'   => $content['background']   ?? null,
            'titleStyle'   => $content['titleStyle']   ?? (object)[],
            'subtitleStyle'=> $content['subtitleStyle']?? (object)[],
            'contentStyle' => $content['contentStyle'] ?? (object)[],
            'questionData' => $content['questionData'] ?? null,
            'questionType' => $content['questionType'] ?? null,
        ]);

        $questionInfo = null;
        $sq = \App\Models\SessionQuestion::where('session_id', $sessionId)
            ->where('slide_id', (string) $session->current_slide_id)
            ->first();

        if ($sq) {
            if ($sq->isExpired()) {
                $questionInfo = [
                    'is_active'         => false,
                    'reason'            => $sq->closed_reason ?? 'timeout',
                    'question_ended_at' => $sq->ended_at ?? $sq->globalEndsAt(),
                ];
            } else {
                $questionInfo = [
                    'is_active'           => true,
                    'total_duration'      => $sq->total_duration,
                    'user_duration'       => $sq->user_duration,
                    'question_started_at' => $sq->started_at->toISOString(),
                    'question_ends_at'    => $sq->globalEndsAt()->toISOString(),
                ];
            }
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'slide'         => $slideData,
                'template_id'   => $session->presentation->template_id ?? 0,
                'question_info' => $questionInfo,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════
    // expireTimer
    // ══════════════════════════════════════════════════════
    public function expireTimer($sessionId)
    {
        $session = Session::findOrFail($sessionId);
        $session->update(['timer_expired' => true]);
        return response()->json(['status' => true]);
    }

    // ══════════════════════════════════════════════════════
    // participants
    // ══════════════════════════════════════════════════════
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
                'session_status'     => $session->status,
                'participants_count' => $participants->count(),
                'participants'       => $participants,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════
    // submitAnswer
    // ══════════════════════════════════════════════════════
    public function submitAnswer(Request $request, $id)
    {
        $data = $request->validate([
            'slide_id'      => 'required|string',
            'answer_index'  => 'nullable|integer',
            'answer_value'  => 'nullable|string',
            'answer_rating' => 'nullable|integer',
            'device_token'  => 'required|string',
            'time_taken'    => 'nullable|integer',
        ]);

        $session     = Session::findOrFail($id);
        $participant = $session->participants()
            ->where('device_token', $data['device_token'])
            ->first();

        if (!$participant) {
            return response()->json(['status' => false, 'message' => 'Participant not found'], 404);
        }

        $sq = \App\Models\SessionQuestion::where('session_id', $id)
            ->where('slide_id', $data['slide_id'])
            ->first();

        if (!$sq || $sq->isExpired()) {
            return response()->json(['status' => false, 'message' => 'Question is closed'], 403);
        }

        $userKey       = "user_{$participant->id}_sq_{$sq->id}_started_at";
        $userStartedAt = cache()->remember($userKey, 3600, fn() => now());

        if (now()->greaterThan($userStartedAt->copy()->addSeconds($sq->user_duration))) {
            return response()->json(['status' => false, 'message' => 'Your time has expired'], 403);
        }

        if (\App\Models\Response::where('session_id', $id)
            ->where('slide_id', $data['slide_id'])
            ->where('participant_id', $participant->id)
            ->exists()) {
            return response()->json(['status' => false, 'message' => 'Already answered'], 409);
        }

        $slide        = $session->presentation->slides()->where('id', $data['slide_id'])->first();
        $content      = $slide
            ? (is_string($slide->content) ? json_decode($slide->content, true) : $slide->content)
            : [];
        $questionData = $content['questionData'] ?? null;
        $questionType = $content['questionType'] ?? $questionData['type'] ?? 'mcq';

        $isCorrect = null;
        $points    = 0;

       $choiceTypes = ['mcq', 'true_false', 'multiple-choice', 'truefalse'];

if (in_array(strtolower($questionType), $choiceTypes)) {
    $correctIndex = $questionData['correct_answer'] ?? $questionData['correctAnswer'] ?? null;
    if (!is_null($correctIndex) && isset($data['answer_index'])) {
        $isCorrect = ((int) $data['answer_index'] === (int) $correctIndex);
        if ($isCorrect) {
            $maxTime = $sq->user_duration;
            $taken   = min($data['time_taken'] ?? $maxTime, $maxTime);
            $points  = (int) round(1000 * (1 - ($taken / $maxTime) * 0.5));
        }
    }
}

        $response = \App\Models\Response::create([
            'session_id'     => $id,
            'slide_id'       => $data['slide_id'],
            'participant_id' => $participant->id,
            'answer_index'   => $data['answer_index']  ?? null,
            'answer_value'   => $data['answer_value']  ?? null,
            'answer_rating'  => $data['answer_rating'] ?? null,
            'time_taken'     => $data['time_taken']    ?? 0,
            'is_correct'     => $isCorrect,
            'points'         => $points,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Answer submitted successfully',
            'data'    => ['response_id' => $response->id],
        ]);
    }

    // ══════════════════════════════════════════════════════
    // revealResults / hideResults
    // ══════════════════════════════════════════════════════
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

    // ══════════════════════════════════════════════════════
    // slideResults
    // ══════════════════════════════════════════════════════
    public function slideResults($sessionId, $slideId)
    {
        $session = Session::findOrFail($sessionId);

        $results = \App\Models\Response::where('session_id', $sessionId)
            ->where('slide_id', $slideId)
            ->selectRaw('answer_index, COUNT(*) as count')
            ->groupBy('answer_index')
            ->orderBy('answer_index')
            ->get();

        $total   = \App\Models\Response::where('session_id', $sessionId)->where('slide_id', $slideId)->count();
        $correct = \App\Models\Response::where('session_id', $sessionId)->where('slide_id', $slideId)->where('is_correct', true)->count();

        return response()->json([
            'status' => true,
            'data'   => [
                'results'         => $results,
                'total_responses' => $total,
                'correct_count'   => $correct,
                'incorrect_count' => $total - $correct,
                'correct_percent' => $total > 0 ? round(($correct / $total) * 100) : 0,
                'show_results'    => $session->show_results ?? false,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════
    // generateReport
    // ══════════════════════════════════════════════════════
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
                $slideStats[$sid] = ['slide_id' => $sid, 'total' => 0, 'correct' => 0, 'options' => [], 'avg_time' => 0];
            }
            $slideStats[$sid]['total']    += $r->count;
            $slideStats[$sid]['correct']  += $r->correct_count;
            $slideStats[$sid]['avg_time']  = round($r->avg_time, 1);
            $slideStats[$sid]['options'][] = ['index' => $r->answer_index, 'count' => $r->count];
        }

        $leaderboard = \App\Models\Response::where('session_id', $sessionId)
            ->join('participants', 'responses.participant_id', '=', 'participants.id')
            ->selectRaw('participants.nickname, SUM(responses.points) as total_points, SUM(responses.is_correct) as correct_answers')
            ->groupBy('participants.id', 'participants.nickname')
            ->orderByDesc('total_points')
            ->limit(20)
            ->get();

        $report = [
            'session_id'         => $sessionId,
            'presentation_title' => $session->presentation->title,
            'total_participants' => $participants,
            'total_questions'    => count($slideStats),
            'slide_stats'        => array_values($slideStats),
            'leaderboard'        => $leaderboard,
            'generated_at'       => now()->toDateTimeString(),
        ];

        \App\Models\Report::updateOrCreate(
            ['session_id' => $sessionId],
            ['total_participants' => $participants, 'summary_data' => $report]
        );

        return response()->json(['status' => true, 'data' => $report]);
    }

    // ══════════════════════════════════════════════════════
    // getUserRemainingTime
    // ══════════════════════════════════════════════════════
    public function getUserRemainingTime($sessionId, Request $request)
    {
        $request->validate(['device_token' => 'required|string']);

        $session     = Session::findOrFail($sessionId);
        $participant = $session->participants()
            ->where('device_token', $request->device_token)
            ->first();

        if (!$participant) {
            return response()->json(['status' => false, 'message' => 'Participant not found'], 404);
        }

        $sq = \App\Models\SessionQuestion::where('session_id', $sessionId)
            ->where('slide_id', (string) $session->current_slide_id)
            ->first();

        if (!$sq) {
            return response()->json(['status' => true, 'data' => ['is_active' => false, 'reason' => 'no_active_question']]);
        }

        if ($sq->isExpired()) {
            return response()->json(['status' => true, 'data' => ['is_active' => false, 'reason' => 'question_closed']]);
        }

        $userKey       = "user_{$participant->id}_sq_{$sq->id}_started_at";
        $userStartedAt = cache()->remember($userKey, 3600, fn() => now());
        $userDeadline  = $userStartedAt->copy()->addSeconds($sq->user_duration);
        $remaining     = (int) now()->diffInSeconds($userDeadline, false);

        if ($remaining <= 0) {
            return response()->json(['status' => true, 'data' => ['is_active' => false, 'reason' => 'user_time_expired']]);
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'is_active'         => true,
                'remaining_seconds' => $remaining,
                'user_deadline'     => $userDeadline->toISOString(),
                'user_duration'     => $sq->user_duration,
                'global_ends_at'    => $sq->globalEndsAt()->toISOString(),
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════
    // closeQuestion ✅ مصلّح
    // ══════════════════════════════════════════════════════
    public function closeQuestion($sessionId)
    {
        $session = Session::whereHas('presentation', fn($q) =>
            $q->where('user_id', auth()->id())
        )->where('id', $sessionId)->firstOrFail();

        $sq = \App\Models\SessionQuestion::where('session_id', $sessionId)
            ->where('slide_id', (string) $session->current_slide_id)
            ->first();

        if (!$sq) {
            return response()->json(['status' => false, 'message' => 'No active question found'], 404);
        }

        // ✅ دائماً احفظ — سواء انتهى بالوقت أو لسه شغال
        $sq->update([
            'ended_at'      => $sq->ended_at ?? now(),
            'closed_reason' => $sq->closed_reason ?? 'manual',
        ]);

        $sq->refresh();

        return response()->json([
            'status'  => true,
            'message' => 'Question closed',
            'data'    => [
                'slide_id'   => $sq->slide_id,
                'started_at' => $sq->started_at,
                'ended_at'   => $sq->ended_at,
                'reason'     => $sq->closed_reason,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════
    // questionReport — helpers
    // ══════════════════════════════════════════════════════
    public function questionReport($sessionId, $slideId)
    {
        $session = Session::with('presentation')->findOrFail($sessionId);

        $sq = \App\Models\SessionQuestion::where('session_id', $sessionId)
            ->where('slide_id', (string) $slideId)
            ->first();

        if (!$sq) {
            return response()->json(['status' => false, 'message' => 'Question not found'], 404);
        }

        $slide        = $session->presentation->slides()->where('id', $slideId)->first();
        $content      = $slide
            ? (is_string($slide->content) ? json_decode($slide->content, true) : $slide->content)
            : [];
        $questionData = $content['questionData'] ?? null;
        $questionType = strtolower($content['questionType'] ?? $questionData['type'] ?? 'mcq');
        $questionText = $questionData['question'] ?? $content['title'] ?? '';
        $options      = $questionData['options']   ?? $questionData['answers'] ?? [];
        $correctIndex = $questionData['correct_answer'] ?? $questionData['correctAnswer'] ?? null;

        $totalParticipants = $session->participants()->count();
        $responses         = \App\Models\Response::where('session_id', $sessionId)
            ->where('slide_id', (string) $slideId)
            ->with('participant:id,nickname')
            ->get();

        $totalResponses = $responses->count();
        $noAnswer       = max(0, $totalParticipants - $totalResponses);
        $avgTime        = $totalResponses > 0 ? round($responses->avg('time_taken'), 1) : null;

       $isChoiceType = in_array($questionType, ['mcq', 'true_false', 'multiple-choice', 'truefalse']);

        $isChoiceType = in_array($questionType, ['mcq', 'true_false', 'multiple-choice', 'truefalse']);

        if ($isChoiceType) {
            $typeStats = $this->buildChoiceStats($responses, $options, $correctIndex, $totalResponses);
        } elseif (in_array($questionType, ['text', 'open'])) {
            $typeStats = $this->buildTextStats($responses);
        } elseif ($questionType === 'rating') {
            $typeStats = $this->buildRatingStats($responses);
        } else {
            $typeStats = [];
        }

        $correctCount = $isChoiceType ? $responses->where('is_correct', true)->count()  : null;
        $wrongCount   = $isChoiceType ? $responses->where('is_correct', false)->count() : null;

        $leaderboard = null;
        if ($isChoiceType) {
            $leaderboard = \App\Models\Response::where('responses.session_id', $sessionId)
                ->where('responses.slide_id', (string) $slideId)
                ->join('participants', 'responses.participant_id', '=', 'participants.id')
                ->select('participants.nickname', 'responses.points', 'responses.time_taken', 'responses.is_correct')
                ->orderByDesc('responses.points')
                ->orderBy('responses.time_taken')
                ->limit(10)
                ->get();
        }

        return response()->json([
            'status' => true,
            'data'   => [
                'question' => [
                    'slide_id'      => $slideId,
                    'type'          => $questionType,
                    'text'          => $questionText,
                    'correct_index' => $correctIndex,
                    'options'       => $options,
                ],
                'timing' => [
                    'started_at'     => $sq->started_at,
                    'ended_at'       => $sq->ended_at ?? $sq->globalEndsAt(),
                    'closed_reason'  => $sq->closed_reason ?? 'timeout',
                    'total_duration' => $sq->total_duration,
                    'user_duration'  => $sq->user_duration,
                ],
                'stats' => [
                    'total_participants' => $totalParticipants,
                    'total_responses'    => $totalResponses,
                    'no_answer_count'    => $noAnswer,
                    'correct_count'      => $correctCount,
                    'wrong_count'        => $wrongCount,
                    'correct_percent'    => ($totalResponses > 0 && !is_null($correctCount))
                        ? round(($correctCount / $totalResponses) * 100) : null,
                    'participation_rate' => $totalParticipants > 0
                        ? round(($totalResponses / $totalParticipants) * 100) : 0,
                    'avg_time_seconds'   => $avgTime,
                    'type_stats'         => $typeStats,
                ],
                'leaderboard'  => $leaderboard,
                'show_results' => $session->show_results ?? false,
            ],
        ]);
    }

    private function buildChoiceStats($responses, $options, $correctIndex, $totalResponses): array
    {
        $stats = [];
        foreach ($options as $index => $option) {
            $count   = $responses->where('answer_index', $index)->count();
            $stats[] = [
                'index'      => $index,
                'text'       => is_array($option) ? ($option['text'] ?? '') : $option,
                'count'      => $count,
                'percent'    => $totalResponses > 0 ? round(($count / $totalResponses) * 100) : 0,
                'is_correct' => ($index === $correctIndex),
            ];
        }
        return $stats;
    }

    private function buildTextStats($responses): array
    {
        return $responses->whereNotNull('answer_value')
            ->map(fn($r) => [
                'nickname'   => $r->participant->nickname ?? 'Anonymous',
                'answer'     => $r->answer_value,
                'time_taken' => $r->time_taken,
            ])->values()->toArray();
    }

    private function buildRatingStats($responses): array
    {
        $ratings = $responses->whereNotNull('answer_rating');
        if ($ratings->isEmpty()) return [];

        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $count          = $ratings->where('answer_rating', $i)->count();
            $distribution[] = [
                'rating'  => $i,
                'count'   => $count,
                'percent' => $ratings->count() > 0 ? round(($count / $ratings->count()) * 100) : 0,
            ];
        }

        return [
            'average'      => round($ratings->avg('answer_rating'), 2),
            'distribution' => $distribution,
        ];
    }
}