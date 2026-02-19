<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PresentationController extends Controller
{
        public function index()
    {
        // جلب العروض مع الإحصائيات المطلوبة
        $presentations = Presentation::where('user_id', auth()->id())
            ->withCount(['slides', 'sessions']) // جلب عدد الشرائح وعدد الجلسات
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($pres) {
                return [
                    'id' => $pres->id,
                    'title' => $pres->title,
                    'status' => $pres->status, // draft, ready, archived
                    'slides_count' => $pres->slides_count,
                    'sessions_count' => $pres->sessions_count,
                    'last_run' => $pres->sessions()->latest()->first()?->started_at, // آخر تاريخ تشغيل
                    'total_participants' => $pres->sessions()->sum('participants_count'), // مجموع المشاركين في كل الجلسات
                    'created_at' => $pres->created_at->format('Y-m-d'),
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $presentations // إذا كانت المصفوفة فارغة، واجهة React ستعرف تلقائياً وتظهر "لا يوجد مشاريع"
        ]);
    }

    public function duplicate($id)
{
    $original = Presentation::with('slides.options')->findOrFail($id);
    
    // نسخ العرض الأساسي
    $new = $original->replicate();
    $new->title = $original->title . ' (Copy)';
    $new->save();

    // نسخ الشرائح والخيارات المرتبطة بها (Deep Copy)
    foreach ($original->slides as $slide) {
        $newSlide = $slide->replicate();
        $newSlide->presentation_id = $new->id;
        $newSlide->save();

        foreach ($slide->options as $option) {
            $newOption = $option->replicate();
            $newOption->slide_id = $newSlide->id;
            $newOption->save();
        }
    }

    return response()->json(['status' => true, 'message' => 'تم نسخ العرض بنجاح', 'data' => $new]);
}
public function toggleArchive($id)
{
    $presentation = Presentation::findOrFail($id);
    $presentation->status = ($presentation->status === 'archived') ? 'draft' : 'archived';
    $presentation->save();

    return response()->json(['status' => true, 'message' => 'تمت العملية بنجاح']);
}
public function getReport($id)
{
    $presentation = Presentation::with(['sessions' => function($q) {
        $q->orderBy('created_at', 'desc');
    }])->findOrFail($id);

    return response()->json([
        'status' => true,
        'presentation_title' => $presentation->title,
        'sessions' => $presentation->sessions, // قائمة بكل الجلسات وتفاصيلها للتقرير
    ]);
}

}
