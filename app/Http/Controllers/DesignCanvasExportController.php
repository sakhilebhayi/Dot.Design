<?php

namespace App\Http\Controllers;

use App\Events\DesignExported;
use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Services\CanvasPngExporter;
use App\Services\CanvasSvgExporter;
use App\Services\ExportRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Canvas export. SVG is the format that always works: elements are already
 * structured data, so it needs no rendering engine. PNG is rasterised with
 * GD and is deliberately narrower -- see CanvasPngExporter for what it
 * refuses. JPEG and PDF do not exist; nothing here claims them.
 */
class DesignCanvasExportController extends Controller
{
    public function svg(DesignProject $project, DesignCanvas $canvas, CanvasSvgExporter $exporter): Response
    {
        $this->authorize('view', $project);
        abort_unless($canvas->design_project_id === $project->id, 404);

        $svg = $exporter->export($canvas);

        event(new DesignExported($canvas, 'svg'));

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($project, $canvas, 'svg').'"',
        ]);
    }

    public function png(DesignProject $project, DesignCanvas $canvas, CanvasPngExporter $exporter): Response|RedirectResponse
    {
        $this->authorize('view', $project);
        abort_unless($canvas->design_project_id === $project->id, 404);

        try {
            $png = $exporter->export($canvas);
        } catch (ExportRefused $e) {
            return back()->with('error', $e->getMessage());
        }

        event(new DesignExported($canvas, 'png'));

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($project, $canvas, 'png').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filename(DesignProject $project, DesignCanvas $canvas, string $extension): string
    {
        return (Str::slug($project->name) ?: 'design').'-page-'.$canvas->page_number.'.'.$extension;
    }
}
