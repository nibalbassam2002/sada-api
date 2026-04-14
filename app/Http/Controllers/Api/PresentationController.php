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
            ->with(['slides' => function($q) {
                $q->orderBy('order', 'asc')->limit(1); // أول شريحة فقط
            }])
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($pres) {
                // محتوى أول شريحة
                $firstSlide = $pres->slides->first();
                $firstContent = $firstSlide ? (is_array($firstSlide->content) ? $firstSlide->content : []) : [];
                if ($firstSlide) $firstContent['id'] = $firstSlide->id;

                return [
                    'id'                 => $pres->id,
                    'title'              => $pres->title,
                    'status'             => $pres->status,
                    'template_id'        => $pres->template_id,
                    'slides_count'       => $pres->slides_count,
                    'sessions_count'     => 0,
                    'last_run'           => null,
                    'total_participants' => 0,
                    'created_at'         => $pres->created_at->format('Y-m-d'),
                    'first_slide'        => $firstContent, // ✅ محتوى أول شريحة
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
            'template_id' => $request->template_id ?: null, 
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
        // 🔥 التحويل الذكي: 
        // - لو الرقم 0 أو '0' → يصير null (بدون قالب)
        // - لو الرقم موجود وفعلي → يخليه كما هو (مع قالب)
        $templateId = $request->template_id;
        
        if ($templateId === 0 || $templateId === '0') {
            $templateId = null;  // بدون قالب ✅
        }
        // أي رقم ثاني (1,2,3,5,...) يضل كما هو ✅
        
        $updateData['template_id'] = $templateId;
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
    public function importPptx(Request $request)
{
    $request->validate([
        'file'  => 'required|file|mimes:pptx,zip|max:20480',
        'title' => 'nullable|string|max:255',
    ]);

    $file     = $request->file('file');
    $title    = $request->input('title') ?: $file->getClientOriginalName();
    $path     = $file->store('temp_pptx', 'local');
    $fullPath = storage_path('app/' . $path);

    try {
        $reader       = \PhpOffice\PhpPresentation\IOFactory::createReader('PowerPoint2007');
        $presentation = $reader->load($fullPath);

        $newPresentation = \App\Models\Presentation::create([
            'user_id'     => auth()->id(),
            'title'       => $title,
            'template_id' => null,
            'status'      => 'draft',
        ]);

        foreach ($presentation->getAllSlides() as $index => $slide) {
            $content = $this->extractSlideContent($slide);
            $newPresentation->slides()->create([
                'order'    => $index + 1,
                'category' => 'content',
                'type'     => 'imported',
                'content'  => $content,
                'settings' => [],
            ]);
        }

        \Storage::disk('local')->delete($path);

        return response()->json([
            'status'  => true,
            'message' => 'PowerPoint imported successfully',
            'data'    => $newPresentation->load('slides'),
        ], 201);

    } catch (\Exception $e) {
    \Storage::disk('local')->delete($path);
    return response()->json([
        'status'  => false,
        'message' => $e->getMessage(),
        'line'    => $e->getLine(),
        'file'    => basename($e->getFile()),
    ], 422);
}
}

private function extractSlideContent($slide): array
{
    $title    = '';
    $subtitle = '';
    $content  = '';
    $images   = [];
    $shapes   = [];

    foreach ($slide->getShapeCollection() as $shape) {

        // ── نصوص ──────────────────────────────────────
        if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
            $text      = '';
            $fontSize  = 24;
            $color     = '#1e293b';
            $fontWeight = 'normal';

            foreach ($shape->getParagraphs() as $para) {
                foreach ($para->getRichTextElements() as $el) {
                    $text .= $el->getText();

                    // استخراج الخط واللون
                    $font = $el->getFont();
                    if ($font) {
                        $fontSize   = $font->getSize()   ?: $fontSize;
                        $fontWeight = $font->isBold()    ? 'bold' : 'normal';
                        $clr        = $font->getColor();
                        if ($clr && $clr->getRGB() !== '000000') {
                            $color = '#' . $clr->getRGB();
                        }
                    }
                }
                $text .= "\n";
            }
            $text = trim($text);
            if (!$text) continue;

            // تحديد نوع النص حسب الحجم
            if (empty($title) || $fontSize >= 28) {
                $title = $text;
            } elseif (empty($subtitle) || $fontSize >= 18) {
                $subtitle = $text;
            } else {
                $content .= $text . "\n";
            }
        }

        // ── صور ───────────────────────────────────────
        if ($shape instanceof \PhpOffice\PhpPresentation\Shape\Drawing\Gd) {
            ob_start();
            imagepng($shape->getImage());
            $imgData = ob_get_clean();
            $images[] = [
                'id'     => uniqid('img_'),
                'src'    => 'data:image/png;base64,' . base64_encode($imgData),
                'x'      => $shape->getOffsetX(),
                'y'      => $shape->getOffsetY(),
                'width'  => $shape->getWidth(),
                'height' => $shape->getHeight(),
            ];
        }

        // ── أشكال (مستطيلات، دوائر، إلخ) ────────────
        if ($shape instanceof \PhpOffice\PhpPresentation\Shape\AutoShape) {
            $fill  = $shape->getFill();
            $color = '#e2e8f0';
            if ($fill && $fill->getFillType() !== \PhpOffice\PhpPresentation\Style\Fill::FILL_NONE) {
                $clr = $fill->getStartColor();
                if ($clr) $color = '#' . $clr->getRGB();
            }
            $shapes[] = [
                'id'     => uniqid('shape_'),
                'type'   => 'rect',
                'x'      => $shape->getOffsetX(),
                'y'      => $shape->getOffsetY(),
                'width'  => $shape->getWidth(),
                'height' => $shape->getHeight(),
                'fill'   => $color,
            ];
        }
    }

    return [
        'layout'       => 'Title and Content',
        'title'        => $title,
        'subtitle'     => $subtitle,
        'content'      => trim($content),
        'images'       => $images,
        'shapes'       => $shapes,
        'tables'       => [],
        'titleStyle'   => ['fontFamily' => 'Calibri', 'fontSize' => 48],
        'subtitleStyle' => ['fontFamily' => 'Calibri', 'fontSize' => 24],
        'contentStyle' => ['fontFamily' => 'Calibri', 'fontSize' => 18],
    ];
}
}
