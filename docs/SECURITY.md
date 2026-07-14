# Security Improvements

> Security hardening checklist for Dot.Design. Mapped to OWASP Top 10 (2021). Each item is independently applicable.

---

## 1. Broken Access Control (OWASP A01)

### 1a. Authorisation on every resource action

Every route or Livewire action that touches a `DesignProject`, `DesignCanvas`, or `DesignAsset` must verify the authenticated user owns or has team access to that resource.

```php
// app/Policies/DesignProjectPolicy.php
class DesignProjectPolicy
{
    public function view(User $user, DesignProject $project): bool
    {
        return $user->id === $project->user_id
            || $user->belongsToTeam($project->team);
    }

    public function update(User $user, DesignProject $project): bool
    {
        return $user->id === $project->user_id
            || $user->hasTeamRole($project->team, 'editor');
    }

    public function delete(User $user, DesignProject $project): bool
    {
        return $user->id === $project->user_id
            || $user->ownsTeam($project->team);
    }
}
```

Register in `AuthServiceProvider`:

```php
protected $policies = [
    DesignProject::class => DesignProjectPolicy::class,
    DesignCanvas::class  => DesignCanvasPolicy::class,
    DesignAsset::class   => DesignAssetPolicy::class,
];
```

Never rely on obscurity (e.g., UUID IDs) as a substitute for authorisation.

### 1b. Lock Livewire properties

Mark server-side-only properties with `#[Locked]` to prevent client-side tampering:

```php
#[Locked]
public int $projectId;

#[Locked]
public int $userId;
```

---

## 2. Cryptographic Failures (OWASP A02)

### 2a. Sanctum tokens

Tokens are hashed at rest by default in Sanctum. Ensure `personal_access_tokens.token` is never logged.

Add to `config/logging.php` log sanitisation if a custom formatter is used.

### 2b. Signed export URLs

AI-generated images and exported files should be served via signed S3 URLs — never expose raw bucket paths:

```php
$url = Storage::temporaryUrl($path, now()->addHour());
```

### 2c. Sensitive environment variables

Never commit `.env`. Ensure `.env` is in `.gitignore`. Use secret management (AWS Secrets Manager, Doppler, or 1Password Secrets Automation) in production.

---

## 3. Injection (OWASP A03)

### 3a. No raw SQL

All database access through Eloquent or the Query Builder with bound parameters. Never concatenate user input into SQL strings.

```php
// UNSAFE — never do this
DB::statement("SELECT * FROM design_projects WHERE name = '{$name}'");

// SAFE
DesignProject::where('name', $name)->first();
```

### 3b. AI prompt injection

User-supplied prompts are sent to AI APIs. A malicious prompt could attempt to override system instructions.

**Mitigations:**
- Pre-pend a fixed system prompt that cannot be overridden by user content.
- Use the `system` parameter in Anthropic's API (separate from `messages`) — user input never touches the system role.
- Strip control characters from prompts: `preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $prompt)`.
- Validate prompt length: `max:2000` characters.

### 3c. File upload validation

Validate MIME types using file signatures (magic bytes), not just the extension or Content-Type header:

```php
// app/Actions/Assets/UploadDesignAsset.php
use Illuminate\Support\Facades\File;

$allowedMimes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml', 'font/ttf', 'font/otf'];
$detectedMime = mime_content_type($file->getRealPath());

if (! in_array($detectedMime, $allowedMimes, true)) {
    throw new InvalidArgumentException('Unsupported file type.');
}
```

Never store uploaded files in a web-accessible directory. Use `storage/app/private/` or a private S3 bucket.

### 3d. XSS prevention

- Blade `{{ }}` escapes by default — use `{!! !!}` only for explicitly trusted, sanitised HTML.
- When rendering user-created design names or descriptions in HTML, they pass through Blade's `{{ }}` automatically.
- For rich-text fields (future), use `HTMLPurifier` or `mews/purifier`.

---

## 4. Insecure Design (OWASP A04)

### 4a. Team isolation

Projects should be strictly scoped to either a user or a team — never accessible across teams. Enforce this at the query scope level (not just the policy):

```php
// app/Models/DesignProject.php
public function scopeAccessibleBy(Builder $query, User $user): Builder
{
    return $query->where(function ($q) use ($user) {
        $q->where('user_id', $user->id)
          ->orWhereIn('team_id', $user->allTeams()->pluck('id'));
    });
}
```

### 4b. Export rate limiting

Without rate limits, a malicious user could trigger thousands of export jobs, exhausting queue workers and storage.

```php
// Apply in routes/web.php
Route::post('/canvas/{canvas}/export', ExportController::class)
    ->middleware('throttle:5,1')  // 5 exports per minute
    ->name('canvas.export');
```

---

## 5. Security Misconfiguration (OWASP A05)

### 5a. Content Security Policy

Add a strict CSP header. For a Livewire + Alpine.js app:

```php
// app/Http/Middleware/ContentSecurityPolicy.php
public function handle(Request $request, Closure $next): Response
{
    $nonce    = base64_encode(random_bytes(16));
    app()->instance('csp-nonce', $nonce);

    $response = $next($request);

    $response->headers->set('Content-Security-Policy', implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-{$nonce}'",
        "style-src 'self' 'unsafe-inline'",   // Tailwind requires inline styles
        "img-src 'self' data: blob: https://*.amazonaws.com",
        "connect-src 'self' wss://".config('reverb.host'),
        "font-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
    ]));

    return $response;
}
```

### 5b. Additional security headers

```php
$response->headers->set('X-Content-Type-Options', 'nosniff');
$response->headers->set('X-Frame-Options', 'DENY');
$response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
$response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
```

### 5c. Remove debug info from production

Ensure `.env` has:

```dotenv
APP_DEBUG=false
APP_ENV=production
```

Laravel's debug mode exposes stack traces and environment variables. The Vite manifest should also not be publicly browsable.

---

## 6. Vulnerable Components (OWASP A06)

### 6a. Dependency auditing

```bash
# PHP
composer audit

# Node
npm audit
```

Add these to CI/CD pipeline (see DEVOPS.md). Fail the build on `critical` or `high` severity findings.

### 6b. Automated updates

Use Dependabot or Renovate to open PRs for security patches automatically.

---

## 7. Authentication Failures (OWASP A07)

### 7a. Rate limit authentication routes

Fortify applies rate limiting by default. Verify it is enabled in `config/fortify.php`:

```php
// Already handled by Fortify's RateLimiter for login attempts
// Confirm the limiter is not disabled in FortifyServiceProvider
```

### 7b. Ecosystem SSO token validation

The `EcosystemAuthController` validates tokens with `'ecosystem:read'` ability. After use, the token is deleted. This is correct. Additionally:

```php
// app/Http/Controllers/Auth/EcosystemAuthController.php — add expiry check
$token = PersonalAccessToken::findToken($request->token);

if (! $token || ! $token->can('ecosystem:read')) {
    abort(401, 'Invalid token.');
}

// Reject tokens older than 5 minutes
if ($token->created_at->lt(now()->subMinutes(5))) {
    $token->delete();
    abort(401, 'Token expired.');
}
```

### 7c. Two-factor authentication

Two-factor columns are migrated and Fortify feature is enabled. Ensure the 2FA setup flow is tested and that recovery codes are hashed at rest (Fortify handles this).

---

## 8. Integrity Failures (OWASP A08)

### 8a. Validate Fabric.js JSON before saving

The `elements` JSON column stores arbitrary data sent from the browser. Validate its shape before persisting:

```php
// app/Http/Requests/Design/SaveCanvasRequest.php
public function rules(): array
{
    return [
        'elements'                     => ['required', 'array'],
        'elements.version'             => ['required', 'string'],
        'elements.objects'             => ['required', 'array', 'max:500'],
        'elements.objects.*.type'      => ['required', Rule::in(['rect','circle','textbox','image','path','group','line'])],
        'background_color'             => ['required', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
    ];
}
```

Never trust the client to send well-formed canvas data.

---

## 9. Logging & Monitoring (OWASP A09)

**Log security-relevant events:**

```php
// Events to log:
Log::info('auth.ecosystem_login', ['user_id' => $user->id, 'ip' => $request->ip()]);
Log::warning('ai.generation_rate_limit', ['user_id' => auth()->id()]);
Log::notice('asset.upload', ['user_id' => auth()->id(), 'file' => $name, 'mime' => $mime]);
Log::warning('policy.denied', ['user_id' => auth()->id(), 'resource' => $resourceClass, 'action' => $ability]);
```

**Do not log:**
- Passwords, API keys, or token values
- Full canvas element data (may contain PII)
- Raw AI prompts (may contain sensitive content)

---

## 10. SSRF Prevention (OWASP A10)

When fetching external images for the canvas (e.g., from a URL the user provides), validate the URL before making an HTTP request:

```php
// app/Services/UrlFetcher.php
public function fetchSafe(string $url): string
{
    $parsed = parse_url($url);

    // Block private/internal IP ranges
    $host = $parsed['host'] ?? '';
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (! filter_var($host, FILTER_VALIDATE_IP, $flags)) {
            throw new InvalidArgumentException('Private IP addresses are not allowed.');
        }
    }

    // Only allow http/https
    if (! in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
        throw new InvalidArgumentException('Only HTTP(S) URLs are allowed.');
    }

    return Http::timeout(10)->get($url)->body();
}
```
