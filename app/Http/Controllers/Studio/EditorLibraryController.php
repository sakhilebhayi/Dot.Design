<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the built-in design elements (badges, frames, dividers, patterns, gradients, palettes) to the editor.
 * The file is 400 KB, so the editor asks for it the first time the Elements panel opens instead of carrying it in
 * the page. It is static content, the same for everyone, and public to any signed-in user.
 */
class EditorLibraryController extends Controller
{
    public function elements(): BinaryFileResponse
    {
        $file = resource_path('design/elements.json');
        abort_unless(is_file($file), 404);

        $response = response()->file($file, ['Content-Type' => 'application/json', 'X-Content-Type-Options' => 'nosniff']);
        $response->setPrivate();
        $response->setMaxAge(3600);
        $response->setEtag(md5_file($file) ?: '');

        return $response;
    }
}
