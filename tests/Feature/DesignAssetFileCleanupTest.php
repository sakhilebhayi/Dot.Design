<?php

namespace Tests\Feature;

use App\Models\DesignAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DesignAssetFileCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function asset(User $user, string $path): DesignAsset
    {
        return DesignAsset::create(['user_id' => $user->id, 'name' => 'a.png', 'type' => 'image', 'file_path' => $path]);
    }

    public function test_deleting_an_asset_removes_its_local_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('assets/a.png', 'x');
        $user = User::factory()->withPersonalTeam()->create();
        $asset = $this->asset($user, 'assets/a.png');

        $this->actingAs($user)->delete(route('design.assets.destroy', $asset))->assertRedirect();

        $this->assertNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
        Storage::disk('public')->assertMissing('assets/a.png');
    }

    public function test_remote_urls_are_never_treated_as_local_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('https:/example.test/a.png', 'x'); // would only be removed by a bug
        $user = User::factory()->withPersonalTeam()->create();
        $asset = $this->asset($user, 'https://example.test/a.png');

        $this->actingAs($user)->delete(route('design.assets.destroy', $asset))->assertRedirect();

        $this->assertNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
        Storage::disk('public')->assertExists('https:/example.test/a.png');
    }

    public function test_file_shared_with_another_asset_row_is_kept(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('assets/shared.png', 'x');
        $user = User::factory()->withPersonalTeam()->create();
        $first = $this->asset($user, 'assets/shared.png');
        $this->asset($user, 'assets/shared.png');

        $this->actingAs($user)->delete(route('design.assets.destroy', $first))->assertRedirect();

        Storage::disk('public')->assertExists('assets/shared.png');
    }

    public function test_path_traversal_in_file_path_is_ignored(): void
    {
        Storage::fake('public');
        $user = User::factory()->withPersonalTeam()->create();
        $asset = $this->asset($user, '../../.env');

        $this->actingAs($user)->delete(route('design.assets.destroy', $asset))->assertRedirect();

        $this->assertNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
    }

    public function test_a_missing_file_does_not_block_deleting_the_row(): void
    {
        Storage::fake('public');
        $user = User::factory()->withPersonalTeam()->create();
        $asset = $this->asset($user, 'assets/gone.png');

        $this->actingAs($user)->delete(route('design.assets.destroy', $asset))->assertRedirect();

        $this->assertNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
    }
}
