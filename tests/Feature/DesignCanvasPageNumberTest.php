<?php

namespace Tests\Feature;

use App\Models\DesignCanvas;
use App\Models\DesignProject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DesignCanvasPageNumberTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): DesignProject
    {
        $project = DesignProject::create([
            'user_id' => $user->id, 'name' => 'P', 'type' => 'graphic', 'width' => 1200, 'height' => 628, 'unit' => 'px',
        ]);
        $project->canvases()->create(['page_number' => 1, 'elements' => []]);

        return $project;
    }

    public function test_page_numbers_are_unique_per_project_at_the_database_level(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);

        $this->expectException(QueryException::class);

        DB::table('design_canvases')->insert([
            'design_project_id' => $project->id, 'page_number' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_same_page_number_is_allowed_in_different_projects(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $a = $this->project($user);
        $b = $this->project($user);

        $this->assertSame(1, $a->canvases()->count());
        $this->assertSame(1, $b->canvases()->count());
    }

    public function test_adding_pages_numbers_them_sequentially(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);

        $this->actingAs($user)->post(route('design.projects.canvases.store', $project))->assertRedirect();
        $this->actingAs($user)->post(route('design.projects.canvases.store', $project))->assertRedirect();

        $this->assertSame([1, 2, 3], $project->canvases()->pluck('page_number')->all());
    }

    public function test_a_unique_violation_on_insert_is_retried(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);

        // A concurrent request winning the race surfaces as a unique-index
        // violation on our insert. (A truly committed competing row cannot be
        // staged inside the test transaction, so the violation is injected.)
        $failures = 0;
        DesignCanvas::creating(function () use (&$failures) {
            if ($failures < 1) {
                $failures++;
                throw new UniqueConstraintViolationException('sqlite', 'insert into design_canvases', [], new \Exception('UNIQUE'));
            }
        });

        $this->actingAs($user)->post(route('design.projects.canvases.store', $project))->assertRedirect();

        $this->assertSame(1, $failures);
        $this->assertSame([1, 2], $project->canvases()->pluck('page_number')->all());
    }

    public function test_it_gives_up_after_repeated_violations(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);

        $attempts = 0;
        DesignCanvas::creating(function () use (&$attempts) {
            $attempts++;
            throw new UniqueConstraintViolationException('sqlite', 'insert into design_canvases', [], new \Exception('UNIQUE'));
        });

        try {
            $project->addPage();
            $this->fail('Expected the violation to surface after retries.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(5, $attempts);
        }

        $this->assertSame(1, $project->canvases()->count());
    }

    public function test_migration_dedupes_existing_duplicate_pages_before_adding_the_index(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $project = $this->project($user);

        $migration = require database_path('migrations/2026_10_18_000002_add_unique_page_number_to_design_canvases.php');
        $migration->down();

        // Legacy data produced by the old race: two pages numbered 1, plus a 3.
        foreach ([1, 3] as $n) {
            DB::table('design_canvases')->insert([
                'design_project_id' => $project->id, 'page_number' => $n, 'elements' => '["keep"]', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->assertSame(3, DB::table('design_canvases')->count());

        $migration->up();

        $numbers = DB::table('design_canvases')->where('design_project_id', $project->id)->orderBy('page_number')->pluck('page_number')->all();
        $this->assertSame(3, count($numbers));
        $this->assertSame($numbers, array_values(array_unique($numbers)));
        // No row was dropped and the original numbers were kept where they were unique.
        $this->assertSame(3, DB::table('design_canvases')->count());
        $this->assertContains(1, $numbers);
        $this->assertContains(3, $numbers);
    }
}
