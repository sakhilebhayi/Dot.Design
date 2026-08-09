<?php

namespace Tests\Feature\Console;

use App\Models\TokenDriftNotice;
use App\Models\TokenSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanTokenDriftCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeTokenSet(int $version): TokenSet
    {
        return TokenSet::create([
            'name' => 'Core Palette',
            'slug' => 'core-palette-'.uniqid(),
            'version' => $version,
        ]);
    }

    public function test_a_drifted_record_creates_exactly_one_notice(): void
    {
        $tokenSet = $this->makeTokenSet(3);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.auction',
            'pinned_version' => 1,
        ]);

        $this->artisan('design-system:scan-token-drift')->assertSuccessful();
        $this->artisan('design-system:scan-token-drift')->assertSuccessful();

        $this->assertDatabaseCount('token_drift_notices', 1);
        $this->assertDatabaseHas('token_drift_notices', [
            'token_set_id' => $tokenSet->id,
            'platform_id' => 'dot.auction',
            'pinned_version' => 1,
            'current_version' => 3,
            'cleared_at' => null,
        ]);
    }

    public function test_a_record_already_current_creates_no_notice(): void
    {
        $tokenSet = $this->makeTokenSet(2);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.billing',
            'pinned_version' => 2,
        ]);

        $this->artisan('design-system:scan-token-drift')->assertSuccessful();

        $this->assertSame(0, TokenDriftNotice::count());
    }

    public function test_a_record_that_catches_up_gets_its_notice_auto_cleared(): void
    {
        $tokenSet = $this->makeTokenSet(2);
        $record = $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.central',
            'pinned_version' => 1,
        ]);

        $this->artisan('design-system:scan-token-drift')->assertSuccessful();
        $notice = TokenDriftNotice::firstOrFail();
        $this->assertNull($notice->cleared_at);

        $record->update(['pinned_version' => 2, 'last_synced_at' => now()]);
        $this->artisan('design-system:scan-token-drift')->assertSuccessful();

        $this->assertNotNull($notice->fresh()->cleared_at);
    }

    public function test_a_record_that_falls_further_behind_refreshes_the_existing_notice(): void
    {
        $tokenSet = $this->makeTokenSet(2);
        $tokenSet->consumptionRecords()->create([
            'platform_id' => 'dot.central',
            'pinned_version' => 1,
        ]);

        $this->artisan('design-system:scan-token-drift')->assertSuccessful();
        $notice = TokenDriftNotice::firstOrFail();
        $originalDetectedAt = $notice->detected_at;

        $tokenSet->update(['version' => 5]);
        $this->artisan('design-system:scan-token-drift')->assertSuccessful();

        $this->assertSame(1, TokenDriftNotice::count());
        $notice->refresh();
        $this->assertSame(5, $notice->current_version);
        $this->assertEquals($originalDetectedAt, $notice->detected_at);
    }
}
