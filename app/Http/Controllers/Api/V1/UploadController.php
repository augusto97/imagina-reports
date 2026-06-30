<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Generic authenticated image upload for report content (cover/back-cover logos, the image
 * block, …). Stores on the public disk and returns a URL usable in the report. Images shown
 * in a client report are public by design, so they live on the public disk.
 */
final class UploadController extends Controller
{
    public function image(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/png,image/jpeg,image/svg+xml,image/webp', 'max:2048'],
        ]);

        $path = $request->file('image')?->store('uploads', 'public');

        return response()->json([
            'url' => is_string($path) ? Storage::disk('public')->url($path) : null,
        ]);
    }
}
