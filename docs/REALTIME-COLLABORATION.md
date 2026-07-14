# Real-Time Collaboration

> Implementation guide for real-time features in Dot.Design using Laravel Reverb. Each section is independently deployable.

---

## 1. Laravel Reverb Setup

Reverb is already in `composer.json`. Ensure it is configured:

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=dotdesign
REVERB_APP_KEY=your-key
REVERB_APP_SECRET=your-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http   # https in production
```

```bash
php artisan reverb:start --port=8080
```

Install the front-end Echo client:

```bash
npm install laravel-echo pusher-js
```

```js
// resources/js/echo.js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster:    'reverb',
    key:            import.meta.env.VITE_REVERB_APP_KEY,
    wsHost:         import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort:         import.meta.env.VITE_REVERB_PORT ?? 8080,
    wssPort:        import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS:       (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

---

## 2. Channel Architecture

```
channels:
  private:
    users.{userId}            # per-user notifications (AI done, export ready)
  presence:
    canvas.{canvasId}         # who is editing this canvas right now
    project.{projectId}       # who is viewing this project
  private:
    team.{teamId}             # team-wide events (member added, project shared)
```

Define in `routes/channels.php`:

```php
// routes/channels.php
use App\Models\{DesignCanvas, DesignProject, Team};

Broadcast::channel('users.{userId}', function (User $user, int $userId) {
    return $user->id === $userId;
});

Broadcast::channel('canvas.{canvasId}', function (User $user, int $canvasId) {
    $canvas = DesignCanvas::with('project')->find($canvasId);
    if (! $canvas) return false;

    return $user->id === $canvas->project->user_id
        || $user->belongsToTeam($canvas->project->team)
        ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->profile_photo_url]
        : false;
});

Broadcast::channel('team.{teamId}', function (User $user, int $teamId) {
    return $user->belongsToTeam(Team::find($teamId));
});
```

---

## 3. Presence — Who Is Editing

Show live avatars of users currently on the same canvas.

**Front-end (Alpine.js in canvas editor):**

```js
// resources/js/canvas/presence.js
export default function canvasPresence(canvasId, currentUser) {
    return {
        presentUsers: [],

        init() {
            window.Echo.join(`canvas.${canvasId}`)
                .here((users) => { this.presentUsers = users; })
                .joining((user) => { this.presentUsers.push(user); })
                .leaving((user) => {
                    this.presentUsers = this.presentUsers.filter(u => u.id !== user.id);
                })
                .error((error) => console.error('Presence error:', error));
        },

        leave() {
            window.Echo.leave(`canvas.${canvasId}`);
        },
    };
}
```

**Template:**

```html
<div class="flex items-center gap-1" x-data="canvasPresence({{ $canvas->id }}, {{ auth()->id() }})">
  <template x-for="user in presentUsers" :key="user.id">
    <img
      :src="user.avatar"
      :title="user.name"
      class="w-7 h-7 rounded-full border-2 border-white ring-2 ring-brand-500 -ml-2 first:ml-0"
      :alt="user.name"
    />
  </template>
  <span x-show="presentUsers.length > 1" class="text-xs text-ink-muted ml-2" x-text="`${presentUsers.length} editing`"></span>
</div>
```

---

## 4. Live Canvas Updates

Broadcast canvas element changes to other users on the same canvas.

### 4a. Event

```php
// app/Events/CanvasElementUpdated.php
class CanvasElementUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly DesignCanvas $canvas,
        public readonly array        $elements,
        public readonly int          $editorId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("canvas.{$this->canvas->id}")];
    }

    public function broadcastAs(): string
    {
        return 'canvas.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'elements'  => $this->elements,
            'editor_id' => $this->editorId,
        ];
    }
}
```

### 4b. Dispatch on save

```php
// app/Livewire/Canvas/CanvasEditor.php
public function save(array $elements, string $backgroundColor): void
{
    $this->canvas->update([
        'elements'         => $elements,
        'background_color' => $backgroundColor,
    ]);

    broadcast(new CanvasElementUpdated($this->canvas, $elements, auth()->id()))->toOthers();
}
```

### 4c. Receive on front end

```js
window.Echo.join(`canvas.${canvasId}`)
    .listen('.canvas.updated', (e) => {
        if (e.editor_id !== this.currentUserId) {
            // Reload canvas from the broadcast payload
            this.canvas.loadFromJSON(e.elements, () => this.canvas.renderAll());
        }
    });
```

> **Note:** This is a "last writer wins" model. For conflict-free merging, see §7 (CRDT approach).

---

## 5. Cursor Sharing

Show other users' cursor positions as coloured dots on the canvas.

### 5a. Throttle cursor events

Do not broadcast every `mousemove`. Throttle to 30 fps maximum.

```js
// resources/js/canvas/cursor-sharing.js
let lastCursorBroadcast = 0;

this.canvas.on('mouse:move', (opt) => {
    const now = Date.now();
    if (now - lastCursorBroadcast < 33) return;  // ~30fps
    lastCursorBroadcast = now;

    const pointer = this.canvas.getPointer(opt.e);
    window.Echo.join(`canvas.${canvasId}`)
        .whisper('cursor-moved', { x: pointer.x, y: pointer.y });
});
```

### 5b. Receive cursors

```js
window.Echo.join(`canvas.${canvasId}`)
    .listenForWhisper('cursor-moved', (e) => {
        this.updateCursor(e.user, e.x, e.y);
    });
```

Render cursor overlays as absolutely-positioned `<div>` elements above the canvas, transformed via the canvas viewport matrix.

---

## 6. Notifications via Private Channel

AI generation results and export completion are sent to the user's private channel:

```php
// app/Events/AiImageGenerated.php
class AiImageGenerated implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->user->id}")];
    }

    public function broadcastWith(): array
    {
        return [
            'pending_id' => $this->pendingId,
            'image_url'  => $this->log->result_url,
            'provider'   => $this->log->provider,
        ];
    }
}
```

```js
// resources/js/notifications.js
window.Echo.private(`users.${userId}`)
    .listen('.AiImageGenerated', (e) => {
        window.dispatchEvent(new CustomEvent('toast', {
            detail: { type: 'success', message: 'AI image is ready!' }
        }));
        this.$wire.dispatch('ai-image-ready', { pendingId: e.pending_id, imageUrl: e.image_url });
    })
    .listen('.DesignExportReady', (e) => {
        window.dispatchEvent(new CustomEvent('toast', {
            detail: { type: 'success', message: `Export ready — ${e.format.toUpperCase()}` }
        }));
    });
```

---

## 7. Conflict Resolution Strategy

The simplest approach (§4) is "last writer wins" — fine for small teams. For larger teams, consider:

**Option A — Operational Transform (OT):** Complex to implement. Suitable if the canvas becomes a collaborative whiteboard.

**Option B — Element-level locking:** When a user selects an element, lock it for others.

```php
// app/Events/CanvasElementLocked.php
public function broadcastWith(): array
{
    return [
        'element_id' => $this->elementId,
        'locked_by'  => ['id' => $this->user->id, 'name' => $this->user->name],
    ];
}
```

Front end: render a coloured overlay on locked elements belonging to another user; prevent selection.

**Option C — Page-level locking:** Only one user edits a given page at a time. Others see a read-only view with a "Request edit access" button.

---

## 8. Connection Resilience

Handle disconnection gracefully:

```js
window.Echo.connector.pusher.connection.bind('unavailable', () => {
    window.dispatchEvent(new CustomEvent('toast', {
        detail: { type: 'error', message: 'Connection lost. Changes are saved locally.' }
    }));
});

window.Echo.connector.pusher.connection.bind('connected', () => {
    // Re-join channels after reconnect
    this.initPresence();
    // Sync any changes made while offline
    this.$wire.syncOfflineChanges(this.pendingChanges);
});
```

---

## 9. Scaling Reverb

For production deployments beyond a single server:

```dotenv
REVERB_SCALING_ENABLED=true
REVERB_SCALING_DRIVER=redis
```

Reverb supports horizontal scaling via Redis pub/sub. Each server subscribes to the same Redis channels, so broadcasts are fanned out regardless of which server the WebSocket connection landed on.

---

## 10. Team Broadcast Events

```php
// app/Events/TeamProjectShared.php — notify team when a project is shared
class TeamProjectShared implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [new PrivateChannel("team.{$this->project->team_id}")];
    }
}
```

Show a notification bell in the top bar with a count of unread team events. Persist to a `notifications` table using Laravel's built-in notification system.
