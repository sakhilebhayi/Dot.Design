<?php

namespace App\Http\Controllers\Studio;

use App\Models\DesignUserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-user studio preferences. They change which panels and controls show, never the data.
 */
class SettingsController extends StudioController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['settings' => $this->present($this->settingsFor($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['sometimes', Rule::in(DesignUserSetting::MODES)],
            'snap' => ['sometimes', 'boolean'],
            'grid' => ['sometimes', 'boolean'],
            'rulers' => ['sometimes', 'boolean'],
            'reduced_motion_pref' => ['sometimes', Rule::in(DesignUserSetting::MOTION)],
            'last_format_key' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9_.-]+$/'],
            'onboarded' => ['sometimes', 'boolean'],
        ]);

        $settings = $this->settingsFor($request);

        if (array_key_exists('onboarded', $data)) {
            $settings->onboarded_at = $data['onboarded'] ? ($settings->onboarded_at ?? now()) : null;
            unset($data['onboarded']);
        }

        $settings->fill($data)->save();

        return response()->json(['status' => 'saved', 'settings' => $this->present($settings)]);
    }

    private function settingsFor(Request $request): DesignUserSetting
    {
        return DesignUserSetting::firstOrCreate(['user_id' => $request->user()->id]);
    }

    /** @return array<string, mixed> */
    private function present(DesignUserSetting $settings): array
    {
        $settings->refresh();

        return [
            'mode' => $settings->mode,
            'snap' => (bool) $settings->snap,
            'grid' => (bool) $settings->grid,
            'rulers' => (bool) $settings->rulers,
            'reduced_motion_pref' => $settings->reduced_motion_pref,
            'last_format_key' => $settings->last_format_key,
            'onboarded' => $settings->onboarded_at !== null,
            'onboarded_at' => $settings->onboarded_at?->toIso8601String(),
        ];
    }
}
