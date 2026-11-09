<?php

namespace Tests\Feature\Security;

use App\Models\DesignProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Tests\Support\BusinessFixtures;
use Tests\Support\ImageFixtures;
use Tests\Support\RouteCatalog;
use Tests\Support\SecurityWorld;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * R-45 security review, part 1: the authorisation matrix, built from the live router instead of a hand-kept list.
 *
 * Every route is read from the router and classified (public on purpose, vendor, or ours). Ours must sit behind
 * auth:sanctum, answer a guest with 401, answer a member of another business with 404 for every id it takes, refuse a
 * same-workspace reviewer on every mutating route, and stop answering the moment a member is removed. A route nobody
 * classified fails the first test, so a new endpoint cannot ship unprotected.
 */
class RouteAuthorisationMatrixTest extends TestCase
{
    use BusinessFixtures;
    use ImageFixtures;
    use RefreshDatabase;
    use SecurityWorld;
    use StudioWorld;

    /** A valid body for each mutating route, so a 403 or 404 proves authorisation and not a validation failure. */
    private function payload(string $name, array $t): array
    {
        return match ($name) {
            'design.projects.pages.update' => ['revision' => 1, 'elements' => []],
            'design.projects.canvases.update' => ['revision' => 1, 'elements' => []],
            'design.projects.pages.order' => ['order' => [$t['page']->id]],
            'design.projects.update' => ['name' => 'Renamed'],
            'design.projects.versions.store' => ['label' => 'x'],
            'design.projects.shares.store' => ['permission' => 'view'],
            'design.projects.comments.store' => ['body' => 'hi'],
            'design.projects.comments.update' => ['body' => 'edit'],
            'design.projects.reviews.store' => ['reviewer_id' => $t['admin']->id],
            'design.projects.reviews.request-changes' => ['note' => 'x'],
            'design.projects.exports.store' => ['format' => 'png'],
            'design.projects.grants.store' => ['user_id' => $t['editor']->id, 'level' => 'view'],
            'design.projects.brand.link' => ['kit_id' => $t['kit']->id],
            'design.projects.brand.apply' => ['all' => true],
            'design.projects.copies.store' => ['format_key' => 'instagram-post-square', 'pages' => [['elements' => []]]],
            'design.projects.ai-generations.store' => ['prompt' => 'a cat'],
            'design.projects.assistant.improve', 'design.assistant.improve' => ['instruction' => 'make it better', 'page_id' => $t['page']->id],
            'design.assistant.proposals.store' => ['page_id' => $t['page']->id, 'label' => 'x', 'ops' => [['op' => 'noop']]],
            'design.projects.pages.store' => [],
            'design.integrations.files.store' => ['scope' => 'project', 'format' => 'png', 'acknowledged' => true],
            'design.assets.update' => ['name' => 'renamed'],
            'design.brand.kits.update' => ['name' => 'renamed'],
            'design.brand.kits.assets.store' => ['design_asset_id' => $t['asset']->id, 'role' => 'logo'],
            'design.templates.update' => ['name' => 'renamed'],
            default => [],
        };
    }

    private function send($user, LaravelRoute $route, string $url, array $t)
    {
        $method = RouteCatalog::method($route);
        $body = $method === 'get' ? [] : $this->payload((string) $route->getName(), $t);
        $request = $user === null ? $this : $this->actingAs($user);

        return $request->json(strtoupper($method), $url, $body);
    }

    // -------------------------------------------------------------- classification

    public function test_every_route_is_classified_and_every_app_route_is_behind_authentication(): void
    {
        $unclassified = [];
        $unprotected = [];
        $count = 0;

        foreach (RouteCatalog::app() as $route) {
            $count++;

            if (! RouteCatalog::hasAuth($route)) {
                $unprotected[] = RouteCatalog::key($route);
            }
        }

        foreach (RouteCatalog::all() as $route) {
            $isVendor = RouteCatalog::isVendor($route);
            $isPublic = RouteCatalog::isPublic($route);

            // Nothing outside the vendor packages may be anonymous unless it is in the public allowlist with a reason.
            if (! $isVendor && ! $isPublic && ! RouteCatalog::hasAuth($route)) {
                $unclassified[] = RouteCatalog::key($route);
            }
        }

        $this->assertSame([], $unprotected, 'These application routes are reachable without signing in and are not on the public allowlist (tests/Support/RouteCatalog.php): '.implode(', ', $unprotected));
        $this->assertSame([], $unclassified);
        $this->assertGreaterThan(100, $count, 'The router should expose well over a hundred application routes; the catalogue is probably reading the wrong list.');
    }

    public function test_the_public_allowlist_has_no_stale_entries(): void
    {
        $present = [];

        foreach (RouteCatalog::all() as $route) {
            $present[$route->getName() ?? ''] = true;
            $present[strtoupper(RouteCatalog::method($route)).' '.$route->uri()] = true;
        }

        foreach (array_keys(RouteCatalog::PUBLIC) as $entry) {
            $this->assertArrayHasKey($entry, $present, "Public allowlist entry '{$entry}' matches no route any more: remove it.");
        }
    }

    public function test_operator_routes_are_exactly_the_expected_set_and_refuse_ordinary_users(): void
    {
        $actual = [];

        foreach (RouteCatalog::app() as $route) {
            if (RouteCatalog::hasOperator($route)) {
                $actual[] = $route->getName();
            }
        }

        sort($actual);
        $expected = RouteCatalog::OPERATOR;
        sort($expected);
        $this->assertSame($expected, $actual, 'The set of operator-only routes changed: review the new route and update RouteCatalog::OPERATOR.');

        $t = $this->tenant('Alpha');

        foreach (RouteCatalog::app() as $route) {
            if (RouteCatalog::hasOperator($route)) {
                $url = RouteCatalog::url($route, $t);
                $this->send($t['owner'], $route, $url, $t)->assertForbidden();
            }
        }
    }

    // -------------------------------------------------------------- guests

    public function test_a_guest_gets_401_json_or_a_login_redirect_on_every_app_route(): void
    {
        $t = $this->tenant('Alpha');
        $seen = 0;

        foreach (RouteCatalog::app() as $route) {
            $seen++;
            $url = RouteCatalog::url($route, $t);
            $name = RouteCatalog::key($route);

            $response = $this->send(null, $route, $url, $t);
            // The studio's JSON endpoints answer 401; the few HTML-only pages outside design/* send the guest to the login page.
            $this->assertContains($response->getStatusCode(), [401, 302], "{$name} answered a guest with ".$response->getStatusCode());
            if (str_starts_with($route->uri(), 'design/') || str_starts_with($route->uri(), 'api/')) {
                $this->assertSame(401, $response->getStatusCode(), "{$name} answered a guest with ".$response->getStatusCode());
            }
            $this->assertStringNotContainsString($t['sentinel'], (string) $response->getContent(), "{$name} leaked to a guest");

            if (RouteCatalog::method($route) === 'get') {
                $html = $this->get($url);
                $this->assertContains($html->getStatusCode(), [302, 401], "{$name} gave a guest a page");
                if ($html->getStatusCode() === 302) {
                    $this->assertStringContainsString('login', (string) $html->headers->get('Location'), "{$name} redirected a guest somewhere other than login");
                }
            }
        }

        $this->assertGreaterThan(100, $seen);
    }

    // -------------------------------------------------------------- another business

    public function test_a_member_of_another_business_gets_404_on_every_route_that_takes_an_id(): void
    {
        $a = $this->tenant('Alpha');
        $b = $this->tenant('Bravo');
        $tested = 0;
        $failures = [];

        foreach (RouteCatalog::app() as $route) {
            if (RouteCatalog::tenantParams($route) === []) {
                continue;
            }

            if (RouteCatalog::hasOperator($route)) {
                continue; // operator gate answers 403 before any id is looked at; covered by its own test
            }

            // Every role of business B, against business A's ids.
            foreach (['owner', 'admin', 'editor', 'viewer'] as $role) {
                $tested++;
                $response = $this->send($b[$role], $route, RouteCatalog::url($route, $a), $a);

                if ($response->getStatusCode() !== 404) {
                    $failures[] = RouteCatalog::key($route)." as B {$role} -> ".$response->getStatusCode();
                }

                if (str_contains((string) $response->getContent(), $a['sentinel'])) {
                    $failures[] = RouteCatalog::key($route)." as B {$role} leaked A's data";
                }
            }
        }

        $this->assertGreaterThan(100, $tested);
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_a_stranger_with_only_a_personal_workspace_gets_404_everywhere(): void
    {
        $a = $this->tenant('Alpha');
        $stranger = $this->person('Sam Stranger');
        $failures = [];

        foreach (RouteCatalog::app() as $route) {
            if (RouteCatalog::tenantParams($route) === [] || RouteCatalog::hasOperator($route)) {
                continue;
            }

            $response = $this->send($stranger, $route, RouteCatalog::url($route, $a), $a);

            if ($response->getStatusCode() !== 404 || str_contains((string) $response->getContent(), $a['sentinel'])) {
                $failures[] = RouteCatalog::key($route).' -> '.$response->getStatusCode();
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    // -------------------------------------------------------------- nested id pairings (IDOR)

    public function test_my_project_with_another_businesss_child_id_is_a_404_on_every_nested_route(): void
    {
        $mine = $this->tenant('Alpha');
        $theirs = $this->tenant('Bravo');
        $failures = [];
        $pairs = 0;

        foreach (RouteCatalog::app() as $route) {
            $params = RouteCatalog::tenantParams($route);

            if (! in_array('project', $params, true) || count($params) < 2) {
                continue;
            }

            foreach (array_diff($params, ['project']) as $child) {
                $pairs++;
                $override = [$child => RouteCatalog::value($child, $theirs)];
                $url = RouteCatalog::url($route, $mine, $override);
                $response = $this->send($mine['owner'], $route, $url, $mine);
                $name = RouteCatalog::key($route)." (project A + {$child} of B)";

                // A foreign child must never be served, changed or revealed. 404 is the contract; a 403 would confirm it exists.
                if ($response->getStatusCode() !== 404) {
                    $failures[] = "{$name} -> ".$response->getStatusCode();
                }

                if (str_contains((string) $response->getContent(), $theirs['sentinel'])) {
                    $failures[] = "{$name} leaked B's data";
                }
            }
        }

        $this->assertGreaterThan(15, $pairs);
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_my_project_with_a_sibling_projects_child_id_is_a_404_on_every_nested_route(): void
    {
        $mine = $this->tenant('Alpha');
        // A second project of the same owner in the same workspace, with its own children.
        $sibling = $this->tenant('AlphaSibling');
        $failures = [];

        foreach (RouteCatalog::app() as $route) {
            $params = RouteCatalog::tenantParams($route);

            if (! in_array('project', $params, true) || count($params) < 2) {
                continue;
            }

            foreach (array_diff($params, ['project']) as $child) {
                // The child belongs to ANOTHER project of a user who can see both: build that situation explicitly.
                $other = $this->project($mine['owner'], $mine['team'], 'team', ['name' => 'Other project']);
                $this->moveChild($child, $sibling, $other);

                $url = RouteCatalog::url($route, $mine, [$child => RouteCatalog::value($child, $sibling)]);
                $response = $this->send($mine['owner'], $route, $url, $mine);

                if (! in_array($response->getStatusCode(), [404], true)) {
                    $failures[] = RouteCatalog::key($route)." (project A + {$child} of another project) -> ".$response->getStatusCode();
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /** Re-home a child row of $from under $to (same owner and workspace), so the pairing is "mine + mine, wrong project". */
    private function moveChild(string $child, array $from, DesignProject $to): void
    {
        $model = match ($child) {
            'canvas', 'page' => $from['page'],
            'comment' => $from['comment'],
            'review' => $from['review'],
            'share' => $from['share'],
            'version' => $from['version'],
            'grant' => $from['grant'],
            default => null,
        };

        $attributes = ['design_project_id' => $to->id];

        if ($child === 'canvas' || $child === 'page') {
            $attributes['page_number'] = 50 + random_int(1, 40);
        }

        $model?->forceFill($attributes)->save();
    }

    // -------------------------------------------------------------- same workspace, wrong role

    public function test_a_reviewer_cannot_use_any_mutating_project_route_except_the_ones_a_reviewer_is_meant_to_have(): void
    {
        $t = $this->tenant('Alpha');
        $viewer = $t['viewer'];

        // What a Reviewer may legitimately do on a team project: comment (and, when named, decide a review).
        $allowed = [
            'design.projects.comments.store', 'design.projects.comments.resolve', 'design.projects.comments.unresolve',
            // Own words only: the policy refuses someone else's comment (asserted below), so it is not blanket-allowed.
        ];
        $failures = [];
        $tested = 0;

        foreach (RouteCatalog::app() as $route) {
            $params = RouteCatalog::tenantParams($route);

            if (! in_array('project', $params, true) || RouteCatalog::method($route) === 'get') {
                continue;
            }

            $name = (string) $route->getName();
            $tested++;
            $response = $this->send($viewer, $route, RouteCatalog::url($route, $t), $t);

            if (in_array($name, $allowed, true)) {
                continue;
            }

            if (! in_array($response->getStatusCode(), [403, 404], true)) {
                $failures[] = "{$name} let a reviewer through: ".$response->getStatusCode();
            }
        }

        $this->assertGreaterThan(25, $tested);
        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertSame(1, DesignProject::withoutGlobalScopes()->where('team_id', $t['team']->id)->count(), 'a reviewer changed the project set');
    }

    public function test_a_reviewer_cannot_read_what_only_editors_or_admins_may_read(): void
    {
        $t = $this->tenant('Alpha');
        $viewer = $t['viewer'];

        foreach ([
            'design.projects.shares.index', 'design.projects.grants.index', 'design.projects.activity',
            // Taking a file out is the Export capability, which a Reviewer does not have (R-45 finding S-1).
            'design.projects.canvases.export.svg', 'design.projects.canvases.export.png',
        ] as $name) {
            $route = collect(RouteCatalog::all())->first(fn ($r) => $r->getName() === $name);
            $response = $this->send($viewer, $route, RouteCatalog::url($route, $t), $t);
            $this->assertSame(403, $response->getStatusCode(), "{$name} should be closed to a reviewer");
            $this->assertStringNotContainsString($t['sentinel'].' ', (string) $response->getContent());
        }

        // An editor, who does have Export, is let through (so the 403 above is the capability and not a broken route).
        $svg = collect(RouteCatalog::all())->first(fn ($r) => $r->getName() === 'design.projects.canvases.export.svg');
        $this->assertSame(200, $this->send($t['editor'], $svg, RouteCatalog::url($svg, $t), $t)->getStatusCode());
    }

    // -------------------------------------------------------------- removed members and gone rows

    public function test_a_removed_member_loses_every_project_route_on_the_next_request(): void
    {
        $t = $this->tenant('Alpha');
        $failures = [];

        foreach (['admin', 'editor', 'viewer'] as $role) {
            $this->removeFrom($t['team'], $t[$role]);
        }

        // The editor also held an explicit grant; removing them from the workspace does not remove the grant row, which
        // is a documented exception (docs/R45_BACKEND.md "Access"), so it is deleted here like Jetstream's removal flow does.
        \App\Models\DesignProjectGrant::query()->where('user_id', $t['editor']->id)->delete();

        foreach (RouteCatalog::app() as $route) {
            if (! in_array('project', RouteCatalog::tenantParams($route), true)) {
                continue;
            }

            foreach (['admin', 'editor', 'viewer'] as $role) {
                $response = $this->send($t[$role], $route, RouteCatalog::url($route, $t), $t);

                // A reviewer named on a pending review keeps sight of that one project by design (admin is the named reviewer here).
                if ($role === 'admin' && in_array($response->getStatusCode(), [200, 201, 403, 422, 409], true)) {
                    continue;
                }

                if ($response->getStatusCode() !== 404) {
                    $failures[] = RouteCatalog::key($route)." as removed {$role} -> ".$response->getStatusCode();
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_a_deleted_project_answers_404_on_every_one_of_its_routes_even_for_its_owner(): void
    {
        $t = $this->tenant('Alpha');
        $failures = [];
        $routes = [];

        foreach (RouteCatalog::app() as $route) {
            if (in_array('project', RouteCatalog::tenantParams($route), true)) {
                $routes[] = $route;
            }
        }

        // Build every URL first (the ids vanish with the project), then delete it.
        $urls = array_map(fn ($r) => RouteCatalog::url($r, $t), $routes);
        $this->actingAs($t['owner'])->deleteJson(route('design.projects.destroy', $t['project']))->assertOk();

        foreach ($routes as $i => $route) {
            if ($route->getName() === 'design.projects.destroy') {
                continue;
            }

            $response = $this->send($t['owner'], $route, $urls[$i], $t);

            if ($response->getStatusCode() !== 404) {
                $failures[] = RouteCatalog::key($route).' after delete -> '.$response->getStatusCode();
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    // -------------------------------------------------------------- listings never mix businesses

    public function test_no_listing_or_page_shows_another_businesss_data(): void
    {
        $a = $this->tenant('Alpha');
        $b = $this->tenant('Bravo');
        $failures = [];
        $read = 0;

        foreach (RouteCatalog::app() as $route) {
            if (RouteCatalog::method($route) !== 'get') {
                continue;
            }

            // Own-scope listings (no ids) and own-id pages, read as A's owner, must never carry B's marker.
            $url = RouteCatalog::url($route, $a);

            foreach ([['json', $url], ['html', $url]] as [$kind]) {
                $response = $kind === 'json' ? $this->actingAs($a['owner'])->getJson($url) : $this->actingAs($a['owner'])->get($url);
                $read++;

                if (str_contains((string) $response->getContent(), $b['sentinel'])) {
                    $failures[] = RouteCatalog::key($route)." ({$kind}) leaked B's data to A";
                }

                if ($response->getStatusCode() >= 500) {
                    $failures[] = RouteCatalog::key($route)." ({$kind}) is a server error: ".$response->getStatusCode();
                }
            }
        }

        $this->assertGreaterThan(80, $read);
        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
