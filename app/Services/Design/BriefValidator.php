<?php

namespace App\Services\Design;

use App\Models\BrandKit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Cleans and checks a brief. The brief is typed by a person and ends up printed on their design,
 * so every string is bounded, stripped of control and direction-override characters, and refused
 * when it contains HTML-looking markup. Nothing here uses AI and nothing is "fixed" silently:
 * a value that is not acceptable gets a plain-language message the person can act on.
 */
class BriefValidator
{
    public function __construct(private readonly BriefFlow $flow, private readonly Formats $formats, private readonly AssetAccess $assets) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  bool  $requireEssentials  true when creating a project (goal and business name are needed); false for previews
     */
    public function validate(array $input, ?User $user = null, bool $requireEssentials = true): BriefValidation
    {
        $errors = [];
        $ignored = [];
        $brief = [];

        $allowed = ['goal', 'what', 'business_name', 'details', 'logo_asset_id', 'photos', 'style', 'format_key', 'industry', 'brand_kit_id', 'apply_brand', 'skipped'];
        foreach (array_diff(array_keys($input), $allowed) as $unknown) {
            $ignored[] = (string) $unknown;
        }

        // Goal
        $goal = $input['goal'] ?? null;
        if (is_string($goal) && $this->flow->hasGoal($goal)) {
            $brief['goal'] = $goal;
        } elseif ($goal === null || $goal === '') {
            $errors['goal'] = 'Pick what you would like to make.';
        } else {
            $errors['goal'] = 'That is not one of the choices. Pick what you would like to make from the list.';
        }

        // Free text answers
        foreach ((array) config('design_brief_flow.text_answers') as $key => $spec) {
            $value = $this->text($input[$key] ?? null, $spec['max'], $key, $errors, false);
            if ($value !== null) {
                $brief[$key] = $value;
            }
        }

        if ($requireEssentials && ! isset($brief['business_name']) && ! isset($errors['business_name'])) {
            $errors['business_name'] = 'Add your business name. We put it on your design for you.';
        }

        // Details
        $details = [];
        $given = $input['details'] ?? [];
        if (! is_array($given)) {
            $errors['details'] = 'The details need to be a list of fields.';
            $given = [];
        }

        $fields = (array) config('design_brief_flow.fields');
        foreach ($given as $key => $value) {
            if (! is_string($key) || ! isset($fields[$key])) {
                $ignored[] = 'details.'.(is_string($key) ? $key : '?');

                continue;
            }

            $clean = $this->checkDetail($key, $fields[$key], $value, $errors);
            if ($clean !== null) {
                $details[$key] = $clean;
            }
        }
        $brief['details'] = $details;

        // Style
        if (isset($input['style']) && $input['style'] !== '') {
            if (is_string($input['style']) && config("design_brief_flow.styles.{$input['style']}") !== null) {
                $brief['style'] = $input['style'];
            } else {
                $errors['style'] = 'Pick one of the looks shown.';
            }
        }

        // Where it will be used
        if (isset($input['format_key']) && $input['format_key'] !== '') {
            if (is_string($input['format_key']) && $this->formats->has($input['format_key'])) {
                $brief['format_key'] = $input['format_key'];
            } else {
                $errors['format_key'] = 'That size is not available. Pick one from the list.';
            }
        }

        // Industry (optional, otherwise guessed by the recommender)
        if (isset($input['industry']) && $input['industry'] !== '') {
            if (is_string($input['industry']) && config("design_brief_flow.industries.{$input['industry']}") !== null) {
                $brief['industry'] = $input['industry'];
            } else {
                $errors['industry'] = 'Pick one of the kinds of business shown.';
            }
        }

        // Pictures: ids only, and only ones this person may use.
        $assetIds = [];
        if (isset($input['logo_asset_id']) && $input['logo_asset_id'] !== '') {
            $logo = $this->wholeNumber($input['logo_asset_id']);
            if ($logo === null) {
                $errors['logo_asset_id'] = 'Pick a logo from your uploads, or skip this step.';
            } else {
                $assetIds['logo_asset_id'] = $logo;
            }
        }

        if (isset($input['photos'])) {
            $photos = [];
            $max = (int) config('design_brief_flow.limits.max_photos', 3);

            if (! is_array($input['photos']) || ! array_is_list($input['photos']) || count($input['photos']) > $max) {
                $errors['photos'] = "You can add up to $max pictures.";
            } else {
                foreach ($input['photos'] as $i => $photo) {
                    $id = $this->wholeNumber($photo);
                    if ($id === null) {
                        $errors["photos.$i"] = 'Pick a picture from your uploads.';
                    } else {
                        $photos[] = $id;
                        $assetIds["photos.$i"] = $id;
                    }
                }
                $brief['photos'] = array_values(array_unique($photos));
            }
        }

        foreach ($this->unusableAssets($assetIds, $user) as $field) {
            $errors[$field] = 'We could not find that picture in your uploads. Pick another or skip it.';
        }
        if (isset($assetIds['logo_asset_id']) && ! isset($errors['logo_asset_id'])) {
            $brief['logo_asset_id'] = $assetIds['logo_asset_id'];
        }

        // Brand kit
        if (isset($input['brand_kit_id']) && $input['brand_kit_id'] !== '') {
            $kitId = $this->wholeNumber($input['brand_kit_id']);
            if ($kitId === null || ($user !== null && ! $this->kitVisible($kitId, $user))) {
                $errors['brand_kit_id'] = 'We could not find that brand kit.';
            } else {
                $brief['brand_kit_id'] = $kitId;
            }
        }

        $brief['apply_brand'] = filter_var($input['apply_brand'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Steps the person skipped, kept so the UI can offer them again later.
        $validSteps = array_column($this->flow->steps(), 'key');
        $brief['skipped'] = array_values(array_intersect(is_array($input['skipped'] ?? null) ? array_map('strval', $input['skipped']) : [], $validSteps));

        return new BriefValidation($brief, $errors, $ignored);
    }

    /** @param array<string, string> $errors */
    public function checkDetail(string $key, array $spec, mixed $value, array &$errors): ?string
    {
        $field = "details.$key";
        $text = $this->text($value, $spec['max'], $field, $errors, $spec['type'] === 'multiline', $spec['label']);

        if ($text === null) {
            return null;
        }

        return match ($spec['type']) {
            'phone' => $this->phone($text, $field, $spec['label'], $errors),
            'email' => $this->email($text, $field, $errors),
            'url' => $this->url($text, $field, $errors),
            'handle' => $this->handle($text, $field, $errors),
            default => $text,
        };
    }

    /**
     * Normalise and bound a string. Returns null for empty or invalid input (and records the error when invalid).
     *
     * @param  array<string, string>  $errors
     */
    public function text(mixed $value, int $max, string $field, array &$errors, bool $multiline, ?string $label = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $label ??= 'This answer';

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            $errors[$field] = "$label needs to be text.";

            return null;
        }

        $clean = $this->clean($value, $multiline);

        if ($clean === '') {
            return null;
        }

        if ($this->looksLikeMarkup($clean)) {
            $errors[$field] = "$label has characters that look like code. Use plain words, without angle brackets.";

            return null;
        }

        if (mb_strlen($clean) > $max) {
            $errors[$field] = "$label is too long. Keep it under $max characters.";

            return null;
        }

        return $clean;
    }

    public function clean(string $value, bool $multiline = false): string
    {
        if (function_exists('normalizer_normalize')) {
            $normalised = normalizer_normalize($value, \Normalizer::FORM_C);
            $value = $normalised === false ? $value : $normalised;
        }

        $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{00AD}]/u', '', $value);
        $value = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $value);

        if ($multiline) {
            $value = (string) preg_replace('/[ \t]+/u', ' ', $value);
            $value = (string) preg_replace('/\s*\n\s*/u', "\n", $value);
            $value = (string) preg_replace('/\n{3,}/', "\n\n", $value);
        } else {
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        }

        return trim($value);
    }

    private function looksLikeMarkup(string $value): bool
    {
        return (bool) preg_match('/<\s*[\/!?a-zA-Z]|&#|&lt;|&gt;|\{\{|\}\}|\$\{/', $value);
    }

    /** @param array<string, string> $errors */
    public function phone(string $value, string $field, string $label, array &$errors): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        if (! preg_match('/^\+?[0-9 ()\-.\/]{7,24}$/', $value) || strlen((string) $digits) < 7 || strlen((string) $digits) > 15) {
            $errors[$field] = "$label should only use numbers, spaces, + and dashes, for example 021 555 0123.";

            return null;
        }

        return $value;
    }

    /** @param array<string, string> $errors */
    public function email(string $value, string $field, array &$errors): ?string
    {
        if (! filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/\s/', $value)) {
            $errors[$field] = 'That email address does not look right. It should look like name@business.co.za.';

            return null;
        }

        return $value;
    }

    /** @param array<string, string> $errors */
    public function url(string $value, string $field, array &$errors): ?string
    {
        $pattern = '/^(https?:\/\/)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}(:\d{1,5})?(\/[^\s<>"\'\\\\]*)?$/i';

        if (! preg_match($pattern, $value)) {
            $errors[$field] = 'That web address does not look right. It should look like www.yourbusiness.co.za.';

            return null;
        }

        return $value;
    }

    /** @param array<string, string> $errors */
    public function handle(string $value, string $field, array &$errors): ?string
    {
        if (! preg_match('/^@?[A-Za-z0-9._]{1,30}$/', $value)) {
            $errors[$field] = 'A handle can only use letters, numbers, dots and underscores, for example @yourbusiness.';

            return null;
        }

        return '@'.ltrim($value, '@');
    }

    private function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && strlen($value) <= 12) {
            return (int) $value > 0 ? (int) $value : null;
        }

        return null;
    }

    /**
     * @param  array<string, int>  $assetIds  field => asset id
     * @return list<string> fields whose asset is not visible to the user
     */
    private function unusableAssets(array $assetIds, ?User $user): array
    {
        if ($assetIds === [] || $user === null) {
            return [];
        }

        $visible = $this->assets->visibleIds($user, array_values($assetIds));

        return array_keys(array_filter($assetIds, fn (int $id) => ! in_array($id, $visible, true)));
    }

    private function kitVisible(int $id, User $user): bool
    {
        $kit = BrandKit::query()->find($id);

        return $kit !== null && Gate::forUser($user)->allows('view', $kit);
    }
}
