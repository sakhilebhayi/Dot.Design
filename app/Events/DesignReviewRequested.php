<?php

namespace App\Events;

use App\Models\DesignReview;
use Illuminate\Foundation\Events\Dispatchable;

class DesignReviewRequested
{
    use Dispatchable;

    public function __construct(public readonly DesignReview $review) {}
}
