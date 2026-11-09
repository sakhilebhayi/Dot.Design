<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\DesignAsset;
use App\Models\DesignCanvas;
use App\Services\CanvasSvgExporter;
use App\Services\Design\ProjectAssets;
use App\Services\Design\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Share-link viewing. No account, no session, no cookie. Every failure (a malformed token, one that never existed, a
 * revoked or expired link) gets the same 404 page, so nobody can probe which links exist. Visits are counted without
 * recording who made them.
 */
class PublicShareController extends Controller
{
    public function __construct(
        private readonly ShareService $shares,
        private readonly CanvasSvgExporter $svg,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $share = $this->shares->findActive($token);

        if ($share === null) {
            return $this->unavailable();
        }

        $this->shares->recordView($share);
        $document = $this->shares->publicDocument($share, $token);
        $urls = collect($document['assets'])->map(fn ($asset) => $asset['url'])->all();

        $pages = DesignCanvas::where('design_project_id', $share->design_project_id)->orderBy('page_number')->get()
            ->map(fn (DesignCanvas $page) => [
                'number' => (int) $page->page_number,
                'name' => $page->name,
                'svg' => $this->svg->export($page, fn ($assetId) => $urls[(int) $assetId] ?? null),
            ])->all();

        return response()->view('design.share', [
            'document' => $document,
            'pages' => $pages,
            'token' => $token,
            'canComment' => $share->permission === 'comment',
        ]);
    }

    /** A short-lived signed URL for one picture of the shared project. The link must still be live. */
    public function asset(Request $request, string $token, int $asset, ProjectAssets $assets): Response
    {
        $share = $this->shares->findActive($token);

        if ($share === null) {
            return $this->unavailable();
        }

        $row = in_array($asset, $assets->referencedIds($share->project), true)
            ? DesignAsset::withoutGlobalScopes()->find($asset)
            : null;

        if ($row === null || ! $row->isStoredUpload() || ! in_array($row->mime_type, DesignAsset::SERVABLE_MIME_TYPES, true)
            || ! Storage::disk($row->disk)->exists($row->file_path)) {
            return $this->unavailable();
        }

        $response = Storage::disk($row->disk)->response($row->file_path, null, [
            'Content-Type' => $row->mime_type,
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        return $response;
    }

    public function comment(Request $request, string $token): JsonResponse
    {
        $share = $this->shares->findActive($token);

        if ($share === null || $share->permission !== 'comment') {
            return response()->json(['message' => 'This link is not available.'], 404);
        }

        // A field real visitors never see. A bot that fills it gets the same answer as a person and nothing is stored.
        if (filled($request->input('website'))) {
            return response()->json(['status' => 'created'], 201);
        }

        $data = $request->validate([
            'guest_name' => ['required', 'string', 'min:1', 'max:60'],
            'body' => ['required', 'string', 'max:'.(int) config('design.studio.comments.max_length', 2000)],
            'page_id' => ['nullable', 'integer'],
            'element_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'x' => ['nullable', 'numeric', 'between:-40000,40000', 'required_with:y'],
            'y' => ['nullable', 'numeric', 'between:-40000,40000', 'required_with:x'],
        ]);

        $clean = fn (string $s): string => trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace(["\r\n", "\r"], "\n", $s)));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $data['guest_name']));
        $body = $clean($data['body']);

        if ($name === '' || $body === '') {
            return response()->json(['message' => 'Your name and a comment are both needed.', 'errors' => ['body' => ['Write a comment.']]], 422);
        }

        if (isset($data['page_id']) && ! DesignCanvas::where('design_project_id', $share->design_project_id)->whereKey($data['page_id'])->exists()) {
            return response()->json(['message' => 'That page is not part of this design.', 'errors' => ['page_id' => ['Unknown page.']]], 422);
        }

        $comment = $this->shares->addGuestComment($share, ['guest_name' => $name, 'body' => $body] + $data);

        return response()->json(['status' => 'created', 'comment' => [
            'id' => $comment->id, 'guest_name' => $comment->guest_name, 'body' => $comment->body, 'created_at' => $comment->created_at?->toIso8601String(),
        ]], 201);
    }

    private function unavailable(): Response
    {
        return response()->view('design.share-unavailable', ['message' => 'This link is not available. It may have expired, or the person who shared it turned it off.'], 404);
    }
}
