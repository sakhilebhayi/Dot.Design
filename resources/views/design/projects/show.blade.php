<x-app-layout>

@php
    // Computed here rather than inline in the x-data attribute below, so
    // the "no canvas yet" fallback is an explicit PHP null (JSON-encoded
    // to the literal `null`) rather than a string that merely spells the
    // word "null" to fake being a JS literal.
    $initialCanvasId = session('activeCanvasId', optional($project->canvases->first())->id);
    $canvasesForJs = $project->canvases->map(fn ($c) => [
        'id' => $c->id,
        'page_number' => $c->page_number,
        'elements' => $c->elements ?? [],
        'background_color' => $c->background_color ?: '#ffffff',
    ]);
@endphp

<div
    x-data="canvasEditor({
        projectId: {{ $project->id }},
        projectWidth: {{ $project->width }},
        projectHeight: {{ $project->height }},
        canvases: {{ $canvasesForJs->toJson() }},
        activeCanvasId: {{ json_encode($initialCanvasId) }},
        exportUrlTemplate: '{{ url('/design/projects/'.$project->id.'/canvases/__ID__/export.svg') }}',
        saveUrlTemplate: '{{ url('/design/projects/'.$project->id.'/canvases/__ID__') }}',
        csrfToken: '{{ csrf_token() }}',
    })"
    style="padding:1.5rem 2rem 3rem;max-width:1500px;"
>

    {{-- ─── Header ──────────────────────────────────────────────────────── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem;">
        <div>
            <div style="display:flex;align-items:center;gap:0.6rem;">
                <a href="{{ route('design.projects.index') }}" style="color:var(--text-dim);text-decoration:none;display:flex;">
                    <span class="material-symbols-rounded" style="font-size:20px;">arrow_back</span>
                </a>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;color:var(--text);margin:0;">
                    {{ $project->name }}
                </h1>
            </div>
            <p style="font-size:0.72rem;color:var(--text-dim);margin:0.15rem 0 0 1.9rem;">
                {{ $project->width }}&times;{{ $project->height }} {{ $project->unit }} &middot; {{ ucfirst($project->type) }}
            </p>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;">
            <span x-show="saveState" x-text="saveState" style="font-size:0.7rem;color:var(--text-dim);"></span>
            <a :href="exportUrlTemplate.replace('__ID__', activeCanvasId)" class="dot-btn dot-btn-ghost">
                <span class="material-symbols-rounded" style="font-size:15px;">download</span>
                Export SVG
            </a>
        </div>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#4ade80;font-size:0.8rem;">
        {{ session('status') }}
    </div>
    @endif
    @if (session('error'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.8rem;">
        {{ session('error') }}
    </div>
    @endif

    {{-- ─── Page Tabs ───────────────────────────────────────────────────── --}}
    <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:1rem;flex-wrap:wrap;">
        <template x-for="canvas in canvases" :key="canvas.id">
            <button type="button"
                    @click="selectCanvas(canvas.id)"
                    class="dot-btn"
                    :class="canvas.id === activeCanvasId ? 'dot-btn-primary' : 'dot-btn-ghost'"
                    style="padding:5px 12px;">
                <span x-text="'Page ' + canvas.page_number"></span>
            </button>
        </template>
        <form method="POST" action="{{ route('design.projects.canvases.store', $project) }}">
            @csrf
            <button type="submit" class="dot-btn dot-btn-ghost" title="Add page">
                <span class="material-symbols-rounded" style="font-size:15px;">add</span>
            </button>
        </form>
    </div>

    {{-- ─── Editor Grid ─────────────────────────────────────────────────── ---
         Wrapped in its own overflow-x:auto container: at narrow viewports,
         180px+300px fixed side columns left almost nothing for the middle
         "1fr" canvas column (it measured 47px wide in testing at ~890px
         viewport width) -- a bare 1fr can shrink arbitrarily since it has
         no lower bound. minmax(360px, 1fr) below gives the canvas column a
         real floor; when the three columns' combined minimums don't fit,
         this wrapper scrolls horizontally instead of the whole page (or
         instead of silently crushing the canvas to unusable). --}}
    <div style="overflow-x:auto;">
    <div style="display:grid;grid-template-columns:180px minmax(360px, 1fr) 300px;gap:1.25rem;align-items:start;min-width:fit-content;">

        {{-- Toolbar + Layers --}}
        <div class="dot-card" style="padding:1rem;">
            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-dim);margin-bottom:0.6rem;">Add</div>
            <button type="button" @click="addText()" class="dot-btn dot-btn-ghost" style="width:100%;justify-content:flex-start;margin-bottom:0.4rem;">
                <span class="material-symbols-rounded" style="font-size:16px;">title</span>
                Text
            </button>
            <button type="button" @click="addRectangle()" class="dot-btn dot-btn-ghost" style="width:100%;justify-content:flex-start;">
                <span class="material-symbols-rounded" style="font-size:16px;">rectangle</span>
                Rectangle
            </button>

            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-dim);margin:1rem 0 0.6rem;">Layers</div>
            <div style="display:flex;flex-direction:column;gap:0.25rem;max-height:260px;overflow-y:auto;">
                <template x-for="el in activeElements()" :key="el.id">
                    <button type="button" @click="selectedElementId = el.id"
                            class="dot-btn"
                            :class="el.id === selectedElementId ? 'dot-btn-primary' : 'dot-btn-ghost'"
                            style="width:100%;justify-content:flex-start;font-size:11.5px;padding:5px 8px;">
                        <span class="material-symbols-rounded" style="font-size:14px;" x-text="el.type === 'text' ? 'title' : 'rectangle'"></span>
                        <span x-text="el.type === 'text' ? (el.text || 'Text').slice(0, 16) : 'Rectangle'"></span>
                    </button>
                </template>
                <p x-show="activeElements().length === 0" style="font-size:0.72rem;color:var(--text-faint);margin:0.25rem 0 0;">No elements yet.</p>
            </div>
        </div>

        {{-- Canvas Surface --}}
        <div class="dot-card" style="padding:1.25rem;display:flex;justify-content:center;">
            <div
                x-ref="canvasSurface"
                @mousemove="onDrag($event)"
                @mouseup="endDrag()"
                @mouseleave="endDrag()"
                @click.self="selectedElementId = null"
                :style="`position:relative;width:100%;max-width:720px;aspect-ratio:${projectWidth}/${projectHeight};background:${activeCanvas().background_color};border-radius:6px;overflow:hidden;box-shadow:0 0 0 1px var(--border);cursor:default;`"
            >
                <template x-for="el in activeElements()" :key="el.id">
                    <div
                        @mousedown="startDrag($event, el.id)"
                        @click.stop="selectedElementId = el.id"
                        :style="`position:absolute;left:${(el.x / projectWidth) * 100}%;top:${(el.y / projectHeight) * 100}%;width:${(el.width / projectWidth) * 100}%;height:${(el.height / projectHeight) * 100}%;cursor:move;outline:${el.id === selectedElementId ? '2px solid #ec4899' : 'none'};outline-offset:2px;` + (el.type === 'text' ? `color:${el.color};font-size:${(el.fontSize / projectWidth) * 720}px;display:flex;align-items:center;overflow:hidden;white-space:nowrap;` : `background:${el.fill};`)"
                        x-text="el.type === 'text' ? el.text : ''"
                    ></div>
                </template>
            </div>
        </div>

        {{-- Properties + AI + Assets --}}
        <div style="display:flex;flex-direction:column;gap:1.1rem;">

            {{-- Properties panel --}}
            <div class="dot-card" style="padding:1rem;" x-show="selectedElement()">
                <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-dim);margin-bottom:0.6rem;">Properties</div>

                <template x-if="selectedElement()">
                    <div style="display:flex;flex-direction:column;gap:0.6rem;">
                        <template x-if="selectedElement().type === 'text'">
                            <div>
                                <label style="display:block;font-size:0.68rem;color:var(--text-dim);margin-bottom:0.25rem;">Text</label>
                                <textarea x-model="selectedElement().text" @input="scheduleSave()" class="dot-input" rows="2"></textarea>
                            </div>
                        </template>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                            <div>
                                <label style="display:block;font-size:0.68rem;color:var(--text-dim);margin-bottom:0.25rem;">Width</label>
                                <input type="number" min="1" x-model.number="selectedElement().width" @input="scheduleSave()" class="dot-input" />
                            </div>
                            <div>
                                <label style="display:block;font-size:0.68rem;color:var(--text-dim);margin-bottom:0.25rem;">Height</label>
                                <input type="number" min="1" x-model.number="selectedElement().height" @input="scheduleSave()" class="dot-input" />
                            </div>
                        </div>

                        <template x-if="selectedElement().type === 'text'">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                                <div>
                                    <label style="display:block;font-size:0.68rem;color:var(--text-dim);margin-bottom:0.25rem;">Font Size</label>
                                    <input type="number" min="1" x-model.number="selectedElement().fontSize" @input="scheduleSave()" class="dot-input" />
                                </div>
                                <div>
                                    <label style="display:block;font-size:0.68rem;color:var(--text-dim);margin-bottom:0.25rem;">Color</label>
                                    <input type="color" x-model="selectedElement().color" @input="scheduleSave()" class="dot-input" style="padding:2px;height:34px;" />
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedElement().type === 'rectangle'">
                            <div>
                                <label style="display:block;font-size:0.68rem;color:var(--text-dim);margin-bottom:0.25rem;">Fill</label>
                                <input type="color" x-model="selectedElement().fill" @input="scheduleSave()" class="dot-input" style="padding:2px;height:34px;" />
                            </div>
                        </template>

                        <button type="button" @click="deleteSelected()" class="dot-btn dot-btn-ghost" style="color:#f87171;justify-content:center;margin-top:0.25rem;">
                            <span class="material-symbols-rounded" style="font-size:15px;">delete</span>
                            Delete Element
                        </button>
                    </div>
                </template>
            </div>

            {{-- AI Generation panel --}}
            <div class="dot-card" style="padding:1rem;">
                <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.6rem;">
                    <span class="material-symbols-rounded" style="font-size:16px;color:#22c55e;">auto_awesome</span>
                    <span style="font-family:'Syne',sans-serif;font-size:0.82rem;font-weight:700;color:var(--text);">Generate with AI</span>
                </div>
                @if(config('ai_image.driver') === 'mock')
                <p style="font-size:0.68rem;color:var(--text-muted);margin:0 0 0.6rem;">{{ \App\Services\Ai\AiImageResult::PLACEHOLDER_LABEL }}. Results are stock placeholder images, not generated art.</p>
                @endif
                <form method="POST" action="{{ route('design.projects.ai-generations.store', $project) }}" style="display:flex;flex-direction:column;gap:0.5rem;">
                    @csrf
                    <textarea name="prompt" required maxlength="1000" rows="3" class="dot-input" placeholder="Describe the image you want...">{{ old('prompt') }}</textarea>
                    <button type="submit" class="dot-btn dot-btn-primary" style="justify-content:center;">
                        <span class="material-symbols-rounded" style="font-size:15px;">auto_awesome</span>
                        Generate
                    </button>
                </form>

                @if($generations->isNotEmpty())
                <div style="margin-top:0.85rem;display:flex;flex-direction:column;gap:0.4rem;max-height:160px;overflow-y:auto;">
                    @foreach($generations as $generation)
                    <div style="display:flex;align-items:center;gap:0.5rem;padding:0.4rem 0.5rem;border-radius:6px;background:rgba(127,127,127,0.06);">
                        <img src="{{ $generation->result_url }}" alt="" style="width:28px;height:28px;border-radius:4px;object-fit:cover;flex-shrink:0;" />
                        <span style="font-size:0.68rem;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $generation->prompt }}@if($generation->provider === 'mock') <em style="font-style:normal;opacity:0.7;">&middot; {{ \App\Services\Ai\AiImageResult::PLACEHOLDER_LABEL }}</em>@endif</span>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Assets --}}
            <div class="dot-card" style="padding:1rem;">
                <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-dim);margin-bottom:0.6rem;">Your Assets</div>
                @if($recentAssets->isEmpty())
                <p style="font-size:0.72rem;color:var(--text-faint);margin:0;">No assets yet. Direct uploads are not available; AI placeholders will appear here.</p>
                @else
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.4rem;">
                    @foreach($recentAssets as $asset)
                    <div style="position:relative;border-radius:6px;overflow:hidden;background:rgba(127,127,127,0.06);aspect-ratio:1;">
                        <img src="{{ $asset->file_path }}" alt="{{ $asset->name }}" @if(! empty($asset->meta['placeholder'])) title="{{ \App\Services\Ai\AiImageResult::PLACEHOLDER_LABEL }}" @endif style="width:100%;height:100%;object-fit:cover;" />
                        <form method="POST" action="{{ route('design.assets.destroy', $asset) }}" style="position:absolute;top:2px;right:2px;" onsubmit="return confirm('Delete this asset?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" style="width:18px;height:18px;border-radius:50%;background:rgba(0,0,0,0.6);border:none;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;">
                                <span class="material-symbols-rounded" style="font-size:12px;">close</span>
                            </button>
                        </form>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

        </div>
    </div>
    </div>

</div>

<script>
function canvasEditor(config) {
    return {
        projectId: config.projectId,
        projectWidth: config.projectWidth,
        projectHeight: config.projectHeight,
        canvases: config.canvases,
        activeCanvasId: config.activeCanvasId,
        // Exposed as real properties (not just read via closure over
        // `config`) because the blade template's own :href binding
        // ("Export SVG") evaluates against this component's data scope
        // directly, not against the config object passed into this factory.
        exportUrlTemplate: config.exportUrlTemplate,
        saveUrlTemplate: config.saveUrlTemplate,
        csrfToken: config.csrfToken,
        selectedElementId: null,
        dragging: null,
        dragOffsetX: 0,
        dragOffsetY: 0,
        scale: 1,
        saveState: '',
        saveTimer: null,

        activeCanvas() {
            return this.canvases.find(c => c.id === this.activeCanvasId) || this.canvases[0];
        },
        activeElements() {
            const canvas = this.activeCanvas();
            return canvas ? canvas.elements : [];
        },
        selectedElement() {
            return this.activeElements().find(el => el.id === this.selectedElementId) || null;
        },
        selectCanvas(id) {
            this.activeCanvasId = id;
            this.selectedElementId = null;
        },

        addText() {
            const el = { id: this.uuid(), type: 'text', x: 40, y: 40, width: 240, height: 48, text: 'Double-click to edit', fontSize: 28, color: '#18181b' };
            this.activeElements().push(el);
            this.selectedElementId = el.id;
            this.scheduleSave();
        },
        addRectangle() {
            const el = { id: this.uuid(), type: 'rectangle', x: 40, y: 40, width: 180, height: 120, fill: '#ec4899' };
            this.activeElements().push(el);
            this.selectedElementId = el.id;
            this.scheduleSave();
        },
        deleteSelected() {
            if (! this.selectedElementId) return;
            const canvas = this.activeCanvas();
            canvas.elements = canvas.elements.filter(el => el.id !== this.selectedElementId);
            this.selectedElementId = null;
            this.scheduleSave();
        },

        startDrag(e, id) {
            this.selectedElementId = id;
            const el = this.activeElements().find(el => el.id === id);
            if (! el || ! this.$refs.canvasSurface) return;
            const rect = this.$refs.canvasSurface.getBoundingClientRect();
            this.scale = rect.width / this.projectWidth;
            this.dragging = id;
            this.dragOffsetX = (e.clientX - rect.left) / this.scale - el.x;
            this.dragOffsetY = (e.clientY - rect.top) / this.scale - el.y;
        },
        onDrag(e) {
            if (! this.dragging || ! this.$refs.canvasSurface) return;
            const rect = this.$refs.canvasSurface.getBoundingClientRect();
            const el = this.activeElements().find(el => el.id === this.dragging);
            if (! el) return;
            el.x = Math.max(0, Math.round((e.clientX - rect.left) / this.scale - this.dragOffsetX));
            el.y = Math.max(0, Math.round((e.clientY - rect.top) / this.scale - this.dragOffsetY));
        },
        endDrag() {
            if (! this.dragging) return;
            this.dragging = null;
            this.scheduleSave();
        },

        scheduleSave() {
            this.saveState = 'Saving…';
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.save(), 500);
        },
        async save() {
            const canvas = this.activeCanvas();
            if (! canvas) return;
            const url = this.saveUrlTemplate.replace('__ID__', canvas.id);
            try {
                const response = await fetch(url, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({ elements: canvas.elements, background_color: canvas.background_color }),
                });
                this.saveState = response.ok ? 'Saved' : 'Save failed';
            } catch (e) {
                this.saveState = 'Save failed';
            }
            setTimeout(() => { this.saveState = ''; }, 1500);
        },

        uuid() {
            return (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : 'el-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        },
    };
}
</script>

</x-app-layout>
