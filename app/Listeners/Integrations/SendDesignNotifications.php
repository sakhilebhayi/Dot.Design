<?php

namespace App\Listeners\Integrations;

use App\Events\DesignCommentAdded;
use App\Events\DesignReviewDecided;
use App\Events\DesignReviewRequested;
use App\Events\DesignShared;
use App\Services\Integrations\Contracts\NotificationGateway;
use App\Services\Integrations\Notify\DesignNotification;
use App\Services\Integrations\Notify\NotificationPlanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued listener that turns design events into Dot.Notify notifications. It can never break the action that caused the
 * event: it only runs after the surrounding transaction commits, off the request path, and every error is caught here.
 * Nothing is even queued while the integration is off. A retry re-sends the whole batch, which is safe because every
 * notification carries an idempotency key Dot.Notify de-duplicates on.
 */
class SendDesignNotifications implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public bool $afterCommit = true;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function shouldQueue(object $event): bool
    {
        return app(NotificationGateway::class)->enabled();
    }

    public function commentAdded(DesignCommentAdded $event): void
    {
        $this->run(fn (NotificationPlanner $p) => $p->commentAdded($event));
    }

    public function reviewRequested(DesignReviewRequested $event): void
    {
        $this->run(fn (NotificationPlanner $p) => $p->reviewRequested($event));
    }

    public function reviewDecided(DesignReviewDecided $event): void
    {
        $this->run(fn (NotificationPlanner $p) => $p->reviewDecided($event));
    }

    public function shared(DesignShared $event): void
    {
        $this->run(fn (NotificationPlanner $p) => $p->shared($event));
    }

    public function failed(object $event, Throwable $exception): void
    {
        Log::warning('A Dot.Notify notification could not be sent.', ['event' => class_basename($event), 'error' => class_basename($exception)]);
    }

    /** @param  \Closure(NotificationPlanner): list<DesignNotification>  $plan */
    private function run(\Closure $plan): void
    {
        try {
            $gateway = app(NotificationGateway::class);

            if (! $gateway->enabled()) {
                return;
            }

            $retry = false;

            foreach ($plan(app(NotificationPlanner::class)) as $notification) {
                if ($gateway->deliver($notification) === NotificationGateway::RETRY) {
                    $retry = true;
                }
            }

            if ($retry) {
                $this->retryLater();
            }
        } catch (Throwable $e) {
            Log::warning('Design notifications failed; the action that caused them is unaffected.', ['error' => class_basename($e)]);
        }
    }

    private function retryLater(): void
    {
        if (! $this->job || $this->attempts() >= $this->tries) {
            Log::warning('Dot.Notify could not be reached; the design notifications were given up on.');

            return;
        }

        $this->release($this->backoff()[min($this->attempts() - 1, 1)]);
    }
}
