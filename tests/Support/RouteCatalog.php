<?php

namespace Tests\Support;

use App\Http\Middleware\EnsurePlatformOperator;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Reads the live router and sorts every route into a security class. A route that fits no class fails the matrix test,
 * so a new endpoint cannot ship without somebody deciding what protects it.
 *
 *  public  : reachable without signing in on purpose. Each entry says why.
 *  vendor  : Fortify, Jetstream, Livewire, Sanctum and framework routes (their own tests and middleware).
 *  app     : everything else; must sit behind auth:sanctum.
 */
final class RouteCatalog
{
    /** @var array<string, string> route name or uri => why it is public */
    public const PUBLIC = [
        'GET /' => 'landing page',
        'cookies' => 'cookie policy page',
        'csp.report' => 'browsers post CSP reports without credentials; throttled, session-less, stores only directive and host',
        'ecosystem.auth' => 'InfoDot single sign-on hand-over (signed token in a POST body, own throttle)',
        'GET auth/ecosystem' => 'legacy link: redirects to the sign-in page and never reads the token',
        'design.integrations.membership' => 'ecosystem webhook: authenticated by HMAC signature and replay window, not a session',
        'studio.share.show' => 'share link viewer: the 40 character link is the credential',
        'studio.share.asset' => 'share link picture: signed URL plus a live link',
        'studio.share.comment' => 'share link guest comment: link, honeypot and throttle',
        'register' => 'closed: redirects to login',
    ];

    /** Route names and URIs owned by vendor packages or the framework, with their own authentication. */
    public const VENDOR_PREFIXES = ['livewire/', 'two-factor', 'user/', 'forgot-password', 'reset-password', 'login', 'logout', 'sanctum/', 'storage/', '_boost/', 'up', 'privacy-policy', 'terms-of-service', 'current-team', 'team-invitations/', 'teams/'];

    /** Routes that only a platform operator may call. */
    public const OPERATOR = [
        'design-system.components.store', 'design-system.components.update', 'design-system.components.destroy',
        'design-system.token-sets.store', 'design-system.token-sets.update', 'design-system.token-sets.destroy',
        'design-system.token-sets.consumers.store', 'design-system.token-sets.consumers.update', 'design-system.token-sets.consumers.destroy',
        'design-system.token-sets.tokens.store', 'design-system.token-sets.tokens.update', 'design-system.token-sets.tokens.destroy',
        'design.templates.publish', 'design.templates.retire',
    ];

    /** @return list<LaravelRoute> */
    public static function all(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $r) => ! str_starts_with($r->uri(), '_ignition'),
        ));
    }

    public static function key(LaravelRoute $route): string
    {
        $method = collect($route->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))->first();

        return $route->getName() ?? $method.' '.$route->uri();
    }

    public static function method(LaravelRoute $route): string
    {
        return strtolower((string) collect($route->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))->first());
    }

    public static function isPublic(LaravelRoute $route): bool
    {
        $name = $route->getName();
        $byUri = self::method($route).' '.$route->uri();

        return ($name !== null && isset(self::PUBLIC[$name])) || isset(self::PUBLIC[strtoupper(self::method($route)).' '.$route->uri()]) || isset(self::PUBLIC[$byUri]);
    }

    public static function isVendor(LaravelRoute $route): bool
    {
        $action = $route->getActionName();

        if ($action !== 'Closure' && ! str_starts_with($action, 'App\\')) {
            return true;
        }

        foreach (self::VENDOR_PREFIXES as $prefix) {
            if (str_starts_with($route->uri(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function hasAuth(LaravelRoute $route): bool
    {
        return in_array('auth:sanctum', $route->gatherMiddleware(), true);
    }

    public static function hasOperator(LaravelRoute $route): bool
    {
        return in_array('operator', $route->gatherMiddleware(), true)
            || in_array(EnsurePlatformOperator::class, $route->gatherMiddleware(), true);
    }

    /** @return list<LaravelRoute> the application's own signed-in routes */
    public static function app(): array
    {
        return array_values(array_filter(self::all(), fn (LaravelRoute $r) => ! self::isPublic($r) && ! self::isVendor($r)));
    }

    /**
     * Fill the route's parameters from a tenant, optionally overriding some by name (to build a cross-tenant pairing).
     *
     * @param  array<string, mixed>  $tenant
     * @param  array<string, mixed>  $override  param name => id
     */
    public static function url(LaravelRoute $route, array $tenant, array $override = []): string
    {
        $values = [];

        foreach ($route->parameterNames() as $name) {
            $values[$name] = $override[$name] ?? self::value($name, $tenant);
        }

        $uri = $route->uri();

        foreach ($values as $name => $value) {
            $uri = preg_replace('/\{'.preg_quote($name, '/').'\??\}/', (string) $value, $uri);
        }

        return '/'.ltrim($uri, '/');
    }

    /** @param array<string, mixed> $t */
    public static function value(string $param, array $t): mixed
    {
        return match ($param) {
            'project' => $t['project']->id,
            'canvas', 'page' => $t['page']->id,
            'asset' => $t['asset']->id,
            'comment' => $t['comment']->id,
            'review' => $t['review']->id,
            'share' => $t['share']->id,
            'version' => $t['version']->id,
            'grant' => $t['grant']->id,
            'kit' => $t['kit']->id,
            'brandAsset' => $t['brandAsset']->id,
            'template' => $t['template']->id,
            'proposal' => $t['proposal']->id,
            'tokenSet' => $t['ds']['set']->id,
            'token' => $t['ds']['token']->id,
            'component' => $t['ds']['component']->id,
            'record' => $t['ds']['record']->id,
            default => 1,
        };
    }

    /** Parameters that name a tenant-owned row (everything but the global design-system catalogue). */
    public const TENANT_PARAMS = ['project', 'canvas', 'page', 'asset', 'comment', 'review', 'share', 'version', 'grant', 'kit', 'brandAsset', 'template', 'proposal'];

    /** @return list<string> */
    public static function tenantParams(LaravelRoute $route): array
    {
        return array_values(array_intersect($route->parameterNames(), self::TENANT_PARAMS));
    }
}
