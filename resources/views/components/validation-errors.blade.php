@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'lt-alert lt-alert--bad']) }} role="alert">
        <x-ds.icon name="circle-alert" />
        <div>
            <strong>{{ __('Something needs fixing.') }}</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
