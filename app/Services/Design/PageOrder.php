<?php

namespace App\Services\Design;

use App\Models\DesignCanvas;
use App\Models\DesignProject;
use Illuminate\Support\Facades\DB;

/**
 * Page numbering. (design_project_id, page_number) is unique, so renumbering is done in two passes: every page is
 * first moved far out of range, then given its final number, which can never collide with a row still to be moved.
 */
class PageOrder
{
    private const PARK = 10_000_000;

    /**
     * Number the project's pages 1..n in the given order. Pages not listed keep their relative order after the listed ones.
     *
     * @param  list<int>  $orderedIds
     */
    public function apply(DesignProject $project, array $orderedIds): void
    {
        DB::transaction(function () use ($project, $orderedIds): void {
            $all = DesignCanvas::where('design_project_id', $project->id)->orderBy('page_number')->orderBy('id')->pluck('id')->all();
            $listed = array_values(array_intersect($orderedIds, $all));
            $final = array_merge($listed, array_values(array_diff($all, $listed)));

            DesignCanvas::where('design_project_id', $project->id)->update(['page_number' => DB::raw('page_number + '.self::PARK)]);

            foreach ($final as $index => $id) {
                DesignCanvas::whereKey($id)->update(['page_number' => $index + 1]);
            }
        });
    }
}
