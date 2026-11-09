<?php

namespace Tests\Support;

use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\DesignAiProposal;
use App\Models\Component;
use App\Models\DesignAsset;
use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\DesignProjectGrant;
use App\Models\DesignReview;
use App\Models\DesignShare;
use App\Models\DesignTemplate;
use App\Models\DesignToken;
use App\Models\TokenConsumptionRecord;
use App\Models\TokenSet;
use App\Models\Team;
use App\Models\User;
use App\Services\Design\VersionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A whole tenant: a workspace with every role and one of every kind of thing the studio stores, each carrying a unique
 * sentinel string so a leak into another tenant's response can be found by a plain substring search.
 *
 * Needs the StudioWorld and ImageFixtures traits in the same class.
 */
trait SecurityWorld
{
    protected bool $securityDiskFaked = false;

    /** The marker planted in every name, label and note of a tenant. */
    protected function sentinel(string $label): string
    {
        return 'SECRETMARK'.strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $label));
    }

    /**
     * @return array{label: string, sentinel: string, team: Team, owner: User, admin: User, editor: User, viewer: User, project: DesignProject, page: \App\Models\DesignCanvas,
     *   version: \App\Models\DesignVersion, comment: DesignComment, review: DesignReview, share: DesignShare, token: string, grant: DesignProjectGrant, asset: DesignAsset,
     *   kit: BrandKit, brandAsset: BrandAsset, template: DesignTemplate, proposal: DesignAiProposal}
     */
    protected function tenant(string $label, string $visibility = 'team'): array
    {
        // Fake the private disk once per test: faking again would wipe the files of tenants made earlier in the same test.
        if (! ($this->securityDiskFaked ?? false)) {
            Storage::fake('design_assets');
            $this->securityDiskFaked = true;
        }

        $mark = $this->sentinel($label);
        $w = $this->workspace();
        $w['team']->forceFill(['name' => $mark.' Ltd'])->save();

        foreach (['owner', 'admin', 'editor', 'viewer'] as $role) {
            $w[$role]->forceFill(['current_team_id' => $w['team']->id])->save();
            $w[$role] = $w[$role]->fresh();
        }

        $owner = $w['owner'];
        $project = $this->project($owner, $w['team'], $visibility, ['name' => $mark.' project']);
        $page = $project->canvases()->first();

        $bytes = $this->distinctPng(abs(crc32($label)) % 60000);
        $path = $owner->id.'/'.Str::random(20).'.png';
        Storage::disk('design_assets')->put($path, $bytes);

        $asset = DesignAsset::withoutGlobalScopes()->create([
            'user_id' => $owner->id, 'team_id' => $w['team']->id, 'design_project_id' => $project->id, 'name' => $mark.'.png',
            'source' => 'upload', 'type' => 'image', 'license' => 'own', 'file_path' => $path, 'disk' => 'design_assets', 'mime_type' => 'image/png',
            'content_hash' => hash('sha256', $bytes), 'file_size' => strlen($bytes), 'width' => 40, 'height' => 40, 'folder' => $mark.' folder', 'tags' => [$mark],
        ]);

        $page->forceFill(['elements' => [
            $this->textElement('t1', $mark.' headline'),
            ['id' => 'p1', 'type' => 'image', 'x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'asset_id' => $asset->id, 'fit' => 'cover'],
        ]])->save();

        $version = app(VersionService::class)->snapshot($project, 'manual', $owner, $mark.' version');
        $comment = DesignComment::create(['design_project_id' => $project->id, 'user_id' => $owner->id, 'body' => $mark.' comment']);
        $review = DesignReview::create([
            'design_project_id' => $project->id, 'version_id' => $version->id, 'requested_by' => $owner->id, 'reviewer_id' => $w['admin']->id,
            'status' => 'pending', 'note' => $mark.' review note',
        ]);

        $token = Str::random(40);
        $share = DesignShare::create([
            'design_project_id' => $project->id, 'created_by' => $owner->id, 'token_hash' => hash('sha256', $token), 'permission' => 'comment',
        ]);

        $grant = DesignProjectGrant::create(['design_project_id' => $project->id, 'user_id' => $w['editor']->id, 'level' => 'edit', 'granted_by' => $owner->id]);

        $kit = $this->makeKit($owner, ['name' => $mark.' kit', 'team_id' => $w['team']->id, 'tagline' => $mark.' tagline']);
        $brandAsset = BrandAsset::create(['brand_kit_id' => $kit->id, 'design_asset_id' => $asset->id, 'role' => 'logo', 'label' => $mark.' logo']);

        $template = DesignTemplate::create([
            'slug' => 'sec-'.strtolower($mark), 'name' => $mark.' template', 'source' => 'team', 'team_id' => $w['team']->id, 'user_id' => $owner->id,
            'category' => 'social', 'kind' => 'poster', 'width' => 100, 'height' => 100, 'status' => 'published',
            'pages' => [['elements' => [$this->textElement('t1', $mark.' template text')]]],
        ]);

        $proposal = DesignAiProposal::create([
            'design_project_id' => $project->id, 'team_id' => $w['team']->id, 'user_id' => $owner->id, 'mode' => 'improve', 'status' => 'proposed',
            'prompt' => $mark.' prompt', 'payload' => ['label' => $mark.' proposal', 'ops' => []],
        ]);

        $set = TokenSet::firstOrCreate(['slug' => 'sec-core'], ['name' => 'Core', 'version' => 1]);
        $ds = [
            'set' => $set,
            'token' => DesignToken::firstOrCreate(['token_set_id' => $set->id, 'slug' => 'sec-primary'], ['name' => 'Primary', 'category' => 'color', 'value' => '#112233', 'version' => 1]),
            'component' => Component::firstOrCreate(['slug' => 'sec-button'], ['name' => 'Button', 'category' => 'input', 'version' => 1]),
            'record' => TokenConsumptionRecord::firstOrCreate(['token_set_id' => $set->id, 'platform_id' => 'sec-platform'], ['pinned_version' => 1]),
        ];

        return $w + [
            'ds' => $ds,
            'label' => $label, 'sentinel' => $mark, 'project' => $project, 'page' => $page, 'version' => $version, 'comment' => $comment,
            'review' => $review, 'share' => $share, 'token' => $token, 'grant' => $grant, 'asset' => $asset, 'kit' => $kit,
            'brandAsset' => $brandAsset, 'template' => $template, 'proposal' => $proposal,
        ];
    }
}
