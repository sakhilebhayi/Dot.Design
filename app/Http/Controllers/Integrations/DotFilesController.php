<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\DesignProject;
use App\Services\Integrations\Contracts\FileVault;
use App\Services\Integrations\Files\ExportFiler;
use App\Services\Integrations\IntegrationRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Save to Dot.Files": takes an export the browser produced and files it in the business's Dot.Files. Does nothing (404) while
 * the integration is switched off, so no control for it is ever offered that cannot work.
 */
class DotFilesController extends Controller
{
    public function store(Request $request, DesignProject $project, FileVault $vault, ExportFiler $filer): JsonResponse|RedirectResponse
    {
        abort_unless($vault->enabled(), 404);
        // Filing an export into the business's shared Dot.Files is taking a file out: the Export capability, not just sight of the design.
        $this->authorize('export', $project);

        // Validated by hand: the app renders JSON errors only for /api/*, so the framework's own redirect would hide them from fetch().
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:25600'],
            'format' => ['required', 'string', Rule::in($filer->formats())],
            'scope' => ['required', 'string', 'regex:/^(all|page-[0-9]{1,3})$/'],
            // Files filed in Dot.Files are visible to everyone in the business, whatever this design's own visibility is.
            'acknowledge' => ['accepted'],
        ]);

        if ($validator->fails()) {
            return $request->expectsJson()
                ? response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422)
                : back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        try {
            $result = $filer->file($project, $request->user(), $data['scope'], $data['format'], (string) file_get_contents($data['file']->getRealPath()));
        } catch (IntegrationRefused $e) {
            return $request->expectsJson()
                ? response()->json(['error' => $e->getMessage()], $e->status === 409 ? 409 : 422)
                : back()->with('error', $e->getMessage());
        }

        $message = ($result['new_version'] ? 'Saved a new version of ' : 'Saved ').$result['name'].' in Dot.Files.';

        return $request->expectsJson()
            ? response()->json(['status' => 'filed', 'message' => $message, 'name' => $result['name'], 'open_url' => $result['open_url'], 'version' => $result['version']], 201)
            : back()->with('status', $message);
    }
}
