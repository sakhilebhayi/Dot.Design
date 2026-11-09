<x-app-layout>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Asset library</h1>
                <p>{{ \App\Services\Assets\AssetUploader::formatBytes($usage['used']) }} of {{ \App\Services\Assets\AssetUploader::formatBytes($usage['quota']) }} used</p>
            </div>
        </div>

        <form method="get" action="{{ route('design.assets.index') }}" class="dot-card">
            <label>Search <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100"></label>
            <label>Folder
                <select name="folder">
                    <option value="">All folders</option>
                    @foreach ($folders as $folder)
                        <option value="{{ $folder['name'] }}" @selected(($filters['folder'] ?? '') === $folder['name'])>{{ $folder['name'] }} ({{ $folder['count'] }})</option>
                    @endforeach
                </select>
            </label>
            <label>Tag <input type="text" name="tag" value="{{ $filters['tag'] ?? '' }}" maxlength="30"></label>
            <button type="submit">Filter</button>
        </form>

        @if (count($assets) === 0)
            <p>No pictures match. Upload one from the editor.</p>
        @else
            <ul>
                @foreach ($assets as $asset)
                    <li>
                        <strong>{{ $asset['name'] }}</strong>
                        @if ($asset['folder']) <span>in {{ $asset['folder'] }}</span> @endif
                        @foreach ($asset['tags'] as $tag) <span>#{{ $tag }}</span> @endforeach
                        <span>{{ $asset['width'] }} x {{ $asset['height'] }}</span>
                        <span>{{ $asset['license'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($nextCursor)
            <a href="{{ route('design.assets.index', array_filter($filters + ['cursor' => $nextCursor])) }}">More</a>
        @endif
    </div>
</x-app-layout>
