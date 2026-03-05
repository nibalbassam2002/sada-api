<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Slide;
use Illuminate\Http\Request;

class SlideController extends Controller
{
    public function update(Request $request, $id)
    {
        $slide = Slide::with('presentation')->findOrFail($id);

        if ($slide->presentation->user_id !== auth()->id()) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized. You do not have permission to edit this slide.'
            ], 403);
        }

        // Validate the incoming request
        $request->validate([
            'content' => 'required|array', 
            'settings' => 'nullable|array', 
        ]);

        // Update the slide details
        $slide->update([
            'content' => $request->content,
            'settings' => $request->settings ?? $slide->settings,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Slide updated successfully',
            'data' => $slide
        ]);
    }
}