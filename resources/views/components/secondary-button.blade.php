<button {{ $attributes->merge(['type' => 'button', 'class' => 'press inline-flex items-center px-7 py-3.5 text-[var(--ink)] font-medium rounded-full border border-[var(--line)] hover:border-[var(--periwinkle-soft)] transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--gold)] focus:ring-offset-2 disabled:opacity-25']) }}>
    {{ $slot }}
</button>
