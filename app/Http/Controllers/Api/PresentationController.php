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
    $presentations = Presentation::where('user_id', auth()->id())
        ->withCount(['slides']) // نكتفي بالشرائح حالياً
        ->orderBy('updated_at', 'desc')
        ->get()
        ->map(function ($pres) {
            return [
                'id' => $pres->id,
                'title' => $pres->title,
                'status' => $pres->status,
                'slides_count' => $pres->slides_count,
                'sessions_count' => 0, // نضع قيمة صفر مؤقتاً
                'last_run' => null, 
                'total_participants' => 0,
                'created_at' => $pres->created_at->format('Y-m-d'),
            ];
        });

    return response()->json([
        'status' => true,
        'data' => $presentations 
    ]);
}  
        public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'template_id' => 'nullable|exists:templates,id'
        ]);

        //  إنشاء العرض التقديمي
        $presentation = Presentation::create([
            'user_id' => auth()->id(),
            'template_id' => $request->template_id,
            'title' => $request->title,
            'status' => 'draft'
        ]);

    //إذا اختار المستخدم قالباً جاهز  
        if ($request->template_id) {
            $this->createTemplateSlides($presentation);
        } else {
            $presentation->slides()->create([
                'category' => 'content',
                'type' => 'blank_slide',
                'order' => 1,
                'content' => ['title' => 'New Slide', 'text' => 'Start here...']
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Presentation created successfully',
            'data' => $presentation->load('slides')
        ], 201);
    }

    private function createTemplateSlides($presentation)
    {
        // شريحة البداية (Start Slide)
        $presentation->slides()->create([
            'category' => 'content',
            'type' => 'start_slide',
            'order' => 1,
            'content' => ['title' => $presentation->title, 'subtitle' => 'Welcome to our presentation']
        ]);

        // شريحة المحتوى (Content Slide)
        $presentation->slides()->create([
            'category' => 'content',
            'type' => 'content_slide',
            'order' => 2,
            'content' => ['title' => 'Main Topic', 'body' => 'Add your points here']
        ]);

        // شريحة النهاية (End Slide)
        $presentation->slides()->create([
            'category' => 'content',
            'type' => 'end_slide',
            'order' => 3,
            'content' => ['title' => 'Thank You', 'subtitle' => 'Any questions?']
        ]);
    }

    public function duplicate($id)
{
    $original = Presentation::with('slides.options')->findOrFail($id);
    
    // نسخ العرض الأساسي
    $new = $original->replicate();
    $new->title = $original->title . ' (Copy)';
    $new->save();

    //(Deep Copy)
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
