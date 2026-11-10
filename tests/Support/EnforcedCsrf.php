<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** The framework's request-forgery middleware with its "skip while testing" shortcut removed, so a test can see what a browser would see. */
class EnforcedCsrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
