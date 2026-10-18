<x-app-layout>

<div class="page">

    <div class="page-head">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text);margin:0 0 0.25rem;">
                Projects
            </h1>
            <p style="font-size:0.8rem;color:var(--text-dim);margin:0;">
                {{ $projects->count() }} project{{ $projects->count() !== 1 ? 's' : '' }}
            </p>
        </div>
        <a href="{{ route('design.projects.create') }}"
           style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#d946ef,#a21caf);font-family:'Syne',sans-serif;font-size:0.82rem;font-weight:700;color:#fff;text-decoration:none;box-shadow:0 6px 18px rgba(217,70,239,0.3);">
            <span class="material-symbols-rounded" style="font-size:18px;">add_circle</span>
            New Project
        </a>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.5rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(74,222,128,0.12);border:1px solid rgba(74,222,128,0.3);color:#4ade80;font-size:0.82rem;">
        {{ session('status') }}
    </div>
    @endif

    @if($projects->isEmpty())
    <div class="dot-card" style="border-style:dashed;padding:3rem;text-align:center;">
        <span class="material-symbols-rounded" style="font-size:40px;color:var(--text-dim);display:block;margin-bottom:0.75rem;">folder_open</span>
        <p style="color:var(--text-dim);font-size:0.85rem;margin:0 0 1rem;">No projects yet. Create your first design project.</p>
        <a href="{{ route('design.projects.create') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.1rem;background:rgba(217,70,239,0.15);border:1px solid rgba(217,70,239,0.3);border-radius:9999px;font-size:0.78rem;font-weight:600;color:#f0abfc;text-decoration:none;">
            <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
            New Project
        </a>
    </div>
    @else
    <div class="card-grid">
        @foreach($projects as $project)
        @php
            $typeColors = [
                'graphic'      => ['bg'=>'rgba(217,70,239,0.15)','color'=>'#f0abfc','label'=>'Graphic'],
                'presentation' => ['bg'=>'rgba(96,165,250,0.15)','color'=>'#93c5fd','label'=>'Presentation'],
                'social'       => ['bg'=>'rgba(251,146,60,0.15)','color'=>'#fdba74','label'=>'Social'],
                'banner'       => ['bg'=>'rgba(167,139,250,0.15)','color'=>'#c4b5fd','label'=>'Banner'],
                'poster'       => ['bg'=>'rgba(34,197,94,0.15)','color'=>'#86efac','label'=>'Poster'],
                'logo'         => ['bg'=>'rgba(251,191,36,0.15)','color'=>'#fcd34d','label'=>'Logo'],
            ];
            $tc = $typeColors[$project->type] ?? ['bg'=>'rgba(141,144,162,0.15)','color'=>'#b7c8e1','label'=>ucfirst($project->type)];
        @endphp
        <div class="dot-card" style="padding:1.25rem;display:flex;flex-direction:column;gap:0.9rem;">
            <div style="height:120px;border-radius:8px;background:rgba(127,127,127,0.06);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;">
                <span class="material-symbols-rounded" style="font-size:36px;color:var(--text-faint);">palette</span>
                <div style="position:absolute;top:0.5rem;right:0.5rem;">
                    <span style="padding:0.25rem 0.55rem;border-radius:9999px;font-size:0.62rem;font-weight:700;background:{{ $tc['bg'] }};color:{{ $tc['color'] }};text-transform:uppercase;letter-spacing:0.08em;">
                        {{ $tc['label'] }}
                    </span>
                </div>
            </div>

            <div style="flex:1;">
                <div style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:var(--text);margin-bottom:0.3rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    {{ $project->name }}
                </div>
                <div style="display:flex;align-items:center;gap:1rem;font-size:0.7rem;color:var(--text-dim);">
                    <span>{{ $project->width }}&times;{{ $project->height }} {{ $project->unit }}</span>
                    <span style="display:flex;align-items:center;gap:0.25rem;">
                        <span class="material-symbols-rounded" style="font-size:13px;">layers</span>
                        {{ $project->canvases_count }} {{ Str::plural('page', $project->canvases_count) }}
                    </span>
                </div>
            </div>

            <div style="display:flex;gap:0.5rem;">
                <a href="{{ route('design.projects.show', $project) }}" class="dot-btn dot-btn-primary" style="flex:1;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:15px;">open_in_new</span>
                    Open
                </a>
                <form method="POST" action="{{ route('design.projects.destroy', $project) }}" onsubmit="return confirm('Delete this project and all its pages?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="dot-btn dot-btn-ghost" title="Delete" aria-label="Delete project {{ $project->name }}">
                        <span class="material-symbols-rounded" style="font-size:15px;">delete</span>
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
    @endif

</div>

</x-app-layout>
