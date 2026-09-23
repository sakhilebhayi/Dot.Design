<x-app-layout>

<div style="padding:2rem 2.5rem;max-width:640px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text);margin:0 0 0.25rem;">
            New Project
        </h1>
        <p style="font-size:0.8rem;color:var(--text-dim);margin:0;">Set up a canvas to start designing.</p>
    </div>

    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.75rem 1rem;border-radius:0.6rem;background:rgba(248,113,113,0.12);border:1px solid rgba(248,113,113,0.3);color:#f87171;font-size:0.82rem;">
        <ul style="margin:0;padding-left:1.1rem;">
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('design.projects.store') }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-muted);margin-bottom:0.35rem;">Project Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="255" class="dot-input" placeholder="e.g. Summer Sale Instagram Post" />
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-muted);margin-bottom:0.35rem;">Type</label>
            <select name="type" required class="dot-input">
                @foreach($types as $type)
                <option value="{{ $type }}" @selected(old('type', 'graphic') === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-muted);margin-bottom:0.35rem;">Width</label>
                <input type="number" name="width" min="1" max="10000" value="{{ old('width', 1200) }}" required class="dot-input" />
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-muted);margin-bottom:0.35rem;">Height</label>
                <input type="number" name="height" min="1" max="10000" value="{{ old('height', 628) }}" required class="dot-input" />
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:600;color:var(--text-muted);margin-bottom:0.35rem;">Unit</label>
                <select name="unit" required class="dot-input">
                    @foreach(['px', 'in', 'cm', 'mm'] as $unit)
                    <option value="{{ $unit }}" @selected(old('unit', 'px') === $unit)>{{ $unit }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Create Project</button>
            <a href="{{ route('design.projects.index') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>

</div>

</x-app-layout>
