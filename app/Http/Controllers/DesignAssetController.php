<?php

namespace App\Http\Controllers;

use App\Events\AssetDeleted;
use App\Http\Requests\StoreDesignAssetRequest;
use App\Models\DesignAsset;
use App\Services\Assets\AssetRejected;
use App\Services\Assets\AssetUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DesignAssetController extends Controller
{
    /**
     * Upload an image. JSON in, JSON out: the editor calls this with fetch()
     * so the canvas (and anything not yet autosaved) stays on screen.
     */
    public function store(StoreDesignAssetRequest $request, AssetUploader $uploader): JsonResponse
    {
        try {
            [$asset, $created] = $uploader->store($request->user(), $request->file('file'), $request->project(), $request->libraryMeta());
        } catch (AssetRejected $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
                'reason' => $e->reason,
            ], 422);
        }

        return response()->json([
            'asset' => $asset->toEditorArray(),
            'duplicate' => ! $created,
            'usage' => $this->usage($request),
        ], $created ? 201 : 200);
    }

    /**
     * Serve an uploaded file. The only way to read one: the disk is private,
     * the route is behind auth, the asset is resolved through the per-user
     * scope and the policy, and the headers stop a browser from treating the
     * bytes as anything but the image we stored.
     */
    public function file(Request $request, DesignAsset $asset): Response
    {
        $this->authorize('view', $asset);

        $mime = (string) $asset->mime_type;
        abort_unless($asset->isStoredUpload() && in_array($mime, DesignAsset::SERVABLE_MIME_TYPES, true), 404);

        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->file_path), 404);

        // ?size=preview: the lighter WebP copy the editor canvas draws (falls back to the original when there is none).
        $previewPath = $request->query('size') === 'preview' ? $asset->previewPath() : null;
        $usePreview = $previewPath !== null && $disk->exists($previewPath);
        $servedPath = $usePreview ? $previewPath : $asset->file_path;
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
        $response->setEtag(($usePreview ? 'p-' : '').(string) ($asset->content_hash ?: sha1($asset->file_path)));
        $response->isNotModified($request);

        return $response;
    }

    public function destroy(Request $request, DesignAsset $asset): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $asset);

        // An image element points at the asset by id; deleting it would leave
        // the canvas with a hole (and the next autosave failing validation).
        ['visible' => $placements, 'hidden' => $hidden] = $asset->placementsIncludingHidden();

        if ($placements !== [] || $hidden > 0) {
            $first = $placements[0] ?? null;
            $others = count($placements) - ($first ? 1 : 0) + $hidden;
            $more = $others > 0 ? ($first ? ' and '.$others.' more page(s)' : ' on '.$others.' page(s) you cannot see') : '';
            $where = $first ? "{$first['project']}, Page {$first['page']}" : 'a design in your workspace';
            $message = "\"{$asset->name}\" is placed on {$where}{$more}. Remove it from the canvas first.";

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'placements' => $placements], 409)
                : back()->with('error', $message);
        }

        $assetId = $asset->id;
        $assetName = $asset->name;
        $userId = $asset->user_id;

        // Delete the row first, then the file: the shared-file check ignores
        // this row, and a failed file delete must not resurrect the record.
        $asset->delete();

        try {
            $asset->deleteStoredFile();
        } catch (\Throwable $e) {
            report($e);
        }

        event(new AssetDeleted($assetId, $assetName, $userId));

        if ($request->expectsJson()) {
            return response()->json(['status' => 'deleted', 'usage' => $this->usage($request)]);
        }

        return back()->with('status', 'Asset deleted.');
    }

    private function usage(Request $request): array
    {
        return DesignAsset::usageFor($request->user()->id);
    }
}
