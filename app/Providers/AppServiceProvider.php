<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // Per user (falling back to IP), answered as JSON because the only
        // caller is the editor's fetch() upload.
        RateLimiter::for('design-uploads', fn (Request $request) => Limit::perMinute((int) config('design.assets.uploads_per_minute'))
            ->by($request->user()?->id ?: $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => 'Too many uploads. Wait a minute and try again.',
            ], 429, $headers)));
    }
}
