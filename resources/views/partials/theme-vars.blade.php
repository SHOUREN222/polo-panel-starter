@php
    $themeColor = \App\Models\Setting::get('theme_primary_color', '#0d9488');
    $themeFontSize = match (\App\Models\Setting::get('theme_font_size', 'normal')) {
        'small' => '14px',
        'large' => '18px',
        default => '16px',
    };
@endphp
<style>
    html { font-size: {{ $themeFontSize }}; }
    :root {
        --brand: {{ $themeColor }};
        --brand-dark: color-mix(in srgb, {{ $themeColor }} 70%, black);
        --brand-soft: color-mix(in srgb, {{ $themeColor }} 10%, white);
    }
    .dark {
        --brand-soft: color-mix(in srgb, {{ $themeColor }} 18%, black);
    }
</style>
