<?php

namespace App\Services\Design;

use App\Models\BrandKit;
use App\Models\DesignProject;
use App\Models\DesignUserSetting;
use App\Models\User;
use App\Support\Ui\Icons;
use App\Services\Integrations\Contracts\FileVault;
use App\Services\Integrations\Contracts\NotificationGateway;
use Illuminate\Support\Facades\Route;

/**
 * Builds the one JSON object the editor page boots from (docs/R45_UI_EDITOR.md). Nothing here is secret: it is the
 * document the viewer may already read through the API, plus the lists the panels draw from (fonts, formats, icons,
 * the default brand kit) and the named URLs. The page renders it into a non-executable JSON script tag.
 */
class EditorBoot
{
    public function __construct(
        private readonly DocumentPresenter $presenter,
        private readonly Formats $formats,
        private readonly BrandKitService $kits,
        private readonly ProjectMeta $meta,
    ) {}

    /** @return array<string, mixed> */
    public function build(DesignProject $project, User $user): array
    {
        $document = $this->presenter->document($project, $user);
        $permissions = $document['permissions'];
        $link = $this->meta->brandLink($project);

        return [
            'version' => 1,
            'project' => $document['project'] + [
                'format_key' => $this->meta->formatKey($project),
                'template_id' => $this->meta->templateId($project),
                'brief' => (object) $this->meta->brief($project),
                'brand_kit_id' => $link['kit_id'],
                'brand_version' => $link['version'],
            ],
            'pages' => $document['pages'],
            'assets' => $document['assets'],
            'permissions' => $permissions,
            'limits' => $document['limits'],
            'read_corrections' => $document['read_corrections'],
            'brand' => $this->brand($project, $user, $link['kit_id']),
            'fonts' => $this->fonts(),
            'icons' => $this->icons(),
            'uiIcons' => $this->uiIcons(),
            'formats' => $this->formatList(),
            'settings' => $this->settings($user),
            'flags' => [
                'ai' => trim((string) config('services.anthropic.api_key', '')) !== '',
                'filesVault' => $this->safely(fn () => app(FileVault::class)->enabled()),
                'notify' => $this->safely(fn () => app(NotificationGateway::class)->enabled()),
            ],
            'urls' => $this->urls($project),
            'user' => ['id' => $user->id, 'name' => $user->name],
        ];
    }

    /** @return array<string, mixed>|null the project's linked kit, else the workspace default */
    private function brand(DesignProject $project, User $user, ?int $linkedKitId): ?array
    {
        $kit = $linkedKitId !== null ? BrandKit::query()->find($linkedKitId) : null;
        $kit ??= $this->kits->defaultFor($user, $project->team_id !== null ? (int) $project->team_id : null);

        return $kit === null ? null : $this->kits->present($kit, $user) + ['linked' => $linkedKitId !== null && $kit->id === $linkedKitId];
    }

    /** @return list<array<string, mixed>> config/design_fonts.php entries with `files` turned into URLs */
    private function fonts(): array
    {
        $out = [];

        foreach ((array) config('design_fonts.fonts', []) as $font) {
            $files = [];
            foreach ((array) ($font['files'] ?? []) as $variant => $path) {
                $files[(string) $variant] = asset((string) $path);
            }
            $out[] = array_merge($font, ['files' => $files]);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function icons(): array
    {
        $file = resource_path('design/icons.json');
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? array_filter($decoded, fn ($key) => $key !== '$comment', ARRAY_FILTER_USE_KEY) : [];
    }

    /** @return array<string, string> the interface icon set (inner SVG shapes on a 24 grid), keyed by name */
    private function uiIcons(): array
    {
        $out = [];

        foreach (Icons::names() as $name) {
            $out[$name] = (string) Icons::path($name);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function formatList(): array
    {
        return array_values(array_map(fn (array $f) => [
            'key' => $f['key'],
            'label' => $f['label'],
            'group' => $f['group'],
            'kind' => $f['kind'],
            'unit' => $f['unit'],
            'width' => $f['width'],
            'height' => $f['height'],
            'px' => $f['px'],
            'size_label' => $f['size_label'],
            'family' => $f['family'] ?? null,
            'bleed_px' => $f['bleed_px'] ?? 0,
            'safe_px' => $f['safe_px'] ?? null,
        ], $this->formats->all()));
    }

    /** @return array<string, mixed> */
    private function settings(User $user): array
    {
        $row = DesignUserSetting::query()->where('user_id', $user->id)->first();

        return [
            'mode' => $row?->mode ?? 'simple',
            'snap' => $row === null ? true : (bool) $row->snap,
            'grid' => $row === null ? false : (bool) $row->grid,
            'rulers' => $row === null ? false : (bool) $row->rulers,
            'reduced_motion_pref' => $row?->reduced_motion_pref ?? 'system',
            'onboarded' => $row?->onboarded_at !== null,
        ];
    }

    /**
     * Every named studio route as a path, with {project} already filled in. Other placeholders stay as {name} for the
     * caller to fill. Modules read these by route name so no panel hard-codes a path.
     *
     * @return array<string, string>
     */
    private function urls(DesignProject $project): array
    {
        $out = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'design.')) {
                continue;
            }

            $path = '/'.ltrim($route->uri(), '/');
            $path = str_replace(['{project}', '{project?}'], (string) $project->id, $path);
            $out[$name] = $path;
        }

        $out['dashboard'] = '/dashboard';
        $out['login'] = Route::has('login') ? '/'.ltrim(route('login', [], false), '/') : '/login';

        ksort($out);

        return $out;
    }

    private function safely(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (\Throwable) {
            return false;
        }
    }
}
