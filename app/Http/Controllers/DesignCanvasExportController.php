<?php

namespace App\Http\Controllers;

use App\Events\DesignExported;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Services\CanvasSvgExporter;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The export pipeline README lists as a feature but wiki.md's Roadmap
 * confirms has no code yet. SVG only for this pass -- elements are already
 * structured data, so no new dependency is needed; PNG/JPEG/PDF (Roadmap)
 * would need an actual rendering engine and are left for a later pass.
 */
class DesignCanvasExportController extends Controller
{
    public function svg(DesignProject $project, DesignCanvas $canvas, CanvasSvgExporter $exporter): Response
    {
        $this->authorize('view', $project);
        abort_unless($canvas->design_project_id === $project->id, 404);

        $svg = $exporter->export($canvas);

        event(new DesignExported($canvas, 'svg'));

        $filename = Str::slug($project->name).'-page-'.$canvas->page_number.'.svg';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
