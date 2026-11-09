<?php

namespace App\Services\Design;

use App\Contracts\Design\ActivityRecorder;
use App\Models\BrandAsset;
use App\Models\BrandKit;
use App\Models\DesignAsset;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brand kit CRUD with validation. Any change to what the kit says bumps `version` and tells the
 * projects that took colours or fonts from it that an update is available. It never touches a design.
 */
class BrandKitService
{
    public const COLOR_ROLES = ['primary', 'secondary', 'accent', 'neutral', 'extra'];

    public const FONT_SLOTS = ['heading', 'body', 'accent'];

    public const CONTACT_KEYS = ['phone', 'whatsapp', 'email', 'address', 'social_handle'];

    public const MAX_KITS_PER_WORKSPACE = 25;

    public function __construct(
        private readonly BriefValidator $text,
        private readonly BrandService $brand,
        private readonly AssetAccess $assets,
        private readonly ActivityRecorder $activity,
    ) {}

    /** @return Collection<int, BrandKit> */
    public function listFor(User $user): Collection
    {
        return BrandKit::query()->with('assets.asset')->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** @param array<string, mixed> $input */
    public function create(User $user, array $input): BrandKit
    {
        $teamId = $this->workspaceFor($user, $input);
        $data = $this->normalise($input, true);

        $count = BrandKit::query()->where('user_id', $user->id)->when($teamId, fn ($q) => $q->orWhere('team_id', $teamId))->count();
        if ($count >= self::MAX_KITS_PER_WORKSPACE) {
            throw ValidationException::withMessages(['name' => 'You have reached the limit of '.self::MAX_KITS_PER_WORKSPACE.' brand kits. Delete one you no longer use.']);
        }

        return DB::transaction(function () use ($user, $teamId, $data): BrandKit {
            $first = ! BrandKit::query()->when($teamId, fn ($q) => $q->where('team_id', $teamId), fn ($q) => $q->whereNull('team_id')->where('user_id', $user->id))->exists();

            $kit = BrandKit::create($data + [
                'user_id' => $user->id,
                'team_id' => $teamId,
                'is_default' => $first,
                'version' => 1,
            ]);

            $this->activity->record('brand.kit.created', $user, $teamId, null, 'brand_kit', $kit->id);

            return $kit;
        });
    }

    /**
     * @param  array<string, mixed>  $input  partial: only keys present are changed
     * @return array{kit: BrandKit, changed: bool, summary: array<string, mixed>}
     */
    public function update(BrandKit $kit, User $user, array $input): array
    {
        $data = $this->normalise($input, false);

        return DB::transaction(function () use ($kit, $user, $data): array {
            $kit = BrandKit::query()->lockForUpdate()->findOrFail($kit->id);
            $before = $this->content($kit);

            $kit->fill($data);
            $after = $this->content($kit);

            $contentChanged = json_encode($before) !== json_encode($after);
            $from = $kit->version;
            $summary = $contentChanged ? $this->summarise($before, $after) : [];

            if ($contentChanged) {
                $kit->version = $from + 1;
            }

            $kit->save();

            if ($contentChanged) {
                $this->brand->notifyKitChanged($kit, $from, $summary);
                $this->activity->record('brand.kit.changed', $user, $kit->team_id, null, 'brand_kit', $kit->id, ['version' => $kit->version]);
            }

            return ['kit' => $kit->fresh(['assets.asset']), 'changed' => $contentChanged, 'summary' => $summary];
        });
    }

    /** The parts of a kit that count as "what the brand says". */
    private function content(BrandKit $kit): array
    {
        return $kit->only(BrandKit::CONTENT_FIELDS);
    }

    public function delete(BrandKit $kit, User $user): void
    {
        DB::transaction(function () use ($kit, $user): void {
            // Designs keep their colours; they just stop being linked to a kit that no longer exists.
            DB::table('design_projects')->where('brand_kit_id', $kit->id)->update(['brand_kit_id' => null]);

            $teamId = $kit->team_id;
            $wasDefault = $kit->is_default;
            $kit->delete();

            if ($wasDefault) {
                $next = BrandKit::query()->when($teamId, fn ($q) => $q->where('team_id', $teamId), fn ($q) => $q->whereNull('team_id')->where('user_id', $user->id))->orderBy('id')->first();
                $next?->forceFill(['is_default' => true])->save();
            }

            $this->activity->record('brand.kit.deleted', $user, $teamId, null, 'brand_kit', $kit->id);
        });
    }

    public function makeDefault(BrandKit $kit): BrandKit
    {
        return DB::transaction(function () use ($kit): BrandKit {
            BrandKit::query()
                ->when($kit->team_id, fn ($q) => $q->where('team_id', $kit->team_id), fn ($q) => $q->whereNull('team_id')->where('user_id', $kit->user_id))
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $kit->forceFill(['is_default' => true])->save();

            return $kit;
        });
    }

    public function defaultFor(User $user, ?int $teamId = null): ?BrandKit
    {
        $teamId ??= $user->current_team_id;

        return BrandKit::query()->where('is_default', true)
            ->where(fn ($q) => $q->when($teamId, fn ($q2) => $q2->where('team_id', $teamId))->orWhere(fn ($q2) => $q2->whereNull('team_id')->where('user_id', $user->id)))
            ->first();
    }

    /**
     * File a design asset in the kit under a role. The asset must be one the person can use. Single-slot roles
     * (logo, logo-light, mark) replace what was there. Changing a logo counts as a kit change.
     */
    public function attachAsset(BrandKit $kit, User $user, int $assetId, string $role, ?string $label = null): BrandAsset
    {
        if (! in_array($role, BrandAsset::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Pick where this picture is used: logo, logo for dark backgrounds, icon, picture, pattern or background.']);
        }

        if (! $this->assets->canUse($user, $assetId)) {
            throw ValidationException::withMessages(['design_asset_id' => 'We could not find that picture in your uploads.']);
        }

        $label = $label === null ? null : $this->cleanLabel($label, 'label');

        return DB::transaction(function () use ($kit, $user, $assetId, $role, $label): BrandAsset {
            if (in_array($role, BrandAsset::SINGLE_ROLES, true)) {
                BrandAsset::query()->where('brand_kit_id', $kit->id)->where('role', $role)->where('design_asset_id', '!=', $assetId)->delete();
            }

            $asset = BrandAsset::query()->updateOrCreate(
                ['brand_kit_id' => $kit->id, 'design_asset_id' => $assetId, 'role' => $role],
                ['label' => $label],
            );

            $this->touchVersion($kit, $user, 'a picture');

            return $asset;
        });
    }

    public function detachAsset(BrandKit $kit, User $user, BrandAsset $asset): void
    {
        DB::transaction(function () use ($kit, $user, $asset): void {
            $asset->delete();
            $this->touchVersion($kit, $user, 'a picture');
        });
    }

    /** A logo change is a kit change: bump the version, and tell projects (the summary names the picture roles only). */
    private function touchVersion(BrandKit $kit, User $user, string $what): void
    {
        $from = $kit->version;
        $kit->forceFill(['version' => $from + 1])->save();
        $this->brand->notifyKitChanged($kit, $from, ['other' => [$what]]);
    }

    /** @return int|null the workspace (team) id the kit belongs to */
    private function workspaceFor(User $user, array $input): ?int
    {
        if (($input['workspace'] ?? null) === 'personal') {
            return null;
        }

        $teamId = isset($input['team_id']) ? (int) $input['team_id'] : $user->current_team_id;

        if ($teamId === null || $teamId === 0) {
            return null;
        }

        $team = $user->allTeams()->firstWhere('id', $teamId);

        if ($team === null) {
            throw ValidationException::withMessages(['team_id' => 'You are not a member of that workspace.']);
        }

        if (! $user->hasTeamPermission($team, 'create')) {
            throw new AuthorizationException('Your role in that workspace cannot create brand kits.');
        }

        return $team->id;
    }

    /**
     * Validate and clean kit input. With $creating, missing parts get sensible defaults;
     * otherwise only the keys present are returned.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalise(array $input, bool $creating): array
    {
        $errors = [];
        $out = [];

        if ($creating || array_key_exists('name', $input)) {
            $name = $this->text->text($input['name'] ?? null, 80, 'name', $errors, false, 'The kit name');
            if ($name === null && ! isset($errors['name'])) {
                $errors['name'] = 'Give your brand kit a name, for example the name of your business.';
            }
            $out['name'] = $name;
        }

        if (array_key_exists('colors', $input)) {
            $out['colors'] = $this->colors($input['colors'], $errors);
        } elseif ($creating) {
            $out['colors'] = [];
        }

        if (array_key_exists('fonts', $input)) {
            $out['fonts'] = $this->fonts($input['fonts'], $errors);
        } elseif ($creating) {
            $out['fonts'] = ['heading' => 'inter', 'body' => 'inter', 'accent' => 'inter'];
        }

        if (array_key_exists('type_styles', $input)) {
            $out['type_styles'] = $this->typeStyles($input['type_styles'], $errors);
        } elseif ($creating) {
            $out['type_styles'] = $this->defaultTypeStyles();
        }

        foreach (['guidelines' => [4000, true, 'The brand guidelines'], 'tagline' => [160, false, 'The tagline']] as $key => [$max, $multiline, $label]) {
            if ($creating || array_key_exists($key, $input)) {
                $out[$key] = $this->text->text($input[$key] ?? null, $max, $key, $errors, $multiline, $label);
            }
        }

        if ($creating || array_key_exists('website', $input)) {
            $site = $this->text->text($input['website'] ?? null, 200, 'website', $errors, false, 'The website');
            $out['website'] = $site === null ? null : $this->text->url($site, 'website', $errors);
        }

        if (array_key_exists('voice', $input)) {
            $out['voice'] = $this->voice($input['voice'], $errors);
        } elseif ($creating) {
            $out['voice'] = ['tone' => null, 'keywords' => [], 'avoid' => []];
        }

        if (array_key_exists('contacts', $input)) {
            $out['contacts'] = $this->contacts($input['contacts'], $errors);
        } elseif ($creating) {
            $out['contacts'] = [];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** @param array<string, string> $errors */
    private function colors(mixed $value, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 16) {
            $errors['colors'] = 'Add up to 16 colours.';

            return [];
        }

        $out = [];
        $keys = [];
        $singles = [];

        foreach ($value as $i => $color) {
            $hex = is_array($color) ? ($color['hex'] ?? null) : null;
            $role = is_array($color) ? ($color['role'] ?? 'extra') : 'extra';

            if (! is_string($hex) || ! preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex)) {
                $errors["colors.$i.hex"] = 'Use a colour code such as #1a2b3c.';

                continue;
            }

            if (! is_string($role) || ! in_array($role, self::COLOR_ROLES, true)) {
                $errors["colors.$i.role"] = 'Pick main, second, highlight, neutral or extra.';

                continue;
            }

            $name = $this->text->text($color['name'] ?? null, 40, "colors.$i.name", $errors, false, 'The colour name');
            $name ??= (string) config("design_copy.color_roles.$role", ucfirst($role));

            if ($role !== 'extra') {
                if (isset($singles[$role])) {
                    $errors["colors.$i.role"] = 'You can only have one '.mb_strtolower((string) config("design_copy.color_roles.$role")).'. Mark the others as extra.';

                    continue;
                }
                $singles[$role] = true;
            }

            $key = $role !== 'extra' ? $role : (Str::slug($name) ?: 'color');
            $key = substr(preg_replace('/[^a-z0-9-]/', '', strtolower($key)) ?: 'color', 0, 30);
            $base = $key;
            for ($n = 2; isset($keys[$key]); $n++) {
                $key = substr($base, 0, 27).'-'.$n;
            }
            $keys[$key] = true;

            $out[] = ['key' => $key, 'name' => $name, 'hex' => BrandTokens::normaliseHex($hex), 'role' => $role];
        }

        return $out;
    }

    /** @param array<string, string> $errors */
    private function fonts(mixed $value, array &$errors): array
    {
        $allowed = $this->fontKeys();
        $out = [];

        if (! is_array($value)) {
            $errors['fonts'] = 'Pick a heading, body and accent font.';

            return ['heading' => 'inter', 'body' => 'inter', 'accent' => 'inter'];
        }

        foreach (self::FONT_SLOTS as $slot) {
            $key = $value[$slot] ?? 'inter';
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $errors["fonts.$slot"] = 'That font is not available. Pick one from the list.';
                continue;
            }
            $out[$slot] = $key;
        }

        return $out;
    }

    /** @return list<string> */
    public function fontKeys(): array
    {
        $keys = array_keys((array) config('design_fonts.fonts', []));

        return $keys !== [] ? array_map('strval', $keys) : (array) config('design_copy.font_keys');
    }

    /** @param array<string, string> $errors */
    private function typeStyles(mixed $value, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 12) {
            $errors['type_styles'] = 'Add up to 12 text styles.';

            return [];
        }

        $out = [];
        $keys = [];

        foreach ($value as $i => $style) {
            if (! is_array($style)) {
                $errors["type_styles.$i"] = 'Each text style needs a name, a font and a size.';

                continue;
            }

            $label = $this->text->text($style['label'] ?? null, 40, "type_styles.$i.label", $errors, false, 'The style name');
            $slot = $style['font'] ?? 'body';
            $size = $style['size'] ?? null;
            $weight = $style['weight'] ?? 400;
            $line = $style['line_height'] ?? 1.2;
            $spacing = $style['letter_spacing'] ?? 0;
            $case = $style['case'] ?? 'none';

            if ($label === null) {
                $errors["type_styles.$i.label"] ??= 'Give this text style a name.';
            }
            if (! in_array($slot, self::FONT_SLOTS, true)) {
                $errors["type_styles.$i.font"] = 'Pick heading, body or accent.';
            }
            if (! is_numeric($size) || $size < 8 || $size > 400) {
                $errors["type_styles.$i.size"] = 'Use a size from 8 to 400.';
            }
            if (! is_int($weight) || $weight < 100 || $weight > 900) {
                $errors["type_styles.$i.weight"] = 'Use a weight from 100 to 900.';
            }
            if (! is_numeric($line) || $line < 0.6 || $line > 3) {
                $errors["type_styles.$i.line_height"] = 'Use a line height from 0.6 to 3.';
            }
            if (! is_numeric($spacing) || $spacing < -0.2 || $spacing > 1) {
                $errors["type_styles.$i.letter_spacing"] = 'Use letter spacing from -0.2 to 1.';
            }
            if (! in_array($case, ['none', 'upper', 'lower', 'title'], true)) {
                $errors["type_styles.$i.case"] = 'Pick none, upper, lower or title.';
            }

            if ($label === null || isset($errors["type_styles.$i.font"]) || isset($errors["type_styles.$i.size"]) || isset($errors["type_styles.$i.weight"]) || isset($errors["type_styles.$i.line_height"]) || isset($errors["type_styles.$i.letter_spacing"]) || isset($errors["type_styles.$i.case"])) {
                continue;
            }

            $key = substr(Str::slug($label) ?: 'style', 0, 30);
            $base = $key;
            for ($n = 2; isset($keys[$key]); $n++) {
                $key = substr($base, 0, 27).'-'.$n;
            }
            $keys[$key] = true;

            $out[] = ['key' => $key, 'label' => $label, 'font' => $slot, 'size' => (float) $size + 0, 'weight' => $weight, 'line_height' => (float) $line + 0, 'letter_spacing' => (float) $spacing + 0, 'case' => $case];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function defaultTypeStyles(): array
    {
        return [
            ['key' => 'display', 'label' => 'Display', 'font' => 'heading', 'size' => 72, 'weight' => 700, 'line_height' => 1.05, 'letter_spacing' => -0.02, 'case' => 'none'],
            ['key' => 'heading', 'label' => 'Heading', 'font' => 'heading', 'size' => 40, 'weight' => 700, 'line_height' => 1.15, 'letter_spacing' => 0, 'case' => 'none'],
            ['key' => 'body', 'label' => 'Body text', 'font' => 'body', 'size' => 18, 'weight' => 400, 'line_height' => 1.45, 'letter_spacing' => 0, 'case' => 'none'],
            ['key' => 'caption', 'label' => 'Small print', 'font' => 'body', 'size' => 12, 'weight' => 400, 'line_height' => 1.35, 'letter_spacing' => 0.02, 'case' => 'none'],
        ];
    }

    /** @param array<string, string> $errors */
    private function voice(mixed $value, array &$errors): array
    {
        if (! is_array($value)) {
            $errors['voice'] = 'Describe your tone of voice with a short description and a few words.';

            return ['tone' => null, 'keywords' => [], 'avoid' => []];
        }

        return [
            'tone' => $this->text->text($value['tone'] ?? null, 160, 'voice.tone', $errors, false, 'The tone of voice'),
            'keywords' => $this->wordList($value['keywords'] ?? [], 'voice.keywords', $errors),
            'avoid' => $this->wordList($value['avoid'] ?? [], 'voice.avoid', $errors),
        ];
    }

    /** @param array<string, string> $errors @return list<string> */
    private function wordList(mixed $value, string $field, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 12) {
            $errors[$field] = 'Add up to 12 words or short phrases.';

            return [];
        }

        $out = [];
        foreach ($value as $i => $word) {
            $clean = $this->text->text($word, 30, "$field.$i", $errors, false, 'Each word or phrase');
            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, string> $errors */
    private function contacts(mixed $value, array &$errors): array
    {
        if (! is_array($value)) {
            $errors['contacts'] = 'Contact details need to be a list of fields.';

            return [];
        }

        $fields = (array) config('design_brief_flow.fields');
        $out = [];

        foreach (self::CONTACT_KEYS as $key) {
            if (! isset($value[$key])) {
                continue;
            }
            $clean = $this->text->checkDetail($key, $fields[$key], $value[$key], $errors);
            if ($clean !== null) {
                $out[$key] = $clean;
            }
        }

        // checkDetail files its errors under details.<key>; re-key them to contacts.<key>.
        foreach ($errors as $field => $message) {
            if (str_starts_with($field, 'details.')) {
                $errors['contacts.'.substr($field, 8)] = $message;
                unset($errors[$field]);
            }
        }

        return $out;
    }

    private function cleanLabel(string $label, string $field): string
    {
        $errors = [];
        $clean = $this->text->text($label, 80, $field, $errors, false, 'The label');

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean ?? '';
    }

    /**
     * What changed between two content snapshots, in the shape the notification list shows.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{colors: list<array<string, mixed>>, fonts: list<array<string, mixed>>, other: list<string>}
     */
    public function summarise(array $before, array $after): array
    {
        $oldColors = collect((array) ($before['colors'] ?? []))->keyBy('key');
        $newColors = collect((array) ($after['colors'] ?? []))->keyBy('key');
        $colors = [];

        foreach ($newColors as $key => $color) {
            $old = $oldColors->get($key);
            if ($old === null) {
                $colors[] = ['key' => $key, 'name' => $color['name'], 'from' => null, 'to' => $color['hex']];
            } elseif (! BrandTokens::same('color', $old['hex'], $color['hex'])) {
                $colors[] = ['key' => $key, 'name' => $color['name'], 'from' => $old['hex'], 'to' => $color['hex']];
            }
        }
        foreach ($oldColors as $key => $color) {
            if (! $newColors->has($key)) {
                $colors[] = ['key' => $key, 'name' => $color['name'], 'from' => $color['hex'], 'to' => null];
            }
        }

        $fonts = [];
        foreach (self::FONT_SLOTS as $slot) {
            $from = ((array) ($before['fonts'] ?? []))[$slot] ?? null;
            $to = ((array) ($after['fonts'] ?? []))[$slot] ?? null;
            if ($from !== $to) {
                $fonts[] = ['slot' => $slot, 'from' => $from, 'to' => $to];
            }
        }

        $other = [];
        foreach (['type_styles' => 'text styles', 'guidelines' => 'guidelines', 'voice' => 'tone of voice', 'tagline' => 'tagline', 'website' => 'website', 'contacts' => 'contact details'] as $field => $label) {
            if (json_encode($before[$field] ?? null) !== json_encode($after[$field] ?? null)) {
                $other[] = $label;
            }
        }

        return ['colors' => $colors, 'fonts' => $fonts, 'other' => $other];
    }

    /**
     * The shape the Brand Studio UI renders from.
     *
     * @return array<string, mixed>
     */
    public function present(BrandKit $kit, ?User $user = null): array
    {
        $kit->loadMissing('assets.asset');

        $using = (int) DB::table('design_projects')->where('brand_kit_id', $kit->id)->count();

        return [
            'id' => $kit->id,
            'name' => $kit->name,
            'team_id' => $kit->team_id,
            'is_default' => $kit->is_default,
            'version' => $kit->version,
            'colors' => (array) $kit->colors,
            'fonts' => (array) $kit->fonts,
            'type_styles' => (array) $kit->type_styles,
            'guidelines' => $kit->guidelines,
            'voice' => $kit->voice,
            'tagline' => $kit->tagline,
            'website' => $kit->website,
            'contacts' => (object) ((array) $kit->contacts),
            'assets' => $kit->assets->map(fn (BrandAsset $a) => [
                'id' => $a->id,
                'role' => $a->role,
                'role_label' => config("design_copy.brand_roles.{$a->role}", $a->role),
                'label' => $a->label,
                'asset' => $a->asset?->toEditorArray(),
            ])->values()->all(),
            'projects_using' => $using,
            'can' => $user === null ? null : [
                'update' => $user->can('update', $kit),
                'delete' => $user->can('delete', $kit),
            ],
            'updated_at' => $kit->updated_at?->toIso8601String(),
        ];
    }
}
