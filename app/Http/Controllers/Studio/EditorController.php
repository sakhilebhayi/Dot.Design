<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\DesignProject;
use App\Services\Design\EditorBoot;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The editor workspace page. It renders the shell and one JSON boot object; everything else is the browser app in
 * resources/js/studio. A project the viewer cannot see never reaches this method (route binding answers 404).
 */
class EditorController extends Controller
{
    public function show(Request $request, DesignProject $project, EditorBoot $boot): View
    {
        $this->authorize('view', $project);

        return view('design.projects.show', [
            'project' => $project,
            'boot' => $boot->build($project, $request->user()),
        ]);
    }
}
