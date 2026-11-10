<?php

namespace App\Providers;

use App\Events\DesignCommentAdded;
use App\Events\DesignReviewDecided;
use App\Events\DesignReviewRequested;
use App\Events\DesignShared;
use App\Listeners\Integrations\SendDesignNotifications;
use App\Services\Integrations\Analytics\AnalyticsStatus;
use App\Services\Integrations\Contracts\FileVault;
use App\Services\Integrations\Contracts\NotificationGateway;
use App\Services\Integrations\Files\FilesVault;
use App\Services\Integrations\InfoDot\InfoDotStatus;
use App\Services\Integrations\IntegrationStatus;
use App\Services\Integrations\Notify\NotifyGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * R-45 integrations: binds each port to its adapter, wires the design events to the queued Dot.Notify listener, and loads the
 * signed server-to-server routes. Every adapter turns itself off unless its flag AND its url/secret are set, so with nothing
 * configured the application behaves exactly as if this provider were absent.
 */
class StudioIntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationGateway::class, NotifyGateway::class);
        $this->app->bind(FileVault::class, FilesVault::class);

        $this->app->singleton(IntegrationStatus::class, fn ($app) => new IntegrationStatus([
            $app->make(InfoDotStatus::class),
            $app->make(NotificationGateway::class),
            $app->make(FileVault::class),
            $app->make(AnalyticsStatus::class),
        ]));
    }

    public function boot(): void
    {
        Event::listen(DesignCommentAdded::class, [SendDesignNotifications::class, 'commentAdded']);
        Event::listen(DesignReviewRequested::class, [SendDesignNotifications::class, 'reviewRequested']);
        Event::listen(DesignReviewDecided::class, [SendDesignNotifications::class, 'reviewDecided']);
        Event::listen(DesignShared::class, [SendDesignNotifications::class, 'shared']);

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/studio/integrations_api.php'));
        }
    }
}
