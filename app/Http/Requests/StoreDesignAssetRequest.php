<?php

namespace App\Http\Requests;

use App\Models\DesignProject;
use App\Services\Assets\ImageSanitiser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The upload endpoint is only ever called with fetch(). bootstrap/app.php
 * renders JSON only for api/* paths, so a failed validation here would
 * otherwise come back as an HTML redirect; answer with the JSON the editor
 * expects instead.
 */
class StoreDesignAssetRequest extends FormRequest
{
    private ?DesignProject $project = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('design.assets.max_kilobytes'), 'mimetypes:image/jpeg,image/png,image/webp'],
            'project_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.uploaded' => 'The upload failed. The file may be larger than the server allows.',
            'file.max' => 'The file is larger than the '.(int) config('design.assets.max_kilobytes') / 1024 .' MB upload limit.',
            'file.mimetypes' => ImageSanitiser::TYPE_MESSAGE,
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $id = $this->input('project_id');

            if ($id === null || $id === '' || $validator->errors()->has('project_id')) {
                return;
            }

            // DesignProject carries the per-user global scope, so another
            // user's id resolves to nothing -- same answer as "no such project".
            $this->project = DesignProject::find((int) $id);

            if ($this->project === null) {
                $validator->errors()->add('project_id', 'That project could not be found.');
            }
        }];
    }

    public function project(): ?DesignProject
    {
        return $this->project;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
