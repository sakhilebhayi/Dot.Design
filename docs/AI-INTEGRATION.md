# AI Integration Improvements

> Upgrades for Dot.Design's generative AI features. Each section is independently applicable.

---

## 1. AI Service Abstraction

**Problem:** `AiGenerationLog` supports four providers (`anthropic`, `openai`, `stability`, `replicate`) but there is no service layer or driver abstraction. Switching or adding providers requires code changes in multiple places.

**Solution:** Driver-based AI service.

```php
// app/Services/Ai/Contracts/AiImageDriver.php
interface AiImageDriver
{
    public function generate(string $prompt, array $options = []): AiImageResult;
}

// app/Services/Ai/AiImageResult.php
readonly class AiImageResult
{
    public function __construct(
        public string  $url,
        public int     $tokensUsed,
        public string  $provider,
        public ?string $revisedPrompt = null,
    ) {}
}
```

```php
// app/Services/Ai/Drivers/AnthropicDriver.php
class AnthropicDriver implements AiImageDriver
{
    public function __construct(private string $apiKey, private string $model) {}

    public function generate(string $prompt, array $options = []): AiImageResult
    {
        // HTTP call to Anthropic API
        $response = Http::withToken($this->apiKey)
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => $options['max_tokens'] ?? 1024,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

        $response->throw();

        return new AiImageResult(
            url:        $response->json('content.0.source.url') ?? '',
            tokensUsed: $response->json('usage.input_tokens') + $response->json('usage.output_tokens'),
            provider:   'anthropic',
        );
    }
}
```

Register in `AppServiceProvider`:

```php
$this->app->singleton(AiGenerationService::class, function () {
    $config  = config('ai');
    $default = $config['default'];
    $cfg     = $config['providers'][$default];

    $driver = match ($default) {
        'anthropic' => new AnthropicDriver($cfg['key'], $cfg['model']),
        'openai'    => new OpenAiDriver($cfg['key'], $cfg['model']),
        'stability' => new StabilityDriver($cfg['key']),
        'replicate' => new ReplicateDriver($cfg['token']),
        default     => throw new InvalidArgumentException("Unknown AI provider: {$default}"),
    };

    return new AiGenerationService($driver);
});
```

---

## 2. Generation Action

```php
// app/Actions/Ai/GenerateImageFromPrompt.php
class GenerateImageFromPrompt
{
    public function __construct(private AiGenerationService $ai) {}

    public function execute(User $user, DesignProject $project, string $prompt): AiGenerationLog
    {
        $result = $this->ai->generate($prompt);

        return AiGenerationLog::create([
            'user_id'           => $user->id,
            'design_project_id' => $project->id,
            'prompt'            => $prompt,
            'result_url'        => $result->url,
            'provider'          => $result->provider,
            'tokens_used'       => $result->tokensUsed,
        ]);
    }
}
```

---

## 3. Queued Generation

AI calls can take 5–30 seconds. Never run them in a synchronous HTTP request.

```php
// app/Jobs/GenerateAiImageJob.php
class GenerateAiImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly User          $user,
        public readonly DesignProject $project,
        public readonly string        $prompt,
        public readonly string        $pendingId,  // client-side placeholder ID
    ) {}

    public function handle(GenerateImageFromPrompt $action): void
    {
        $log = $action->execute($this->user, $this->project, $this->prompt);

        // Broadcast result back to the user's canvas
        broadcast(new AiImageGenerated($this->user, $log, $this->pendingId));
    }

    public function failed(Throwable $e): void
    {
        broadcast(new AiImageGenerationFailed($this->user, $this->pendingId, $e->getMessage()));
    }
}
```

**Livewire prompt panel:**

```php
public function generate(string $prompt): void
{
    $pendingId = (string) Str::uuid();

    $this->pendingGenerations[] = [
        'id'     => $pendingId,
        'prompt' => $prompt,
        'status' => 'pending',
    ];

    GenerateAiImageJob::dispatch(auth()->user(), $this->project, $prompt, $pendingId);
}
```

Listen on the front end via Laravel Echo + Reverb:

```js
Echo.private(`users.${userId}`)
    .listen('AiImageGenerated', (e) => {
        this.resolvePending(e.pendingId, e.imageUrl);
    })
    .listen('AiImageGenerationFailed', (e) => {
        this.failPending(e.pendingId, e.message);
    });
```

---

## 4. Prompt Engineering

**Problem:** Raw user prompts sent directly to the API produce inconsistent results.

**Add a prompt enrichment step:**

```php
// app/Services/Ai/PromptEnricher.php
class PromptEnricher
{
    private const SYSTEM_CONTEXT = <<<PROMPT
You are a graphic design assistant for Dot.Design. Generate professional, clean, on-brand visuals.
Follow design principles: clear hierarchy, adequate white space, and balanced composition.
Output images suitable for social media, print, and digital marketing.
PROMPT;

    public function enrich(string $userPrompt, array $context = []): string
    {
        $style = $context['style'] ?? 'modern and professional';
        $dims  = $context['dimensions'] ?? '1080x1080';
        $brand = $context['brandColors'] ?? [];

        $enriched = self::SYSTEM_CONTEXT . "\n\n";
        $enriched .= "User request: {$userPrompt}\n";
        $enriched .= "Style: {$style}\n";
        $enriched .= "Dimensions: {$dims}\n";

        if ($brand) {
            $enriched .= 'Brand colours: ' . implode(', ', $brand) . "\n";
        }

        return $enriched;
    }
}
```

---

## 5. Rate Limiting

Prevent runaway AI usage per user per day.

```php
// app/Http/Middleware/ThrottleAiGeneration.php
class ThrottleAiGeneration
{
    public function handle(Request $request, Closure $next): Response
    {
        $key   = 'ai_gen:' . auth()->id() . ':' . now()->toDateString();
        $limit = config('ai.daily_limit', 50);

        if (Cache::get($key, 0) >= $limit) {
            abort(429, 'Daily AI generation limit reached. Resets at midnight.');
        }

        Cache::increment($key, 1);
        Cache::expire($key, now()->endOfDay());

        return $next($request);
    }
}
```

Also apply Laravel's built-in `throttle:10,1` (10 requests per minute) to the AI generation route.

---

## 6. AI Layout Suggestions

Beyond image generation, Claude can generate canvas element layouts from a description.

**Endpoint:** `POST /api/canvas/{canvas}/suggest-layout`

**Prompt template:**

```
Given the following canvas dimensions: {width}x{height}px
And the design goal: "{userDescription}"
Generate a JSON array of canvas elements in Fabric.js format.
Include: background color, text elements with placeholder content, geometric shapes.
Keep it clean, balanced, and professional.
Return ONLY valid JSON. No explanation.
```

**Validate the response** against a JSON schema before applying to the canvas — never pass raw AI output directly to the canvas.

---

## 7. Generation History UI

The `AiGenerationLog` table is populated but never surfaced in the UI.

**Add a side panel in the canvas editor:**

```
┌─────────────────────────────┐
│ AI Generation History       │
├─────────────────────────────┤
│ [thumbnail] "sunset beach"  │
│             anthropic · 2m  │
│ [Use]  [Delete]             │
├─────────────────────────────┤
│ [thumbnail] "bold headline" │
│             openai · 1h     │
│ [Use]  [Delete]             │
└─────────────────────────────┘
```

Clicking **Use** inserts the `result_url` image into the canvas at the cursor position.

---

## 8. Content Moderation

Before displaying AI-generated images, run a content safety check.

**Option A — Anthropic built-in:** Claude models refuse harmful content by default. Log any refusals in `ai_generation_logs` with `result_url = null` and a `refusal_reason` column.

**Option B — OpenAI Moderation API** (free, fast):

```php
public function moderate(string $prompt): bool
{
    $response = Http::withToken(config('ai.providers.openai.key'))
        ->post('https://api.openai.com/v1/moderations', ['input' => $prompt]);

    return ! $response->json('results.0.flagged');
}
```

Call before queuing the generation job. If flagged, return a 422 with a user-friendly message.

---

## 9. Token Cost Tracking

`AiGenerationLog.tokens_used` is stored but not surfaced. Add a usage dashboard panel:

| Metric | Display |
|---|---|
| Tokens used today | Progress bar vs. daily budget |
| Tokens used this month | Chart by day |
| Estimated cost | Based on provider pricing config |
| Top prompts | Most frequently used prompt fragments |

Add `cost_usd` (decimal, 8,6) column to `ai_generation_logs` and calculate at log-time using per-provider rate tables in `config/ai.php`.

---

## 10. Model Fallback Chain

If the primary provider fails, fall back to the next available driver:

```php
// app/Services/Ai/AiGenerationService.php
class AiGenerationService
{
    /** @param AiImageDriver[] $drivers */
    public function __construct(private array $drivers) {}

    public function generate(string $prompt, array $options = []): AiImageResult
    {
        $lastException = null;

        foreach ($this->drivers as $driver) {
            try {
                return $driver->generate($prompt, $options);
            } catch (Throwable $e) {
                Log::warning('AI driver failed', ['driver' => $driver::class, 'error' => $e->getMessage()]);
                $lastException = $e;
            }
        }

        throw new AiGenerationException('All AI providers failed.', previous: $lastException);
    }
}
```
