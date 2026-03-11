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
            ->withCount(['slides'])
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($pres) {
                return [
                    'id'                 => $pres->id,
                    'title'              => $pres->title,
                    'status'             => $pres->status,
                    'template_id'        => $pres->template_id, // ✅
                    'slides_count'       => $pres->slides_count,
                    'sessions_count'     => 0,
                    'last_run'           => null,
                    'total_participants' => 0,
                    'created_at'         => $pres->created_at->format('Y-m-d'),
                ];
            });

        return response()->json([
            'status' => true,
            'data'   => $presentations
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'       => 'required|string|max:255',
            'template_id' => 'nullable|integer'
        ]);

        $presentation = Presentation::create([
            'user_id'     => auth()->id(),
            'template_id' => $request->template_id,
            'title'       => $request->title,
            'status'      => 'draft'
        ]);

        if ($request->template_id) {
            $this->createTemplateSlides($presentation);
        } else {
            $presentation->slides()->create([
                'category' => 'content',
                'type'     => 'blank_slide',
                'order'    => 1,
                'content'  => ['title' => 'New Slide', 'text' => 'Start here...']
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Presentation created successfully',
            'data'    => $presentation->load('slides')
        ], 201);
    }

    private function createTemplateSlides($presentation)
    {
        $presentation->slides()->create([
            'category' => 'content',
            'type'     => 'start_slide',
            'order'    => 1,
            'content'  => ['title' => $presentation->title, 'subtitle' => 'Welcome to our presentation']
        ]);

        $presentation->slides()->create([
            'category' => 'content',
            'type'     => 'content_slide',
            'order'    => 2,
            'content'  => ['title' => 'Main Topic', 'body' => 'Add your points here']
        ]);

        $presentation->slides()->create([
            'category' => 'content',
            'type'     => 'end_slide',
            'order'    => 3,
            'content'  => ['title' => 'Thank You', 'subtitle' => 'Any questions?']
        ]);
    }

    public function show($id)
    {
        $presentation = Presentation::where('user_id', auth()->id())
            ->with(['slides' => function ($q) {
                $q->orderBy('order', 'asc');
            }])
            ->findOrFail($id);

        return response()->json([
            'status' => true,
            'data'   => [
                'id'          => $presentation->id,
                'title'       => $presentation->title,
                'template_id' => $presentation->template_id, // ✅ مهم جداً
                'slides'      => $presentation->slides->map(function ($slide) {
                    // ✅ ندمج content مع id حتى لا يضيع
                    $content       = is_array($slide->content) ? $slide->content : [];
                    $content['id'] = $slide->id;
                    return $content;
                }),
            ]
        ]);
    }

    public function syncSlides(Request $request, $id)
    {
        $presentation = Presentation::where('user_id', auth()->id())->findOrFail($id);

        // ✅ تحديث العنوان والثيم معاً
        $updateData = [];
        if ($request->has('title')) {
            $updateData['title'] = $request->title;
        }
        if ($request->has('template_id')) {
            $updateData['template_id'] = $request->template_id; // ✅ حفظ الثيم
        }
        if (!empty($updateData)) {
            $presentation->update($updateData);
        }

        $incomingSlides = $request->input('slides', []);
        $keptSlideIds   = [];

        foreach ($incomingSlides as $index => $slideData) {
            $slide = $presentation->slides()->updateOrCreate(
                ['id' => $slideData['id'] ?? null],
                [
                    'order'    => $index + 1,
                    'layout'   => $slideData['layout']       ?? 'Blank',
                    'category' => $slideData['category']     ?? 'content',
                    'type'     => $slideData['questionType'] ?? 'content',
                    'content'  => $slideData,
                    'settings' => $slideData['settings']     ?? [],
                ]
            );
            $keptSlideIds[] = $slide->id;
        }

        // حذف الشرائح المحذوفة
        $presentation->slides()->whereNotIn('id', $keptSlideIds)->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Presentation synced successfully',
            'data'    => $presentation->load('slides')
        ]);
    }

    public function update(Request $request, $id)
    {
        $presentation = Presentation::where('user_id', auth()->id())->findOrFail($id);

        $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $presentation->update(['title' => $request->title]);

        return response()->json([
            'status'  => true,
            'message' => 'Presentation title updated successfully'
        ]);
    }

    public function duplicate($id)
    {
        $original = Presentation::with('slides.options')->findOrFail($id);

        $new        = $original->replicate();
        $new->title = $original->title . ' (Copy)';
        $new->save();

        foreach ($original->slides as $slide) {
            $newSlide                  = $slide->replicate();
            $newSlide->presentation_id = $new->id;
            $newSlide->save();

            foreach ($slide->options as $option) {
                $newOption          = $option->replicate();
                $newOption->slide_id = $newSlide->id;
                $newOption->save();
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'تم نسخ العرض بنجاح',
            'data'    => $new
        ]);
    }

    public function toggleArchive($id)
    {
        $presentation         = Presentation::findOrFail($id);
        $presentation->status = ($presentation->status === 'archived') ? 'draft' : 'archived';
        $presentation->save();

        return response()->json(['status' => true, 'message' => 'تمت العملية بنجاح']);
    }

    public function getReport($id)
    {
        $presentation = Presentation::with(['sessions' => function ($q) {
            $q->orderBy('created_at', 'desc');
        }])->findOrFail($id);

        return response()->json([
            'status'               => true,
            'presentation_title'   => $presentation->title,
            'sessions'             => $presentation->sessions,
        ]);
    }

    public function destroy($id)
    {
        $presentation = Presentation::where('user_id', auth()->id())->findOrFail($id);
        $presentation->delete();

        return response()->json(['status' => true, 'message' => 'Deleted successfully']);
    }
}
