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
        'file'  => 'required|file|max:20480',
        'title' => 'nullable|string|max:255',
    ]);

    $file     = $request->file('file');
    $title    = $request->input('title') ?: str_replace('.pptx', '', $file->getClientOriginalName());
    $tmpDir   = sys_get_temp_dir() . '/' . uniqid('pptx_');
    mkdir($tmpDir);
    $pptxPath = $tmpDir . '/presentation.pptx';
    copy($file->getRealPath(), $pptxPath);

    try {
        // فتح الـ PPTX كـ ZIP
        $zip = new \ZipArchive();
        if ($zip->open($pptxPath) !== true) {
            throw new \Exception('Cannot open PPTX file');
        }

        // استخراج كل الملفات
        $zip->extractTo($tmpDir . '/extracted');
        $zip->close();

        $extractDir = $tmpDir . '/extracted';

        // قراءة عدد الشرائح من presentation.xml
        $presXml = simplexml_load_file($extractDir . '/ppt/presentation.xml');
        $presXml->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $slideNodes = $presXml->xpath('//p:sldIdLst/p:sldId');
        $slideCount = count($slideNodes);

        $newPresentation = \App\Models\Presentation::create([
            'user_id'     => auth()->id(),
            'title'       => $title,
            'template_id' => null,
            'status'      => 'draft',
        ]);

        for ($i = 1; $i <= $slideCount; $i++) {
            $slideFile = $extractDir . "/ppt/slides/slide{$i}.xml";
            if (!file_exists($slideFile)) continue;

            $content = $this->parseSlideXml($slideFile, $extractDir, $i);

            $newPresentation->slides()->create([
                'order'    => $i,
                'category' => 'content',
                'type'     => 'imported',
                'content'  => $content,
                'settings' => [],
            ]);
        }

        // تنظيف
        $this->deleteDir($tmpDir);

        return response()->json([
            'status'  => true,
            'message' => 'PowerPoint imported successfully',
            'data'    => $newPresentation->load('slides'),
        ], 201);

    } catch (\Exception $e) {
        $this->deleteDir($tmpDir);
        return response()->json([
            'status'  => false,
            'message' => $e->getMessage(),
            'line'    => $e->getLine(),
            'file'    => basename($e->getFile()),
        ], 422);
    }
}

private function parseSlideXml($slideFile, $extractDir, $slideIndex): array
{
    $xml = simplexml_load_file($slideFile);

    // تسجيل namespaces
    $namespaces = [
        'a'   => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'p'   => 'http://schemas.openxmlformats.org/presentationml/2006/main',
        'r'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'xdr' => 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing',
    ];

    foreach ($namespaces as $prefix => $uri) {
        $xml->registerXPathNamespace($prefix, $uri);
    }

    $title    = '';
    $subtitle = '';
    $content  = '';
    $images   = [];
    $bgColor  = '#ffffff';

    // ── استخراج لون الخلفية ─────────────────────
    $bgNodes = $xml->xpath('//p:bg//a:solidFill/a:srgbClr');
    if (!empty($bgNodes)) {
        $bgColor = '#' . (string)$bgNodes[0]['val'];
    }

    // ── استخراج الخلفية من slideLayout أو slideMaster ──
    $slideLayoutBg = $this->getSlideBackground($extractDir, $slideIndex);

    // ── استخراج النصوص مع مواضعها ────────────────
    $spNodes = $xml->xpath('//p:sp');
    foreach ($spNodes as $sp) {
        $sp->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $sp->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

        // نوع الـ placeholder
        $phType = '';
        $phNodes = $sp->xpath('.//p:ph');
        if (!empty($phNodes)) {
            $phType = (string)($phNodes[0]['type'] ?? 'body');
        }

        // النص الكامل
        $text = '';
        $fontSize = 18;
        $bold = false;
        $color = '#1e293b';

        $rNodes = $sp->xpath('.//a:r');
        foreach ($rNodes as $r) {
            $r->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $tNodes = $r->xpath('.//a:t');
            foreach ($tNodes as $t) {
                $text .= (string)$t;
            }
            // الخط واللون
            $szNodes = $r->xpath('.//a:rPr/@sz');
            if (!empty($szNodes)) {
                $fontSize = (int)((string)$szNodes[0]) / 100;
            }
            $boldNodes = $r->xpath('.//a:rPr/@b');
            if (!empty($boldNodes)) {
                $bold = (string)$boldNodes[0] === '1';
            }
            $colorNodes = $r->xpath('.//a:rPr/a:solidFill/a:srgbClr/@val');
            if (!empty($colorNodes)) {
                $color = '#' . (string)$colorNodes[0];
            }
        }

        $text = trim($text);
        if (!$text) continue;

        if ($phType === 'title' || $phType === 'ctrTitle') {
            $title = $text;
        } elseif ($phType === 'subTitle') {
            $subtitle = $text;
        } else {
            $content .= $text . "\n";
        }
    }

    // ── استخراج الصور ────────────────────────────
    $relsFile = $extractDir . "/ppt/slides/_rels/slide{$slideIndex}.xml.rels";
    if (file_exists($relsFile)) {
        $rels = simplexml_load_file($relsFile);
        foreach ($rels->Relationship as $rel) {
            $type   = (string)$rel['Type'];
            $target = (string)$rel['Target'];

            if (str_contains($type, 'image')) {
                $imagePath = realpath($extractDir . '/ppt/slides/' . $target);
                if ($imagePath && file_exists($imagePath)) {
                    $ext      = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                    $mime     = match($ext) {
                        'png'  => 'image/png',
                        'jpg', 'jpeg' => 'image/jpeg',
                        'gif'  => 'image/gif',
                        'webp' => 'image/webp',
                        default => 'image/png',
                    };
                    $images[] = [
                        'id'  => uniqid('img_'),
                        'src' => "data:{$mime};base64," . base64_encode(file_get_contents($imagePath)),
                    ];
                }
            }
        }
    }

    return [
        'layout'          => 'Title and Content',
        'title'           => $title,
        'subtitle'        => $subtitle,
        'content'         => trim($content),
        'images'          => $images,
        'shapes'          => [],
        'tables'          => [],
        'backgroundColor' => $slideLayoutBg ?: $bgColor,
        'titleStyle'      => ['fontFamily' => 'Calibri', 'fontSize' => 40, 'color' => '#1e293b'],
        'subtitleStyle'   => ['fontFamily' => 'Calibri', 'fontSize' => 24, 'color' => '#475569'],
        'contentStyle'    => ['fontFamily' => 'Calibri', 'fontSize' => 18, 'color' => '#334155'],
    ];
}

private function getSlideBackground($extractDir, $slideIndex): ?string
{
    // محاولة قراءة خلفية الـ slideLayout
    $relsFile = $extractDir . "/ppt/slides/_rels/slide{$slideIndex}.xml.rels";
    if (!file_exists($relsFile)) return null;

    $rels = simplexml_load_file($relsFile);
    foreach ($rels->Relationship as $rel) {
        if (str_contains((string)$rel['Type'], 'slideLayout')) {
            $layoutPath = realpath($extractDir . '/ppt/slides/' . (string)$rel['Target']);
            if ($layoutPath && file_exists($layoutPath)) {
                $layoutXml = simplexml_load_file($layoutPath);
                $layoutXml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $bgNodes = $layoutXml->xpath('//a:solidFill/a:srgbClr');
                if (!empty($bgNodes)) {
                    return '#' . (string)$bgNodes[0]['val'];
                }
            }
        }
    }
    return null;
}

private function deleteDir($dir): void
{
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? $this->deleteDir($path) : unlink($path);
    }
    rmdir($dir);
}
}
