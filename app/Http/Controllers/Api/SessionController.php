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

    // المقدم انتقل لشريحة جديدة
    public function changeSlide(Request $request, $sessionId)
    {
        $request->validate([
            'slide_id' => 'required|integer',
        ]);

        $session = Session::whereHas('presentation', fn($q) =>
            $q->where('user_id', auth()->id())
        )->where('id', $sessionId)
         ->where('status', 'active')
         ->firstOrFail();

        $session->update([
            'current_slide_id' => $request->slide_id,
        ]);

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
        return response()->json([
            'status' => true,
            'data'   => ['slide' => null]
        ]);
    }
 
    $slide = $session->presentation
        ->slides()
        ->where('id', $session->current_slide_id)
        ->first();
 
    if (!$slide) {
        return response()->json([
            'status' => true,
            'data'   => ['slide' => null]
        ]);
    }
 
    // ✅ فك الـ JSON
    $content = [];
    if (is_string($slide->content)) {
        $content = json_decode($slide->content, true) ?? [];
    } elseif (is_array($slide->content)) {
        $content = $slide->content;
    }
 
    // ✅ دمج كل الحقول
    $slideData = array_merge($content, [
        'id'           => $slide->id,
        'layout'       => $content['layout']       ?? $slide->layout ?? $slide->type ?? 'BLANK',
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
 
    // ✅ template_id من الـ presentation (الثيم)
    $templateId = $session->presentation->template_id ?? 0;
 
    return response()->json([
        'status' => true,
        'data'   => [
            'slide'       => $slideData,
            'template_id' => $templateId, // ← هذا ما يحتاجه DisplayPage
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
}
