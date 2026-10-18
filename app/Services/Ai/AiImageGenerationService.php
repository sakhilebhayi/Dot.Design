<?php

namespace App\Services\Ai;

use App\Events\AiGenerationCompleted;
use App\Events\AiGenerationFailed;
use App\Events\AiGenerationRequested;
use App\Events\AssetUploaded;
use App\Models\AiGenerationLog;
use App\Models\DesignAsset;
use App\Models\User;
use App\Services\Ai\Contracts\AiImageDriver;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Wires AiGenerationLog to an actual (driver-abstracted) generation call --
 * previously the table existed with no code path that ever wrote to it.
 * Resolves its driver from config('ai_image.driver')/config('ai_image.drivers')
 * (see config/ai_image.php) rather than being hardcoded to one provider, per
 * docs/AI-INTEGRATION.md §1's speced abstraction.
 */
class AiImageGenerationService
{
    public function generate(User $user, string $prompt, ?int $projectId = null, array $options = []): AiGenerationLog
    {
        event(new AiGenerationRequested($user, $prompt, $projectId));

        try {
            $result = $this->resolveDriver()->generate($prompt, $options);
        } catch (Throwable $e) {
            event(new AiGenerationFailed($user, $prompt, $e->getMessage()));
            throw $e;
        }

        $log = AiGenerationLog::create([
            'user_id' => $user->id,
            'design_project_id' => $projectId,
            'prompt' => $prompt,
            'result_url' => $result->url,
            'provider' => $result->provider,
            'tokens_used' => $result->tokensUsed,
        ]);

        event(new AiGenerationCompleted($log));

        $asset = DesignAsset::create([
            'user_id' => $user->id,
            'name' => Str::limit($prompt, 60, ''),
            'type' => 'image',
            'file_path' => $result->url,
            'mime_type' => 'image/svg+xml',
            'file_size' => null,
            'meta' => [
                'ai_generation_log_id' => $log->id,
                'prompt' => $prompt,
                'placeholder' => $result->isPlaceholder,
            ],
        ]);

        event(new AssetUploaded($asset));

        return $log;
    }

    private function resolveDriver(): AiImageDriver
    {
        $name = config('ai_image.driver', 'mock');
        $class = config("ai_image.drivers.{$name}");

        if (! $class) {
            throw new RuntimeException("No AI image driver registered for [{$name}]. Check config/ai_image.php.");
        }

        return app($class);
    }
}
