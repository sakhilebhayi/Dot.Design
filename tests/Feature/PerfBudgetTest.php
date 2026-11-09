<?php

namespace Tests\Feature;

use App\Models\DesignTemplate;
use App\Models\User;
use App\Support\Db\Columns;
use App\Support\Ui\DesignListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 perf budget: deterministic operation counts (queries, rows), never wall clock, so a gross regression fails here on any
 * machine. The measured numbers are in docs/R45_PERF.md.
 */
class PerfBudgetTest extends TestCase
{
    use RefreshDatabase;
    use StudioWorld;

    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function manyProjects(int $count): User
    {
        $user = $this->person();
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['user_id' => $user->id, 'name' => 'Design '.$i, 'type' => ['graphic', 'poster', 'social'][$i % 3], 'width' => 1080, 'height' => 1350, 'unit' => 'px', 'created_at' => now()->subMinutes($i), 'updated_at' => now()->subMinutes($i), 'visibility' => 'private', 'status' => 'draft'];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('design_projects')->insert($chunk);
        }

        return $user;
    }

    public function test_the_designs_list_reads_one_page_whatever_the_number_of_designs(): void
    {
        $user = $this->manyProjects(150);
        $request = fn () => $this->actingAs($user)->get(route('design.projects.index'))->assertOk();
        $request();
        $small = $this->queries($request);

        $this->assertLessThanOrEqual(8, $small, 'the designs list should stay a handful of queries');

        $html = $this->actingAs($user)->get(route('design.projects.index'))->getContent();
        $this->assertSame(DesignListing::PER_PAGE, substr_count($html, 'class="lt-contact"'), 'one page of cards, not all 150');
        $this->assertStringContainsString('page 1 of 4', $html);

        $second = $this->actingAs($user)->get(route('design.projects.index', ['page' => 4]))->getContent();
        $this->assertSame(150 - 3 * DesignListing::PER_PAGE, substr_count($second, 'class="lt-contact"'));
    }

    public function test_the_designs_list_filters_and_sorts_in_sql_and_keeps_its_counts(): void
    {
        $user = $this->manyProjects(60);

        $html = $this->actingAs($user)->get(route('design.projects.index', ['type' => 'poster', 'q' => 'Design 1']))->getContent();
        $this->assertStringContainsString('Design 19', $html);
        $this->assertStringNotContainsString('Design 18<', $html, 'type filter applied');

        $named = $this->actingAs($user)->get(route('design.projects.index', ['sort' => 'name']))->getContent();
        $this->assertLessThan(strpos($named, 'Design 10<'), strpos($named, 'Design 0<'), 'name order');
    }

    public function test_template_facets_are_two_queries_however_many_templates_there_are(): void
    {
        $user = $this->person();
        for ($i = 0; $i < 30; $i++) {
            DesignTemplate::query()->create(['slug' => 'perf-'.$i, 'name' => 'T'.$i, 'category' => ['social', 'commercial'][$i % 2], 'kind' => 'post', 'width' => 100, 'height' => 100, 'pages' => [['elements' => []]], 'status' => 'published', 'source' => 'curated']);
        }

        $count = $this->queries(fn () => $this->actingAs($user)->getJson(route('design.templates.facets'))->assertOk()->assertJsonPath('total', 30));

        // session user, the two grouped counts, and whatever the auth layer reads: never a row per template.
        $this->assertLessThanOrEqual(5, $count);
    }

    public function test_a_known_column_is_not_asked_of_the_catalogue_twice(): void
    {
        $this->assertTrue(Columns::has('design_projects', 'brief'));
        $this->assertSame(0, $this->queries(fn () => $this->assertTrue(Columns::has('design_projects', 'brief'))));
        $this->assertFalse(Columns::has('design_projects', 'no_such_column'), 'a missing column is never remembered');
    }
}
