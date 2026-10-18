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
            [$asset, $created] = $uploader->store($request->user(), $request->file('file'), $request->project());
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

        $response = $disk->response($asset->file_path, null, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Vary' => 'Cookie',
        ]);

        $response->setPrivate();
        $response->setMaxAge(3600);
        $response->setEtag((string) ($asset->content_hash ?: sha1($asset->file_path)));
        $response->isNotModified($request);

        return $response;
    }

    public function destroy(Request $request, DesignAsset $asset): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $asset);

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
