<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Services\Design\ProjectAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ProjectAssetController extends StudioController
{
    /**
     * A picture that is part of a project, served to anyone who can view that project (a teammate opening a page that
     * holds a colleague's upload). The asset must really belong to the project: placed on a page, filed under it, or a
     * brand asset of its workspace. Same headers as the personal asset route.
     */
    public function file(Request $request, DesignProject $project, int $asset, ProjectAssets $assets): Response
    {
        $this->authorize('view', $project);

        $row = $assets->findForProject($project, $asset);
        abort_if($row === null, 404);

        $mime = (string) $row->mime_type;
        abort_unless($row->isStoredUpload() && in_array($mime, DesignAsset::SERVABLE_MIME_TYPES, true), 404);

        $disk = Storage::disk($row->disk);
        abort_unless($disk->exists($row->file_path), 404);

        // ?size=preview: the lighter WebP copy the editor canvas draws (falls back to the original when there is none).
        $previewPath = $request->query('size') === 'preview' ? $row->previewPath() : null;
        $usePreview = $previewPath !== null && $disk->exists($previewPath);
        $servedPath = $usePreview ? $previewPath : $row->file_path;
        $servedMime = $usePreview ? 'image/webp' : $mime;

        $response = $disk->response($servedPath, null, [
            'Content-Type' => $servedMime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Vary' => 'Cookie',
        ]);

        $response->setPrivate();
        $response->setMaxAge(3600);
        $response->setEtag(($usePreview ? 'p-' : '').(string) ($row->content_hash ?: sha1($row->file_path)));
        $response->isNotModified($request);

        return $response;
    }
}
