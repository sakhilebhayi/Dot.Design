<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * AuthorizesRequests wasn't previously included here -- no controller in
 * this repo called $this->authorize() before the canvas-editor controllers
 * needed it for DesignProject/DesignCanvas/DesignAsset ownership checks
 * (see their Policies). Adding it here matches Laravel's own default
 * starter-kit Controller base class.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
