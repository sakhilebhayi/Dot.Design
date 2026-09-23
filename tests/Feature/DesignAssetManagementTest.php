<?php

namespace Tests\Feature;

use App\Events\AssetDeleted;
use App\Models\DesignAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DesignAssetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_delete_their_own_asset(): void
    {
        Event::fake([AssetDeleted::class]);
        $user = User::factory()->withPersonalTeam()->create();
        $asset = DesignAsset::create([
            'user_id' => $user->id, 'name' => 'my-image.png', 'type' => 'image', 'file_path' => 'https://example.test/x.png',
        ]);

        $response = $this->actingAs($user)->delete(route('design.assets.destroy', $asset));

        $response->assertRedirect();
        $this->assertNull(DesignAsset::find($asset->id));
        Event::assertDispatched(AssetDeleted::class, fn ($event) => $event->assetId === $asset->id && $event->assetName === 'my-image.png');
    }

    public function test_a_user_cannot_delete_another_users_asset(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $asset = DesignAsset::create([
            'user_id' => $owner->id, 'name' => 'private.png', 'type' => 'image', 'file_path' => 'https://example.test/private.png',
        ]);

        $this->actingAs($intruder)
            ->delete(route('design.assets.destroy', $asset))
            ->assertNotFound();

        $this->assertNotNull(DesignAsset::withoutGlobalScopes()->find($asset->id));
    }

    public function test_guests_are_redirected(): void
    {
        $asset = DesignAsset::create([
            'user_id' => User::factory()->create()->id, 'name' => 'x.png', 'type' => 'image', 'file_path' => 'https://example.test/x.png',
        ]);

        $this->delete(route('design.assets.destroy', $asset))->assertRedirect(route('login'));
    }
}
