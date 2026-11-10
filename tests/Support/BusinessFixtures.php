<?php

namespace Tests\Support;

use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\DesignProject;
use App\Models\DesignTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\Design\StarterProjectBuilder;
use App\Services\Design\TemplateImporter;
use Illuminate\Support\Facades\Storage;

/** Builders shared by the R-45 business tests. */
trait BusinessFixtures
{
    protected function fixtureDir(): string
    {
        return base_path('tests/Fixtures/templates');
    }

    /** A user whose personal team is also their current workspace. */
    protected function workspaceUser(array $attributes = []): User
    {
        $user = User::factory()->withPersonalTeam()->create($attributes);
        $user->switchTeam($user->personalTeam());

        return $user->fresh();
    }

    protected function addMember(Team $team, User $member, string $role = 'editor'): User
    {
        $team->users()->attach($member, ['role' => $role]);
        $member->switchTeam($team);

        return $member->fresh();
    }

    /** Seed the three fixture templates as curated, published rows. */
    protected function seedFixtureTemplates(): void
    {
        $importer = app(TemplateImporter::class);
        $read = $importer->read($this->fixtureDir());
        $this->assertSame([], $read['refused'], json_encode($read['refused']));
        $importer->import($read['valid']);
    }

    protected function fixtureTemplate(string $slug = 'fixture-sale-bold-square'): DesignTemplate
    {
        return DesignTemplate::withoutGlobalScopes()->where('slug', $slug)->firstOrFail();
    }

    /** @param array<string, mixed> $colors */
    protected function makeKit(User $user, array $overrides = []): BrandKit
    {
        return BrandKit::create($overrides + [
            'user_id' => $user->id,
            'team_id' => $user->current_team_id,
            'name' => 'Test kit',
            'is_default' => true,
            'version' => 1,
            'colors' => [
                ['key' => 'primary', 'name' => 'Main colour', 'hex' => '#0057b8', 'role' => 'primary'],
                ['key' => 'secondary', 'name' => 'Second colour', 'hex' => '#ffb81c', 'role' => 'secondary'],
                ['key' => 'accent', 'name' => 'Highlight colour', 'hex' => '#00a651', 'role' => 'accent'],
                ['key' => 'neutral', 'name' => 'Neutral', 'hex' => '#f5f5f5', 'role' => 'neutral'],
            ],
            'fonts' => ['heading' => 'oswald', 'body' => 'inter', 'accent' => 'caveat'],
            'type_styles' => [],
            'tagline' => 'Built to last',
            'website' => 'www.testkit.co.za',
            'contacts' => ['phone' => '011 555 0100'],
        ]);
    }

    protected function fakeAssetDisk(): void
    {
        Storage::fake('design_assets');
    }

    protected function makeAsset(User $user, string $name = 'logo.png', ?string $bytes = null): DesignAsset
    {
        $path = 'u'.$user->id.'/'.uniqid().'.png';
        Storage::disk('design_assets')->put($path, $bytes ?? $this->logoBytes());

        return DesignAsset::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'name' => $name, 'type' => 'image', 'file_path' => $path, 'disk' => 'design_assets',
            'mime_type' => 'image/png', 'file_size' => 100, 'width' => 120, 'height' => 120, 'content_hash' => sha1($path),
        ]);
    }

    /** A logo-like PNG: white background, a big red disc and a smaller blue square. */
    protected function logoBytes(): string
    {
        $img = imagecreatetruecolor(120, 120);
        imagefilledrectangle($img, 0, 0, 119, 119, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, 50, 60, 80, 80, imagecolorallocate($img, 220, 30, 40));
        imagefilledrectangle($img, 80, 70, 110, 100, imagecolorallocate($img, 20, 60, 200));

        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /** A project owned by $user with the fixture sale template's pages (fresh ids), for brand tests. */
    protected function projectFromTemplate(User $user, ?BrandKit $kit = null, bool $apply = false, array $brief = []): DesignProject
    {
        $this->seedFixtureTemplates();

        $built = app(StarterProjectBuilder::class)->create(
            $user,
            $brief + ['goal' => 'announce_sale', 'business_name' => 'Thandi Bakery', 'details' => [], 'photos' => []],
            $this->fixtureTemplate(),
            $kit,
            $apply,
        );

        return $built['project'];
    }
}
