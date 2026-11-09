<?php

namespace App\Http\Controllers\Studio;

use App\Events\DesignCommentAdded;
use App\Models\DesignCanvas;
use App\Models\DesignComment;
use App\Models\DesignProject;
use App\Models\User;
use App\Services\Design\ActivityLog;
use App\Support\Design\DesignAccess;
use App\Support\Design\DesignCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Comments and threads on a project. A thread is a top-level comment (optionally pinned to a page, an element or an
 * x/y position) plus flat replies. Members comment with the comment capability; guests comment through a share link
 * (PublicShareController). Authors edit and delete their own words; workspace owners and admins can delete any.
 */
class CommentController extends StudioController
{
    public function __construct(private readonly ActivityLog $activity) {}

    public function index(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('viewAny', [DesignComment::class, $project]);

        $max = (int) config('design.studio.comments.max_per_listing', 500);

        $threads = DesignComment::query()
            ->where('design_project_id', $project->id)
            ->whereNull('parent_id')
            ->when($request->filled('page_id'), fn ($q) => $q->where('page_id', (int) $request->query('page_id')))
            ->when($request->filled('element_id'), fn ($q) => $q->where('element_id', (string) $request->query('element_id')))
            ->when($request->query('resolved') !== null && $request->query('resolved') !== '', fn ($q) => $request->boolean('resolved') ? $q->whereNotNull('resolved_at') : $q->whereNull('resolved_at'))
            ->with(['author:id,name', 'replies.author:id,name'])
            ->orderBy('id')
            ->limit($max)
            ->get();

        $viewer = $request->user();
        $role = DesignAccess::teamRole($viewer->id, $project->team_id);
        $moderator = in_array($role, ['owner', 'admin'], true) || ($project->team_id === null && (int) $project->user_id === $viewer->id);
        $canComment = DesignAccess::can($viewer, $project, DesignCapability::Comment);

        return response()->json([
            'comments' => $threads->map(fn (DesignComment $c) => $this->present($c, $viewer, $moderator, $canComment) + [
                'replies' => $c->replies->map(fn (DesignComment $r) => $this->present($r, $viewer, $moderator, $canComment))->all(),
            ])->all(),
            'unresolved' => $threads->whereNull('resolved_at')->count(),
        ]);
    }

    public function store(Request $request, DesignProject $project): JsonResponse
    {
        $this->authorize('create', [DesignComment::class, $project]);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.(int) config('design.studio.comments.max_length', 2000)],
            'page_id' => ['nullable', 'integer'],
            'element_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'x' => ['nullable', 'numeric', 'between:-40000,40000', 'required_with:y'],
            'y' => ['nullable', 'numeric', 'between:-40000,40000', 'required_with:x'],
            'parent_id' => ['nullable', 'integer'],
            'mentions' => ['nullable', 'array', 'max:'.(int) config('design.studio.comments.max_mentions', 20)],
            'mentions.*' => ['integer', 'distinct'],
        ]);

        $body = $this->cleanBody($data['body']);

        if ($body === '') {
            return $this->invalid('A comment cannot be empty.', ['body' => ['Write something first.']]);
        }

        $parent = null;

        if (! empty($data['parent_id'])) {
            $parent = DesignComment::where('design_project_id', $project->id)->whereNull('parent_id')->find((int) $data['parent_id']);

            if ($parent === null) {
                return $this->invalid('That thread could not be found.', ['parent_id' => ['Unknown thread.']]);
            }
        }

        $pageId = $parent?->page_id ?? ($data['page_id'] ?? null);

        if ($pageId !== null && ! DesignCanvas::where('design_project_id', $project->id)->whereKey($pageId)->exists()) {
            return $this->invalid('That page is not part of this project.', ['page_id' => ['Unknown page.']]);
        }

        $mentions = $this->mentionable($project, array_map('intval', $data['mentions'] ?? []));

        if ($mentions === null) {
            return $this->invalid('You can only mention people who can see this project.', ['mentions' => ['Someone mentioned cannot see this project.']]);
        }

        $comment = DesignComment::create([
            'design_project_id' => $project->id,
            'page_id' => $pageId,
            'element_id' => $parent?->element_id ?? ($data['element_id'] ?? null),
            'x' => $parent ? $parent->x : ($data['x'] ?? null),
            'y' => $parent ? $parent->y : ($data['y'] ?? null),
            'user_id' => $request->user()->id,
            'body' => $body,
            'mentions' => $mentions === [] ? null : $mentions,
            'parent_id' => $parent?->id,
        ]);

        DesignCommentAdded::dispatch($comment, $project, $mentions);

        $moderator = in_array(DesignAccess::teamRole($request->user()->id, $project->team_id), ['owner', 'admin'], true);

        return response()->json(['status' => 'created', 'comment' => $this->present($comment->load('author:id,name'), $request->user(), $moderator, true)], 201);
    }

    public function update(Request $request, DesignProject $project, DesignComment $comment): JsonResponse
    {
        $this->childOf($project, $comment);
        $this->authorize('update', $comment);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.(int) config('design.studio.comments.max_length', 2000)],
            'mentions' => ['nullable', 'array', 'max:'.(int) config('design.studio.comments.max_mentions', 20)],
            'mentions.*' => ['integer', 'distinct'],
        ]);

        $body = $this->cleanBody($data['body']);

        if ($body === '') {
            return $this->invalid('A comment cannot be empty.', ['body' => ['Write something first.']]);
        }

        $attributes = ['body' => $body];

        if (array_key_exists('mentions', $data)) {
            $mentions = $this->mentionable($project, array_map('intval', $data['mentions'] ?? []));

            if ($mentions === null) {
                return $this->invalid('You can only mention people who can see this project.', ['mentions' => ['Someone mentioned cannot see this project.']]);
            }

            $attributes['mentions'] = $mentions === [] ? null : $mentions;
        }

        $comment->update($attributes);

        return response()->json(['status' => 'updated', 'comment' => $this->present($comment->load('author:id,name'), $request->user(), false, true)]);
    }

    public function destroy(Request $request, DesignProject $project, DesignComment $comment): JsonResponse
    {
        $this->childOf($project, $comment);
        $this->authorize('delete', $comment);

        $wasOthers = $comment->user_id !== $request->user()->id;
        $id = $comment->id;
        $comment->delete();

        if ($wasOthers) {
            $this->activity->record($request->user(), $project, 'comment.deleted_by_moderator', 'comment', $id);
        }

        return response()->json(['status' => 'deleted']);
    }

    public function resolve(Request $request, DesignProject $project, DesignComment $comment): JsonResponse
    {
        return $this->setResolved($request, $project, $comment, true);
    }

    public function unresolve(Request $request, DesignProject $project, DesignComment $comment): JsonResponse
    {
        return $this->setResolved($request, $project, $comment, false);
    }

    private function setResolved(Request $request, DesignProject $project, DesignComment $comment, bool $resolved): JsonResponse
    {
        $this->childOf($project, $comment);
        $this->authorize('resolve', $comment);

        if ($comment->parent_id !== null) {
            return $this->invalid('Resolve the whole conversation, not a single reply.', ['comment' => ['Replies cannot be resolved on their own.']]);
        }

        $comment->forceFill($resolved
            ? ['resolved_at' => $comment->resolved_at ?? now(), 'resolved_by' => $comment->resolved_by ?? $request->user()->id]
            : ['resolved_at' => null, 'resolved_by' => null])->save();

        return response()->json(['status' => $resolved ? 'resolved' : 'reopened', 'comment' => $this->present($comment->load('author:id,name'), $request->user(), false, true)]);
    }

    /**
     * Mentions have to be people who can see the project. Returns the clean id list, or null if any cannot.
     *
     * @param  list<int>  $ids
     * @return list<int>|null
     */
    private function mentionable(DesignProject $project, array $ids): ?array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $users = User::whereIn('id', $ids)->get(['id']);

        if ($users->count() !== count($ids)) {
            return null;
        }

        foreach ($users as $user) {
            if (! DesignAccess::can($user, $project, DesignCapability::View)) {
                return null;
            }
        }

        return $ids;
    }

    private function cleanBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body));
    }

    /** @return array<string, mixed> */
    private function present(DesignComment $c, User $viewer, bool $moderator, bool $canComment): array
    {
        $mine = $c->user_id === $viewer->id;

        return [
            'id' => $c->id,
            'parent_id' => $c->parent_id,
            'page_id' => $c->page_id,
            'element_id' => $c->element_id,
            'x' => $c->x,
            'y' => $c->y,
            'body' => $c->body,
            'mentions' => $c->mentions ?? [],
            'author' => $c->author ? ['id' => $c->author->id, 'name' => $c->author->name] : null,
            'guest_name' => $c->guest_name,
            'is_guest' => $c->user_id === null,
            'resolved' => $c->resolved_at !== null,
            'resolved_at' => $c->resolved_at?->toIso8601String(),
            'resolved_by' => $c->resolved_by,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
            'can' => ['edit' => $mine && $canComment, 'delete' => $mine || $moderator, 'resolve' => $canComment && $c->parent_id === null],
        ];
    }
}
