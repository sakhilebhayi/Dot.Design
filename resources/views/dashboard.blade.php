<x-app-layout>

<div style="padding:2rem 2.5rem;max-width:1400px;">

    {{-- ------------------------------------------------------------------ --}}
    {{-- Header                                                              --}}
    {{-- ------------------------------------------------------------------ --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">
                Design Studio Dashboard
            </h1>
            <p style="font-size:0.8rem;color:#71717a;margin:0;">
                {{ now()->format('l, F j, Y') }} &mdash; Welcome back, {{ Auth::user()->name }}
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

    {{-- ------------------------------------------------------------------ --}}
    {{-- KPI Cards                                                           --}}
    {{-- ------------------------------------------------------------------ --}}
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:2.25rem;">

        {{-- Total Projects --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:1.35rem 1.25rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Total Projects</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(217,70,239,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#d946ef;">folder_open</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#f0abfc;line-height:1;">{{ $totalProjects }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">all time</div>
        </div>

        {{-- Recent (30d) --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:1.35rem 1.25rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Recent</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(96,165,250,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#60a5fa;">trending_up</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#93c5fd;line-height:1;">{{ $recentProjects }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">last 30 days</div>
        </div>

        {{-- Canvases --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:1.35rem 1.25rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Canvases</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(167,139,250,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#a78bfa;">draw</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#c4b5fd;line-height:1;">{{ $totalCanvases }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">across all projects</div>
        </div>

        {{-- Assets --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:1.35rem 1.25rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">Assets</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(251,146,60,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#fb923c;">photo_library</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#fdba74;line-height:1;">{{ $totalAssets }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">images, fonts &amp; more</div>
        </div>

        {{-- AI Generations --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:1.35rem 1.25rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#71717a;">AI Generations</span>
                <div style="width:34px;height:34px;border-radius:9px;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#22c55e;">auto_awesome</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#86efac;line-height:1;">{{ $aiGenerations }}</div>
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.35rem;">prompts run</div>
        </div>

    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Projects Grid                                                       --}}
    {{-- ------------------------------------------------------------------ --}}
    <div style="margin-bottom:2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
            <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin:0;">
                Your Projects
            </h2>
            <a href="{{ route('design.projects.index') }}" style="font-size:0.75rem;color:#d946ef;text-decoration:none;font-weight:600;">View all &rarr;</a>
        </div>

        @if($projects->isEmpty())
        <div style="background:#141416;border:1px dashed rgba(67,70,86,0.4);border-radius:14px;padding:3rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:40px;color:#71717a;display:block;margin-bottom:0.75rem;">folder_open</span>
            <p style="color:#71717a;font-size:0.85rem;margin:0 0 1rem;">No projects yet. Create your first design project.</p>
            <a href="{{ route('design.projects.create') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.55rem 1.1rem;background:rgba(217,70,239,0.15);border:1px solid rgba(217,70,239,0.3);border-radius:9999px;font-size:0.78rem;font-weight:600;color:#f0abfc;text-decoration:none;">
                <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                New Project
            </a>
        </div>
        @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;">
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
            <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:1.25rem;display:flex;flex-direction:column;gap:0.9rem;transition:border-color 0.2s;"
                 onmouseover="this.style.borderColor='rgba(217,70,239,0.35)'"
                 onmouseout="this.style.borderColor='rgba(255,255,255,0.07)'">

                {{-- Canvas preview placeholder --}}
                <div style="height:120px;border-radius:8px;background:rgba(11,19,38,0.8);border:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;">
                    <span class="material-symbols-rounded" style="font-size:36px;color:rgba(141,144,162,0.3);">palette</span>
                    <div style="position:absolute;top:0.5rem;right:0.5rem;">
                        <span style="padding:0.25rem 0.55rem;border-radius:9999px;font-size:0.62rem;font-weight:700;background:{{ $tc['bg'] }};color:{{ $tc['color'] }};text-transform:uppercase;letter-spacing:0.08em;">
                            {{ $tc['label'] }}
                        </span>
                    </div>
                </div>

                <div style="flex:1;">
                    <div style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:700;color:#f4f4f5;margin-bottom:0.3rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        {{ $project->name }}
                    </div>
                    <div style="display:flex;align-items:center;gap:1rem;font-size:0.7rem;color:#71717a;">
                        <span>{{ $project->width }}&times;{{ $project->height }} {{ $project->unit }}</span>
                        <span style="display:flex;align-items:center;gap:0.25rem;">
                            <span class="material-symbols-rounded" style="font-size:13px;">layers</span>
                            {{ $project->canvases_count }} {{ Str::plural('canvas', $project->canvases_count) }}
                        </span>
                    </div>
                    <div style="font-size:0.65rem;color:#71717a;margin-top:0.2rem;">
                        Updated {{ $project->updated_at->diffForHumans() }}
                    </div>
                </div>

                <a href="{{ route('design.projects.show', $project) }}"
                   style="display:flex;align-items:center;justify-content:center;gap:0.4rem;padding:0.55rem;border-radius:8px;background:rgba(217,70,239,0.1);border:1px solid rgba(217,70,239,0.2);font-size:0.78rem;font-weight:600;color:#f0abfc;text-decoration:none;transition:background 0.2s;"
                   onmouseover="this.style.background='rgba(217,70,239,0.2)'"
                   onmouseout="this.style.background='rgba(217,70,239,0.1)'">
                    <span class="material-symbols-rounded" style="font-size:15px;">open_in_new</span>
                    Open
                </a>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- Recent Assets                                                       --}}
    {{-- ------------------------------------------------------------------ --}}
    <div style="margin-bottom:2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
            <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin:0;">
                Recent Assets
            </h2>
        </div>

        @if($recentAssets->isEmpty())
        <div style="background:#141416;border:1px dashed rgba(67,70,86,0.4);border-radius:14px;padding:2rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:36px;color:#71717a;display:block;margin-bottom:0.5rem;">photo_library</span>
            <p style="color:#71717a;font-size:0.82rem;margin:0;">No assets yet. Direct uploads are not available.</p>
        </div>
        @else
        <div style="display:flex;gap:0.85rem;overflow-x:auto;padding-bottom:0.5rem;scrollbar-width:thin;scrollbar-color:rgba(67,70,86,0.4) transparent;">
            @foreach($recentAssets as $asset)
            @php
                $assetBadge = [
                    'image'     => ['bg'=>'rgba(96,165,250,0.15)','color'=>'#93c5fd','icon'=>'image'],
                    'icon'      => ['bg'=>'rgba(251,191,36,0.15)','color'=>'#fcd34d','icon'=>'star'],
                    'font'      => ['bg'=>'rgba(167,139,250,0.15)','color'=>'#c4b5fd','icon'=>'text_fields'],
                    'template'  => ['bg'=>'rgba(34,197,94,0.15)','color'=>'#86efac','icon'=>'dashboard_customize'],
                    'component' => ['bg'=>'rgba(217,70,239,0.15)','color'=>'#f0abfc','icon'=>'widgets'],
                ];
                $ab = $assetBadge[$asset->type] ?? ['bg'=>'rgba(141,144,162,0.15)','color'=>'#b7c8e1','icon'=>'insert_drive_file'];
                $sizeLabel = $asset->file_size
                    ? ($asset->file_size >= 1048576
                        ? number_format($asset->file_size / 1048576, 1).' MB'
                        : number_format($asset->file_size / 1024, 0).' KB')
                    : '—';
            @endphp
            <div style="flex:0 0 180px;background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:1rem;transition:border-color 0.2s;"
                 onmouseover="this.style.borderColor='rgba(217,70,239,0.3)'"
                 onmouseout="this.style.borderColor='rgba(255,255,255,0.07)'">
                <div style="height:80px;border-radius:8px;background:rgba(11,19,38,0.8);display:flex;align-items:center;justify-content:center;margin-bottom:0.75rem;">
                    <span class="material-symbols-rounded" style="font-size:28px;color:{{ $ab['color'] }};">{{ $ab['icon'] }}</span>
                </div>
                <div style="font-size:0.75rem;font-weight:600;color:#f4f4f5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:0.35rem;">
                    {{ $asset->name }}
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <span style="padding:0.2rem 0.5rem;border-radius:9999px;font-size:0.58rem;font-weight:700;background:{{ $ab['bg'] }};color:{{ $ab['color'] }};text-transform:uppercase;letter-spacing:0.07em;">
                        {{ $asset->type }}
                    </span>
                    <span style="font-size:0.62rem;color:#71717a;">{{ $sizeLabel }}</span>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- ------------------------------------------------------------------ --}}
    {{-- AI Generation Log                                                   --}}
    {{-- ------------------------------------------------------------------ --}}
    <div style="margin-bottom:1.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem;">
            <h2 style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin:0;">
                AI Generation Activity
            </h2>
        </div>

        <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:14px;padding:1.5rem;">
            @if($aiGenerations === 0)
            <div style="text-align:center;padding:1.5rem 0;">
                <span class="material-symbols-rounded" style="font-size:36px;color:#71717a;display:block;margin-bottom:0.5rem;">auto_awesome</span>
                <p style="color:#71717a;font-size:0.82rem;margin:0;">No AI generations yet. Try the AI Generate tool.</p>
            </div>
            @else
            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1.25rem;">
                <span class="material-symbols-rounded" style="font-size:18px;color:#22c55e;">auto_awesome</span>
                <span style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#86efac;">
                    {{ $aiGenerations }} total {{ Str::plural('generation', $aiGenerations) }}
                </span>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:0.75rem;">
                @php
                    $providerColors = [
                        'anthropic' => ['bg'=>'rgba(217,70,239,0.15)','color'=>'#f0abfc','icon'=>'auto_awesome'],
                        'openai'    => ['bg'=>'rgba(34,197,94,0.15)','color'=>'#86efac','icon'=>'smart_toy'],
                        'stability' => ['bg'=>'rgba(96,165,250,0.15)','color'=>'#93c5fd','icon'=>'image'],
                        'replicate' => ['bg'=>'rgba(251,146,60,0.15)','color'=>'#fdba74','icon'=>'model_training'],
                    ];
                @endphp
                @foreach($generationsByProvider as $provider => $count)
                @php
                    $pc = $providerColors[$provider] ?? ['bg'=>'rgba(141,144,162,0.15)','color'=>'#b7c8e1','icon'=>'psychology'];
                @endphp
                <div style="display:flex;align-items:center;gap:0.65rem;padding:0.75rem 1.15rem;background:{{ $pc['bg'] }};border:1px solid {{ $pc['color'] }}22;border-radius:10px;min-width:140px;">
                    <div style="width:30px;height:30px;border-radius:7px;background:rgba(0,0,0,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span class="material-symbols-rounded" style="font-size:16px;color:{{ $pc['color'] }};">{{ $pc['icon'] }}</span>
                    </div>
                    <div>
                        <div style="font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:800;color:{{ $pc['color'] }};line-height:1;">{{ $count }}</div>
                        <div style="font-size:0.62rem;color:#71717a;margin-top:0.1rem;{{ $provider === 'mock' ? '' : 'text-transform:capitalize;' }}">{{ $provider === 'mock' ? \App\Services\Ai\AiImageResult::PLACEHOLDER_LABEL : $provider }}</div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>

</div>

</x-app-layout>
