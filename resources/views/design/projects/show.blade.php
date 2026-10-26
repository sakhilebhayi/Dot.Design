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
        'revision' => $c->revision,
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
        assets: {{ $assets->map->toEditorArray()->values()->toJson() }},
        assetIndex: {{ $assetIndex->toJson() }},
        maxElements: {{ (int) config('design.canvas.max_elements') }},
        pngUrlTemplate: '{{ url('/design/projects/'.$project->id.'/canvases/__ID__/export.png') }}',
        pngTextAvailable: {{ is_string(config('design.export.png_font')) && is_file((string) config('design.export.png_font')) ? 'true' : 'false' }},
        pngMaxDimension: {{ (int) config('design.export.png_max_dimension') }},
        pngMaxPixels: {{ (int) config('design.export.png_max_megapixels') * 1000000 }},
        usage: {{ json_encode($assetUsage) }},
        uploadUrl: '{{ route('design.assets.store') }}',
        assetDeleteUrlTemplate: '{{ url('/design/assets/__ID__') }}',
        maxUploadBytes: {{ (int) config('design.assets.max_kilobytes') * 1024 }},
    })"
    style="padding:1.5rem 2rem 3rem;max-width:1500px;" class="ed-root"
    @keydown.window="onKey($event)"
>

    {{-- ─── Header ──────────────────────────────────────────────────────── --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem;">
        <div>
            <div style="display:flex;align-items:center;gap:0.6rem;">
                <a href="{{ route('design.projects.index') }}" style="color:var(--text-dim);text-decoration:none;display:flex;" aria-label="Back to projects">
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
            <span role="status" aria-live="polite" x-show="saveLabel()" x-text="saveLabel()"
                  :style="`font-size:0.7rem;font-weight:600;color:${saveState === 'failed' || saveState === 'conflict' ? '#f87171' : 'var(--text-dim)'};`"></span>
            <a :href="exportUrlTemplate.replace('__ID__', activeCanvasId)" class="dot-btn dot-btn-ghost">
                <span class="material-symbols-rounded" style="font-size:15px;">download</span>
                Export SVG
            </a>
            <template x-if="! pngBlockedReason()">
                <a :href="pngUrlTemplate.replace('__ID__', activeCanvasId)" class="dot-btn dot-btn-ghost">
                    <span class="material-symbols-rounded" style="font-size:15px;">image</span>
                    Export PNG
                </a>
            </template>
            <template x-if="pngBlockedReason()">
                <span class="dot-btn dot-btn-ghost" aria-disabled="true" :title="pngBlockedReason()" style="opacity:0.5;cursor:not-allowed;">
                    <span class="material-symbols-rounded" style="font-size:15px;">image</span>
                    PNG unavailable
                </span>
            </template>
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

    {{-- Save problems stay on screen until they are resolved; they are never a flash that disappears. --}}
    <div x-show="saveState === 'failed'" x-cloak role="alert"
         style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.8rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <span><strong>Not saved.</strong> <span x-text="saveMessage"></span></span>
        <button type="button" @click="retrySave()" class="dot-btn dot-btn-ghost" style="padding:4px 10px;">Retry now</button>
    </div>
    <div x-show="saveState === 'conflict'" x-cloak role="alert"
         style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.35);color:#fbbf24;font-size:0.8rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <span><strong>This page changed somewhere else.</strong> Another tab or device saved it after you opened it, so your edits here are not being saved.</span>
        <span style="display:flex;gap:0.4rem;">
            <button type="button" @click="reloadLatest()" class="dot-btn dot-btn-ghost" style="padding:4px 10px;">Reload their version</button>
            <button type="button" @click="overwriteWithMine()" class="dot-btn dot-btn-ghost" style="padding:4px 10px;">Overwrite with mine</button>
        </span>
    </div>

    {{-- ─── Page Tabs ───────────────────────────────────────────────────── --}}
    <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:1rem;flex-wrap:wrap;">
        <template x-for="canvas in canvases" :key="canvas.id">
            <button type="button"
                    :aria-label="'Page ' + canvas.page_number"
                    @click="selectCanvas(canvas.id)"
                    class="dot-btn"
                    :class="canvas.id === activeCanvasId ? 'dot-btn-primary' : 'dot-btn-ghost'"
                    style="padding:5px 12px;">
                <span x-text="'Page ' + canvas.page_number"></span>
            </button>
        </template>
        <form method="POST" action="{{ route('design.projects.canvases.store', $project) }}" @submit="flushSave()">
            @csrf
            <button type="submit" class="dot-btn dot-btn-ghost" title="Add page" aria-label="Add page">
                <span class="material-symbols-rounded" style="font-size:15px;">add</span>
            </button>
        </form>
    </div>

    {{-- ─── Editor Grid ─────────────────────────────────────────────────── ---
         Wrapped in its own overflow-x:auto container: at narrow viewports,
         180px+300px fixed side columns left almost nothing for the middle
         "1fr" canvas column -- a bare 1fr can shrink arbitrarily since it has
         no lower bound. minmax(360px, 1fr) below gives the canvas column a
         real floor; when the three columns' combined minimums don't fit,
         this wrapper scrolls horizontally instead of the whole page. --}}
    <div style="overflow-x:auto;">
    <div class="ed-grid">

        {{-- Toolbar + Layers --}}
        <div class="dot-card" style="padding:1rem;">
            <div class="ed-label">Add</div>
            <button type="button" @click="addText()" class="dot-btn dot-btn-ghost" style="width:100%;justify-content:flex-start;margin-bottom:0.4rem;">
                <span class="material-symbols-rounded" style="font-size:16px;">title</span>
                Text
            </button>
            <button type="button" @click="addRectangle()" class="dot-btn dot-btn-ghost" style="width:100%;justify-content:flex-start;">
                <span class="material-symbols-rounded" style="font-size:16px;">rectangle</span>
                Rectangle
            </button>
            <p style="font-size:0.66rem;color:var(--text-dim);margin:0.5rem 0 0;">Images: upload or pick one in Assets, then place it.</p>

            <div class="ed-label" style="margin-top:1rem;">Layers <span style="font-weight:400;text-transform:none;letter-spacing:0;">(top first)</span></div>
            <div style="display:flex;flex-direction:column;gap:0.25rem;max-height:260px;overflow-y:auto;">
                <template x-for="el in layersTopFirst()" :key="el.id">
                    <button type="button" :aria-label="layerLabel(el)" @click="selectedElementId = el.id"
                            class="dot-btn"
                            :class="el.id === selectedElementId ? 'dot-btn-primary' : 'dot-btn-ghost'"
                            :aria-pressed="el.id === selectedElementId"
                            style="width:100%;justify-content:flex-start;font-size:11.5px;padding:5px 8px;">
                        <span class="material-symbols-rounded" style="font-size:14px;" x-text="layerIcon(el)"></span>
                        <span x-text="layerLabel(el)"></span>
                    </button>
                </template>
                <p x-show="activeElements().length === 0" style="font-size:0.72rem;color:var(--text-faint);margin:0.25rem 0 0;">No elements yet.</p>
            </div>
            <p x-show="editorNote" x-text="editorNote" role="status" style="font-size:0.7rem;color:#f87171;margin:0.6rem 0 0;"></p>

            <button type="button" @click="showShortcuts = ! showShortcuts" :aria-expanded="showShortcuts" class="dot-btn dot-btn-ghost" style="width:100%;justify-content:flex-start;margin-top:1rem;font-size:11.5px;">
                <span class="material-symbols-rounded" style="font-size:15px;">keyboard</span>
                Shortcuts
            </button>
            <dl x-show="showShortcuts" x-cloak style="margin:0.6rem 0 0;font-size:0.68rem;color:var(--text-muted);display:grid;grid-template-columns:auto 1fr;gap:0.25rem 0.6rem;">
                <dt class="ed-key">2&times; tap</dt><dd style="margin:0;">Edit text</dd>
                <dt class="ed-key">Drag</dt><dd style="margin:0;">Move (Alt: no snapping)</dd>
                <dt class="ed-key">Corner</dt><dd style="margin:0;">Resize (Shift: keep ratio)</dd>
                <dt class="ed-key">Top dot</dt><dd style="margin:0;">Rotate (Shift: 15&deg; steps)</dd>
                <dt class="ed-key">Arrows</dt><dd style="margin:0;">Nudge 1px (Shift: 10px)</dd>
                <dt class="ed-key">Del</dt><dd style="margin:0;">Delete</dd>
                <dt class="ed-key">Ctrl/&#8984; Z</dt><dd style="margin:0;">Undo (+Shift: redo)</dd>
                <dt class="ed-key">Ctrl/&#8984; D</dt><dd style="margin:0;">Duplicate</dd>
                <dt class="ed-key">[ ]</dt><dd style="margin:0;">Back / forward (+Ctrl: to end)</dd>
                <dt class="ed-key">Ctrl+wheel</dt><dd style="margin:0;">Zoom</dd>
                <dt class="ed-key">Esc</dt><dd style="margin:0;">Deselect</dd>
            </dl>
        </div>

        {{-- Canvas Surface --}}
        <div class="dot-card ed-canvas-card" style="padding:0.75rem 1rem 1rem;min-width:0;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                <div style="display:flex;gap:0.3rem;">
                    <button type="button" @click="undo()" :disabled="! canUndo()" class="dot-btn dot-btn-ghost ed-icon-btn" title="Undo (Ctrl/Cmd+Z)" aria-label="Undo">
                        <span class="material-symbols-rounded">undo</span>
                    </button>
                    <button type="button" @click="redo()" :disabled="! canRedo()" class="dot-btn dot-btn-ghost ed-icon-btn" title="Redo (Ctrl/Cmd+Shift+Z)" aria-label="Redo">
                        <span class="material-symbols-rounded">redo</span>
                    </button>
                </div>
                <div style="display:flex;align-items:center;gap:0.3rem;">
                    <button type="button" @click="zoomOut()" class="dot-btn dot-btn-ghost ed-icon-btn" title="Zoom out" aria-label="Zoom out">
                        <span class="material-symbols-rounded">remove</span>
                    </button>
                    <span class="metric-val" style="font-size:0.72rem;color:var(--text-muted);min-width:3.2em;text-align:center;" x-text="zoomLabel()" aria-live="polite"></span>
                    <button type="button" @click="zoomIn()" class="dot-btn dot-btn-ghost ed-icon-btn" title="Zoom in" aria-label="Zoom in">
                        <span class="material-symbols-rounded">add</span>
                    </button>
                    <button type="button" @click="fit()" class="dot-btn dot-btn-ghost" style="padding:4px 10px;font-size:11.5px;" title="Fit the page in view">Fit</button>
                </div>
            </div>

            <div x-ref="viewport" @wheel="onWheel($event)"
                 style="overflow:auto;max-height:72vh;border-radius:6px;background:var(--bg-input);">
                <div x-ref="stage"
                     @pointerdown="onStagePointerDown($event)"
                     @pointermove="onPointerMove($event)"
                     @pointerup="endGesture()"
                     @pointercancel="endGesture()"
                     :style="stageStyle()">

                    {{-- The page: clips to the canvas, holds the elements. --}}
                    <div x-ref="page" :style="pageStyle()">
                        <template x-for="el in activeElements()" :key="el.id">
                            <div
                                tabindex="0"
                                role="button"
                                :aria-label="layerLabel(el) + ' (' + el.type + ')'"
                                @pointerdown.stop="startMove($event, el)"
                                @focus="selectedElementId = el.id"
                                :style="elementStyle(el)"
                            >
                                <span x-show="el.type === 'text'" x-text="el.text" style="width:100%;"></span>
                                <template x-if="el.type === 'image' && assetFor(el)">
                                    <img :src="assetFor(el).url" :alt="assetFor(el).name" draggable="false" style="width:100%;height:100%;display:block;object-fit:fill;pointer-events:none;user-select:none;" />
                                </template>
                                <template x-if="el.type === 'image' && ! assetFor(el)">
                                    <span style="width:100%;font-size:11px;text-align:center;color:#52525b;">Image unavailable</span>
                                </template>
                                <span x-show="el.type === 'image' && assetFor(el) && assetFor(el).placeholder"
                                      :title="assetFor(el) && assetFor(el).placeholder_label"
                                      style="position:absolute;left:0;top:0;padding:1px 5px;font-size:10px;font-weight:700;letter-spacing:0.04em;background:#ec4899;color:#09090b;pointer-events:none;">PLACEHOLDER</span>
                            </div>
                        </template>
                    </div>

                    {{-- Overlay: selection box, handles and snap guides sit above the page and are not clipped by it. --}}
                    <div style="position:absolute;inset:0;pointer-events:none;">
                        <template x-for="v in guides.vertical" :key="'v' + v"><div :style="guideStyle('vertical', v)"></div></template>
                        <template x-for="h in guides.horizontal" :key="'h' + h"><div :style="guideStyle('horizontal', h)"></div></template>

                        <div x-show="selectedElement()" :style="selectionStyle()">
                            <template x-for="handle in handles" :key="handle">
                                <div class="ed-handle" :data-handle="handle" :style="handleStyle(handle)" @pointerdown.stop.prevent="startResize($event, handle)"></div>
                            </template>
                            <div style="position:absolute;left:50%;top:-24px;height:24px;border-left:1px solid #ec4899;pointer-events:none;"></div>
                            <div class="ed-handle ed-rotate" data-handle="rotate" title="Rotate (Shift: 15 degree steps)" @pointerdown.stop.prevent="startRotate($event)"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Properties + AI + Assets --}}
        <div style="display:flex;flex-direction:column;gap:1.1rem;">

            {{-- Properties panel --}}
            <div class="dot-card" style="padding:1rem;" x-show="selectedElement()" x-cloak>
                <div class="ed-label">Properties</div>

                <template x-if="selectedElement()">
                    <div style="display:flex;flex-direction:column;gap:0.6rem;">
                        <template x-if="selectedElement().type === 'text'">
                            <div>
                                <label class="ed-field-label" for="ed-text">Text</label>
                                <textarea id="ed-text" x-ref="textInput" x-model="selectedElement().text" @input="scheduleSave('text:' + selectedElement().id)" class="dot-input" rows="2"></textarea>
                            </div>
                        </template>

                        <template x-if="selectedElement().type === 'image'">
                            <p style="margin:0;font-size:0.68rem;color:var(--text-dim);">
                                <span x-text="assetFor(selectedElement()) ? assetFor(selectedElement()).name : 'Image unavailable'"></span>
                                <template x-if="assetFor(selectedElement()) && assetFor(selectedElement()).placeholder"><span style="color:var(--accent);font-weight:600;"> &middot; Placeholder</span></template>
                            </p>
                        </template>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                            <div>
                                <label class="ed-field-label" for="ed-x">X</label>
                                <input id="ed-x" type="number" step="1" :value="Math.round(selectedElement().x)" @input="setNumber(selectedElement(), 'x', $event.target.value, -100000, 100000)" class="dot-input" />
                            </div>
                            <div>
                                <label class="ed-field-label" for="ed-y">Y</label>
                                <input id="ed-y" type="number" step="1" :value="Math.round(selectedElement().y)" @input="setNumber(selectedElement(), 'y', $event.target.value, -100000, 100000)" class="dot-input" />
                            </div>
                            <div>
                                <label class="ed-field-label" for="ed-w">Width</label>
                                <input id="ed-w" type="number" min="1" :value="Math.round(selectedElement().width)" @input="setNumber(selectedElement(), 'width', $event.target.value, 1, 100000)" class="dot-input" />
                            </div>
                            <div>
                                <label class="ed-field-label" for="ed-h">Height</label>
                                <input id="ed-h" type="number" min="1" :value="Math.round(selectedElement().height)" @input="setNumber(selectedElement(), 'height', $event.target.value, 1, 100000)" class="dot-input" />
                            </div>
                            <div>
                                <label class="ed-field-label" for="ed-r">Rotation (&deg;)</label>
                                <input id="ed-r" type="number" min="-360" max="360" step="1" :value="selectedElement().rotation || 0" @input="setNumber(selectedElement(), 'rotation', $event.target.value, -360, 360)" class="dot-input" />
                            </div>
                            <div>
                                <label class="ed-field-label" for="ed-o">Opacity (%)</label>
                                <input id="ed-o" type="number" min="0" max="100" step="5" :value="Math.round((selectedElement().opacity ?? 1) * 100)" @input="setNumber(selectedElement(), 'opacity', $event.target.value / 100, 0, 1)" class="dot-input" />
                            </div>
                        </div>

                        <template x-if="selectedElement().type === 'text'">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                                <div>
                                    <label class="ed-field-label" for="ed-fs">Font Size</label>
                                    <input id="ed-fs" type="number" min="1" max="400" :value="selectedElement().fontSize" @input="setNumber(selectedElement(), 'fontSize', $event.target.value, 1, 400)" class="dot-input" />
                                </div>
                                <div>
                                    <label class="ed-field-label" for="ed-c">Color</label>
                                    <input id="ed-c" type="color" x-model="selectedElement().color" @input="scheduleSave('color:' + selectedElement().id)" class="dot-input" style="padding:2px;height:34px;" />
                                </div>
                            </div>
                        </template>

                        <template x-if="selectedElement().type === 'rectangle'">
                            <div>
                                <label class="ed-field-label" for="ed-f">Fill</label>
                                <input id="ed-f" type="color" x-model="selectedElement().fill" @input="scheduleSave('fill:' + selectedElement().id)" class="dot-input" style="padding:2px;height:34px;" />
                            </div>
                        </template>

                        <div>
                            <div class="ed-field-label">Arrange</div>
                            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0.3rem;">
                                <button type="button" @click="arrange('front')" class="dot-btn dot-btn-ghost ed-icon-btn" title="Bring to front (Ctrl/Cmd+])" aria-label="Bring to front"><span class="material-symbols-rounded">flip_to_front</span></button>
                                <button type="button" @click="arrange('forward')" class="dot-btn dot-btn-ghost ed-icon-btn" title="Bring forward (])" aria-label="Bring forward"><span class="material-symbols-rounded">move_up</span></button>
                                <button type="button" @click="arrange('backward')" class="dot-btn dot-btn-ghost ed-icon-btn" title="Send backward ([)" aria-label="Send backward"><span class="material-symbols-rounded">move_down</span></button>
                                <button type="button" @click="arrange('back')" class="dot-btn dot-btn-ghost ed-icon-btn" title="Send to back (Ctrl/Cmd+[)" aria-label="Send to back"><span class="material-symbols-rounded">flip_to_back</span></button>
                                <button type="button" @click="duplicateSelected()" class="dot-btn dot-btn-ghost ed-icon-btn" title="Duplicate (Ctrl/Cmd+D)" aria-label="Duplicate"><span class="material-symbols-rounded">content_copy</span></button>
                            </div>
                        </div>

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
                    <textarea aria-label="Describe the image you want" name="prompt" required maxlength="1000" rows="3" class="dot-input" placeholder="Describe the image you want...">{{ old('prompt') }}</textarea>
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
            <div class="dot-card" style="padding:1rem;"
                 @dragover.prevent="dragOver = true"
                 @dragleave.prevent="dragOver = false"
                 @drop.prevent="dragOver = false; uploadFiles($event.dataTransfer.files)">
                <div style="display:flex;align-items:baseline;justify-content:space-between;gap:0.5rem;margin-bottom:0.6rem;">
                    <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-dim);">Assets</div>
                    <div class="metric-val" style="font-size:0.68rem;color:var(--text-dim);" :title="'Stored uploads count against a ' + formatBytes(usage.quota) + ' quota'">
                        <span x-text="formatBytes(usage.used)"></span> / <span x-text="formatBytes(usage.quota)"></span>
                    </div>
                </div>

                <div style="height:3px;border-radius:2px;background:var(--border);overflow:hidden;margin-bottom:0.75rem;" aria-hidden="true">
                    <div :style="`height:100%;width:${Math.min(100, usage.quota ? (usage.used / usage.quota) * 100 : 0)}%;background:${usage.used / usage.quota > 0.9 ? '#f87171' : 'var(--accent)'};`"></div>
                </div>

                <input type="file" x-ref="fileInput" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" style="position:absolute;width:1px;height:1px;opacity:0;" @change="uploadFiles($event.target.files); $event.target.value = ''" aria-label="Upload images" />
                <button type="button" @click="$refs.fileInput.click()" :disabled="uploading"
                        :style="`width:100%;display:flex;flex-direction:column;align-items:center;gap:0.2rem;padding:0.85rem 0.5rem;border-radius:8px;border:1px dashed ${dragOver ? 'var(--accent)' : 'var(--border-strong)'};background:${dragOver ? 'rgba(236,72,153,0.06)' : 'transparent'};color:var(--text-muted);cursor:pointer;font-family:inherit;`">
                    <span class="material-symbols-rounded" style="font-size:20px;" x-text="uploading ? 'progress_activity' : 'upload'"></span>
                    <span style="font-size:0.78rem;font-weight:600;" x-text="uploading ? 'Uploading ' + uploadingName + '…' : 'Upload images'"></span>
                    <span style="font-size:0.66rem;color:var(--text-dim);">Drop files here or click. JPG, PNG or WebP, up to {{ (int) (config('design.assets.max_kilobytes') / 1024) }} MB. SVG is not accepted.</span>
                </button>
                <p x-show="uploadError" x-text="uploadError" role="alert" style="font-size:0.7rem;color:#f87171;margin:0.5rem 0 0;"></p>
                <p x-show="uploadNote" x-text="uploadNote" role="status" style="font-size:0.7rem;color:var(--text-dim);margin:0.5rem 0 0;"></p>

                <template x-for="group in assetGroups()" :key="group.key">
                    <div x-show="group.items.length" style="margin-top:0.9rem;">
                        <div style="display:flex;justify-content:space-between;font-size:0.64rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-dim);margin-bottom:0.4rem;">
                            <span x-text="group.label"></span>
                            <span class="metric-val" x-text="group.items.length"></span>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:0.35rem;">
                            <template x-for="asset in group.items" :key="asset.id">
                                <div style="display:flex;align-items:center;gap:0.55rem;padding:0.35rem;border-radius:8px;background:rgba(127,127,127,0.06);">
                                    <img :src="asset.url" :alt="asset.name" loading="lazy" style="width:44px;height:44px;border-radius:5px;object-fit:cover;flex-shrink:0;background:var(--bg-input);" />
                                    <div style="min-width:0;flex:1;">
                                        <div x-text="asset.name" :title="asset.name" style="font-size:0.74rem;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
                                        <div style="font-size:0.64rem;color:var(--text-dim);">
                                            <template x-if="asset.placeholder"><span :title="asset.placeholder_label" style="color:var(--accent);font-weight:600;">Placeholder</span></template>
                                            <template x-if="! asset.placeholder"><span class="metric-val" x-text="assetMeta(asset)"></span></template>
                                        </div>
                                    </div>
                                    <button type="button" @click="placeAsset(asset)" class="dot-btn dot-btn-ghost" style="padding:4px 6px;" :aria-label="'Place ' + asset.name + ' on canvas'" title="Place on canvas">
                                        <span class="material-symbols-rounded" style="font-size:15px;">add_photo_alternate</span>
                                    </button>
                                    <template x-if="confirmDeleteId !== asset.id">
                                        <button type="button" @click="confirmDeleteId = asset.id" class="dot-btn dot-btn-ghost" style="padding:4px 6px;" :aria-label="'Delete ' + asset.name" title="Delete asset">
                                            <span class="material-symbols-rounded" style="font-size:15px;">delete</span>
                                        </button>
                                    </template>
                                    <template x-if="confirmDeleteId === asset.id">
                                        <div style="display:flex;gap:0.25rem;">
                                            <button type="button" @click="deleteAsset(asset)" class="dot-btn" style="padding:4px 8px;font-size:11px;background:#f87171;color:#09090b;">Delete</button>
                                            <button type="button" @click="confirmDeleteId = null" class="dot-btn dot-btn-ghost" style="padding:4px 8px;font-size:11px;">Keep</button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
                <p x-show="assets.length === 0" style="font-size:0.72rem;color:var(--text-faint);margin:0.85rem 0 0;">No assets yet. Upload an image, or generate a placeholder above.</p>
            </div>

        </div>
    </div>
    </div>

</div>

<style>
    .ed-grid { display:grid; grid-template-columns:190px minmax(360px, 1fr) 300px; gap:1.25rem; align-items:start; min-width:fit-content; }
    /* Narrow screens: stack, with the canvas first so a phone or tablet edits it without sideways scrolling. */
    @media (max-width: 1100px) { .ed-grid { grid-template-columns:minmax(0, 1fr); min-width:0; } .ed-canvas-card { order:-1; } }
    .ed-label { font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--text-dim); margin-bottom:0.6rem; }
    .ed-field-label { display:block; font-size:0.68rem; color:var(--text-dim); margin-bottom:0.25rem; }
    .ed-key { font-family:'JetBrains Mono',monospace; color:var(--text-dim); white-space:nowrap; }
    .ed-icon-btn { padding:6px 8px; justify-content:center; }
    .ed-icon-btn .material-symbols-rounded { font-size:17px; }
    .ed-icon-btn:disabled { opacity:0.35; cursor:not-allowed; }
    .ed-handle { position:absolute; width:10px; height:10px; margin:-5px 0 0 -5px; background:#fff; border:1.5px solid #ec4899; border-radius:2px; pointer-events:auto; touch-action:none; }
    .ed-rotate { left:50%; top:-24px; border-radius:50%; cursor:grab; }
    @media (pointer: coarse) { .ed-handle { width:20px; height:20px; margin:-10px 0 0 -10px; } .ed-rotate { top:-34px; } }
    @media (max-width: 640px) { .ed-root { padding:1rem 0.75rem 2rem !important; } }
    [role="button"][tabindex]:focus-visible { outline:2px solid #ec4899 !important; }
</style>

<script src="{{ asset('js/design-editor/geometry.js') }}?v={{ filemtime(public_path('js/design-editor/geometry.js')) }}"></script>
<script src="{{ asset('js/design-editor/history.js') }}?v={{ filemtime(public_path('js/design-editor/history.js')) }}"></script>
<script src="{{ asset('js/design-editor/editor.js') }}?v={{ filemtime(public_path('js/design-editor/editor.js')) }}"></script>

</x-app-layout>
