# Canvas Editor

> Technical and feature specification for the Dot.Design canvas editor. Implement each section as a standalone Livewire component or Alpine.js module.

---

## 1. Technology Choice

The canvas editor requires pixel-precise rendering, hit detection, and high-frequency DOM updates. A standard Livewire component handles too many round-trips for interactive drawing.

**Recommended hybrid:**
- **Livewire** owns persistence (save, load, history, export).
- **Alpine.js + Fabric.js** owns the canvas rendering and interaction loop.

```bash
npm install fabric
```

Fabric.js provides a mature canvas API, element model, serialisation, and undo/redo primitives.

---

## 2. Data Model Mapping

`DesignCanvas.elements` (JSON column) maps to a Fabric.js JSON object:

```json
{
  "version": "6.x",
  "background": "#ffffff",
  "objects": [
    {
      "type": "textbox",
      "id": "el_01",
      "left": 100,
      "top": 80,
      "width": 400,
      "text": "Hello World",
      "fontSize": 48,
      "fontFamily": "Figtree",
      "fill": "#111827"
    },
    {
      "type": "rect",
      "id": "el_02",
      "left": 50,
      "top": 50,
      "width": 200,
      "height": 120,
      "fill": "#4f6ef7",
      "rx": 8
    }
  ]
}
```

Store the entire `fabric.Canvas#toJSON()` output in `design_canvases.elements`.

---

## 3. Livewire Host Component

```php
// app/Livewire/Canvas/CanvasEditor.php
class CanvasEditor extends Component
{
    public DesignCanvas $canvas;
    public bool $saved = false;

    #[Locked]
    public int $projectId;

    public function mount(int $canvasId): void
    {
        $this->canvas = DesignCanvas::with('project')->findOrFail($canvasId);
        $this->authorize('update', $this->canvas->project);
    }

    public function save(array $elements, string $backgroundColor): void
    {
        $this->canvas->update([
            'elements'         => $elements,
            'background_color' => $backgroundColor,
        ]);

        $this->dispatch('canvas-saved');
        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.canvas.canvas-editor');
    }
}
```

---

## 4. Alpine.js Canvas Module

```js
// resources/js/canvas/editor.js
import { fabric } from 'fabric';

export default function canvasEditor(initialData, canvasId) {
    return {
        canvas: null,
        history: [],
        historyIndex: -1,
        dirty: false,
        saving: false,
        saved: false,

        init() {
            this.canvas = new fabric.Canvas(canvasId, {
                width:  initialData.width,
                height: initialData.height,
                backgroundColor: initialData.background,
                preserveObjectStacking: true,
            });

            if (initialData.elements) {
                this.canvas.loadFromJSON(initialData.elements, () => {
                    this.canvas.renderAll();
                    this.snapshot();
                });
            } else {
                this.snapshot();
            }

            this.canvas.on('object:modified', () => this.onMutate());
            this.canvas.on('object:added',    () => this.onMutate());
            this.canvas.on('object:removed',  () => this.onMutate());

            this.bindKeyboard();
        },

        onMutate() {
            this.dirty = true;
            this.snapshot();
            this.autoSave();
        },

        snapshot() {
            const state = this.canvas.toJSON(['id']);
            this.history = this.history.slice(0, this.historyIndex + 1);
            this.history.push(state);
            if (this.history.length > 50) this.history.shift();
            this.historyIndex = this.history.length - 1;
        },

        undo() {
            if (this.historyIndex <= 0) return;
            this.historyIndex--;
            this.canvas.loadFromJSON(this.history[this.historyIndex], () => this.canvas.renderAll());
        },

        redo() {
            if (this.historyIndex >= this.history.length - 1) return;
            this.historyIndex++;
            this.canvas.loadFromJSON(this.history[this.historyIndex], () => this.canvas.renderAll());
        },

        autoSave: Alpine.debounce(function () {
            this.persist();
        }, 8000),

        async persist() {
            this.saving = true;
            await this.$wire.save(this.canvas.toJSON(['id']), this.canvas.backgroundColor);
            this.saving = false;
            this.saved  = true;
            this.dirty  = false;
            setTimeout(() => this.saved = false, 2000);
        },

        bindKeyboard() {
            document.addEventListener('keydown', (e) => {
                const ctrl = e.metaKey || e.ctrlKey;
                if (ctrl && e.key === 'z' && !e.shiftKey) { e.preventDefault(); this.undo(); }
                if (ctrl && e.key === 'z' &&  e.shiftKey) { e.preventDefault(); this.redo(); }
                if (ctrl && e.key === 'd') { e.preventDefault(); this.duplicate(); }
                if (e.key === 'Delete' || e.key === 'Backspace') {
                    if (document.activeElement.tagName !== 'INPUT') this.deleteSelected();
                }
            });
        },

        duplicate() {
            const obj = this.canvas.getActiveObject();
            if (!obj) return;
            obj.clone((cloned) => {
                cloned.set({ left: obj.left + 20, top: obj.top + 20 });
                this.canvas.add(cloned);
                this.canvas.setActiveObject(cloned);
            });
        },

        deleteSelected() {
            const active = this.canvas.getActiveObjects();
            active.forEach(obj => this.canvas.remove(obj));
            this.canvas.discardActiveObject();
            this.canvas.renderAll();
        },
    };
}
```

---

## 5. Element Tools

### 5a. Text Tool

```js
addText() {
    const text = new fabric.Textbox('Edit me', {
        left:       100,
        top:        100,
        width:      300,
        fontSize:   32,
        fontFamily: 'Figtree',
        fill:       '#111827',
        id:         crypto.randomUUID(),
    });
    this.canvas.add(text);
    this.canvas.setActiveObject(text);
    text.enterEditing();
}
```

### 5b. Shape Tool

```js
addRect(options = {}) {
    const shape = new fabric.Rect({
        left:   100,
        top:    100,
        width:  200,
        height: 150,
        fill:   '#4f6ef7',
        rx:     8,
        id:     crypto.randomUUID(),
        ...options,
    });
    this.canvas.add(shape);
    this.canvas.setActiveObject(shape);
}
```

### 5c. Image Tool

```js
async addImage(url) {
    return new Promise((resolve) => {
        fabric.Image.fromURL(url, (img) => {
            img.set({ left: 100, top: 100, id: crypto.randomUUID() });
            img.scaleToWidth(300);
            this.canvas.add(img);
            this.canvas.setActiveObject(img);
            resolve(img);
        }, { crossOrigin: 'anonymous' });
    });
}
```

---

## 6. Layers Panel

The layers panel mirrors the Fabric.js object stack. Drag-to-reorder maps to `canvas.moveTo(obj, index)`.

```js
get layers() {
    return [...this.canvas.getObjects()].reverse().map((obj, i) => ({
        id:      obj.id,
        type:    obj.type,
        label:   obj.text ?? obj.type,
        visible: obj.visible !== false,
        locked:  obj.selectable === false,
        index:   i,
    }));
},

toggleVisibility(id) {
    const obj = this.canvas.getObjects().find(o => o.id === id);
    if (obj) { obj.visible = !obj.visible; this.canvas.renderAll(); }
},

toggleLock(id) {
    const obj = this.canvas.getObjects().find(o => o.id === id);
    if (obj) {
        obj.selectable = !obj.selectable;
        obj.evented    = obj.selectable;
        this.canvas.renderAll();
    }
},
```

---

## 7. Properties Panel

Show contextual controls based on the selected object type:

| Object type | Controls shown |
|---|---|
| `textbox` | Font family, size, weight, colour, alignment, line height, letter spacing |
| `rect` / `circle` | Fill colour, stroke, stroke width, border radius, opacity |
| `image` | Opacity, flip H/V, crop (future), filters (brightness, contrast) |
| Any | X, Y position, W, H dimensions, rotation, Z-index (layer order) |

Bind property panel inputs directly to `canvas.getActiveObject()` properties and call `canvas.renderAll()` on change.

---

## 8. Zoom & Pan

```js
initZoom() {
    this.canvas.on('mouse:wheel', (opt) => {
        const delta  = opt.e.deltaY;
        let zoom = this.canvas.getZoom();
        zoom *= 0.999 ** delta;
        zoom = Math.min(Math.max(zoom, 0.1), 10);
        this.canvas.zoomToPoint({ x: opt.e.offsetX, y: opt.e.offsetY }, zoom);
        opt.e.preventDefault();
        opt.e.stopPropagation();
    });

    // Spacebar + drag = pan
    let isPanning = false;
    this.canvas.on('mouse:down', (opt) => {
        if (this.activeTool === 'pan') {
            isPanning = true;
            this.canvas.selection = false;
        }
    });
    this.canvas.on('mouse:move', (opt) => {
        if (isPanning && opt.e.buttons === 1) {
            const vpt = this.canvas.viewportTransform;
            vpt[4] += opt.e.movementX;
            vpt[5] += opt.e.movementY;
            this.canvas.requestRenderAll();
        }
    });
    this.canvas.on('mouse:up', () => {
        isPanning = false;
        this.canvas.selection = true;
    });
},
```

---

## 9. Multi-Page Support

`DesignProject` hasMany `DesignCanvas` (one per page). The editor toolbar shows page tabs.

```php
// Livewire: switch page
public function switchPage(int $pageNumber): void
{
    // Save current canvas first
    $this->persist();

    $this->canvas = DesignCanvas::firstOrCreate(
        ['design_project_id' => $this->projectId, 'page_number' => $pageNumber],
        ['elements' => null, 'background_color' => '#ffffff'],
    );

    $this->dispatch('load-canvas', elements: $this->canvas->elements);
}
```

---

## 10. Export Pipeline

```php
// app/Jobs/ExportDesignJob.php
class ExportDesignJob implements ShouldQueue
{
    public function __construct(
        public readonly DesignCanvas $canvas,
        public readonly string $format,   // png|jpeg|svg|pdf
        public readonly int    $scale,    // 1|2|4
        public readonly User   $user,
    ) {}

    public function handle(ExportService $service): void
    {
        $path = $service->export($this->canvas, $this->format, $this->scale);

        $url = Storage::temporaryUrl($path, now()->addHour());

        Notification::send($this->user, new DesignExportReady($url, $this->format));
    }
}
```

Dispatch from Livewire:
```php
ExportDesignJob::dispatch($this->canvas, $format, $scale, auth()->user());
```

---

## 11. Accessibility in the Editor

- All toolbar buttons have `aria-label` and `title`.
- Keyboard-focusable tool buttons with visible `:focus-visible` ring.
- Selected element's type and position announced via an `aria-live` region.
- Colour picker includes a hex input field alongside the visual picker.
- Panel inputs have associated `<label>` elements.
