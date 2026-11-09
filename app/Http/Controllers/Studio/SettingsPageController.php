<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\DesignUserSetting;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The studio preferences page. The values load from, and save to, the settings endpoint (SettingsController). */
class SettingsPageController extends Controller
{
    public function show(Request $request): View
    {
        $settings = DesignUserSetting::firstOrCreate(['user_id' => $request->user()->id]);

        return view('design.settings.index', [
            'settings' => [
                'mode' => $settings->mode,
                'snap' => (bool) $settings->snap,
                'grid' => (bool) $settings->grid,
                'rulers' => (bool) $settings->rulers,
                'reduced_motion_pref' => $settings->reduced_motion_pref,
            ],
        ]);
    }
}
