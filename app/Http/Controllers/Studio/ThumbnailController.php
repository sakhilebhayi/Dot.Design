<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignProject;
use App\Services\Assets\AssetRejected;
use App\Services\Design\ThumbnailStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ThumbnailController extends StudioController
{
    /**
     * The browser sends a small PNG or WebP (up to 300 KB). Either the raw image as the request body (PUT), or a
     * multipart `thumbnail` file through method spoofing (POST with _method=PUT), since PHP does not parse multipart PUT.
     */
    public function update(Request $request, DesignProject $project, ThumbnailStore $store): JsonResponse
    {
        $this->authorize('update', $project);

        $file = $request->file('thumbnail');
        $bytes = $file !== null ? (string) file_get_contents($file->getRealPath()) : $request->getContent();

        try {
            $store->store($project, $bytes);
        } catch (AssetRejected $e) {
            return $this->invalid($e->getMessage(), ['thumbnail' => [$e->getMessage()]], $e->reason === 'size' ? 413 : 422);
        }

        return response()->json(['status' => 'saved', 'thumbnail_url' => $store->url($project)]);
    }

    /** Served only to people who can see the project, with headers that stop the bytes being treated as anything else. */
    public function show(Request $request, DesignProject $project): Response
    {
        $this->authorize('view', $project);

        $path = $project->thumbnail_path;
        $disk = Storage::disk((string) config('design.assets.disk'));

        abort_if($path === null || ! $disk->exists($path), 404);

        $response = $disk->response($path, null, [
            'Content-Type' => str_ends_with($path, '.webp') ? 'image/webp' : 'image/png',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Vary' => 'Cookie',
        ]);

        $response->setPrivate();
        $response->setMaxAge(3600);
        $response->setEtag(sha1($path.'|'.$project->thumbnail_updated_at?->timestamp));
        $response->isNotModified($request);

        return $response;
    }
}
