<?php

namespace App\Http\Requests;

use App\Models\DesignAsset;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates the canvas the editor autosaves. Everything here is untrusted:
 * the elements array is bounded (count and serialized size) before any
 * per-element rule runs, numbers have bounds, colours are hex, only known keys
 * survive, and an image element's asset must be one of the saving user's own.
 *
 * bootstrap/app.php renders JSON only for api/* paths, so failures are turned
 * into JSON here, the way the editor's fetch() expects.
 */
class UpdateDesignCanvasRequest extends FormRequest
{
    private const COMMON = ['id', 'type', 'x', 'y', 'width', 'height', 'rotation', 'opacity'];

    private const PER_TYPE = [
        'text' => ['text', 'fontSize', 'color'],
        'rectangle' => ['fill'],
        'image' => ['asset_id'],
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $elements = $this->input('elements');

        if (! is_array($elements)) {
            return;
        }

        $maxElements = (int) config('design.canvas.max_elements');
        $maxBytes = (int) config('design.canvas.max_json_bytes');

        if (count($elements) > $maxElements) {
            $this->refuse("A page can hold at most {$maxElements} elements.");
        }

        if (strlen((string) json_encode($elements)) > $maxBytes) {
            $this->refuse('This page has too much content to save ('.round($maxBytes / 1024).' KB limit).');
        }
    }

    public function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];

        return [
            'elements' => ['present', 'array'],
            'elements.*' => ['array'],
            'elements.*.id' => ['nullable', 'string', 'max:64'],
            'elements.*.type' => ['required', 'string', 'in:text,rectangle,image'],
            'elements.*.x' => ['nullable', 'numeric', 'between:-100000,100000'],
            'elements.*.y' => ['nullable', 'numeric', 'between:-100000,100000'],
            'elements.*.width' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'elements.*.height' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'elements.*.rotation' => ['nullable', 'numeric', 'between:-360,360'],
            'elements.*.opacity' => ['nullable', 'numeric', 'between:0,1'],
            'elements.*.text' => ['nullable', 'string', 'max:2000'],
            'elements.*.fontSize' => ['nullable', 'numeric', 'min:1', 'max:400'],
            'elements.*.color' => $hex,
            'elements.*.fill' => $hex,
            'elements.*.asset_id' => ['nullable', 'integer'],
            'background_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'elements.*.color.regex' => 'Colours must be hex values such as #1a2b3c.',
            'elements.*.fill.regex' => 'Colours must be hex values such as #1a2b3c.',
            'background_color.regex' => 'The background must be a hex colour such as #ffffff.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $elements = (array) $this->input('elements');
            $assetIds = [];

            foreach ($elements as $i => $element) {
                if (($element['type'] ?? null) !== 'image') {
                    continue;
                }

                if (($element['asset_id'] ?? null) === null) {
                    $validator->errors()->add("elements.{$i}.asset_id", 'An image needs an asset.');
                } else {
                    $assetIds[$i] = (int) $element['asset_id'];
                }

                foreach (['width', 'height'] as $side) {
                    if ((float) ($element[$side] ?? 0) <= 0) {
                        $validator->errors()->add("elements.{$i}.{$side}", "An image needs a {$side} above zero.");
                    }
                }
            }

            if ($assetIds === []) {
                return;
            }

            // Never trust an asset id from the client: it must be a row owned
            // by the user who is saving (the model scope plus an explicit
            // user_id check, so neither alone is load-bearing).
            $owned = DesignAsset::query()
                ->where('user_id', $this->user()->id)
                ->whereIn('id', array_unique($assetIds))
                ->pluck('id')->all();

            foreach ($assetIds as $i => $id) {
                if (! in_array($id, $owned, true)) {
                    $validator->errors()->add("elements.{$i}.asset_id", 'That image is not one of your assets.');
                }
            }
        }];
    }

    /** The validated elements reduced to the keys each type is allowed to carry. */
    public function elementsForStorage(): array
    {
        return array_map(function (array $element): array {
            $allowed = array_merge(self::COMMON, self::PER_TYPE[$element['type']] ?? []);

            return array_intersect_key($element, array_flip($allowed));
        }, array_values((array) $this->validated('elements')));
    }

    private function refuse(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => ['elements' => [$message]],
        ], 422));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
