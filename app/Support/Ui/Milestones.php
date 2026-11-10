<?php

namespace App\Support\Ui;

use App\Models\DesignExport;
use App\Models\DesignMilestone;
use App\Models\DesignProject;
use App\Models\DesignReview;
use App\Models\User;
use App\Support\Design\ReviewStatus;
use Illuminate\Support\Facades\DB;

/**
 * First-time moments worth a short, quiet "well done": the first design, the first file downloaded, the first approval. Each is read
 * from what the person really did (a design they made, an export they ran, a review that approved their work); the only thing stored is
 * that the message was shown, so it appears once. Nothing here is a score, a streak or a prompt to do more.
 */
class Milestones
{
    /** In the order a person meets them. */
    public const KEYS = ['first_design', 'first_export', 'first_approval'];

    private const COPY = [
        'first_design' => ['title' => 'Your first design is on the table.', 'text' => 'It saves as you work, so you can leave and come back to it any time.'],
        'first_export' => ['title' => 'Your first file is ready.', 'text' => 'You made something you can print or post. The design stays editable: change it and download again whenever you like.'],
        'first_approval' => ['title' => 'Your first approval.', 'text' => 'Someone signed off on a design of yours. The approved version is kept in its history.'],
    ];

    /** @return array{key: string, title: string, text: string}|null the first milestone reached and not yet shown, or null */
    public function pending(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $seen = DesignMilestone::query()->where('user_id', $user->id)->pluck('key')->all();

        foreach (self::KEYS as $key) {
            if (! in_array($key, $seen, true) && $this->reached($user, $key)) {
                return ['key' => $key] + self::COPY[$key];
            }
        }

        return null;
    }

    /** Remember that the message was shown. Only a known key, and only once it was really reached, is stored. */
    public function acknowledge(User $user, string $key): bool
    {
        if (! in_array($key, self::KEYS, true) || ! $this->reached($user, $key)) {
            return false;
        }

        DB::table('design_milestones')->insertOrIgnore(['user_id' => $user->id, 'key' => $key, 'seen_at' => now()]);

        return true;
    }

    public function reached(User $user, string $key): bool
    {
        return match ($key) {
            'first_design' => DesignProject::withoutGlobalScopes()->where('user_id', $user->id)->exists(),
            'first_export' => DesignExport::query()->where('user_id', $user->id)->exists(),
            'first_approval' => DesignReview::query()->where('requested_by', $user->id)->where('status', ReviewStatus::Approved->value)->exists(),
            default => false,
        };
    }
}
