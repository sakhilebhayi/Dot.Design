<?php

namespace App\Events;

use App\Models\DesignReview;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A reviewer approved or asked for changes; `$review->status` says which.
 */
class DesignReviewDecided
{
    use Dispatchable;

    public function __construct(public readonly DesignReview $review) {}
}
