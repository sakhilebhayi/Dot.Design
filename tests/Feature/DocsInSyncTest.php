<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * docs/API.md (route table) and docs/DATABASE.md (table list) are generated from the code. If this fails, a route or a
 * migration changed without the docs: run `php scripts/ops/generate-docs.php` and commit the result.
 */
class DocsInSyncTest extends TestCase
{
    public function test_the_generated_parts_of_the_docs_match_the_code(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/ops/generate-docs.php'), '--check'], base_path());
        $process->setTimeout(120);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), "The generated docs are stale.\n".$process->getErrorOutput().$process->getOutput());
    }
}
