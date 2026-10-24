<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Preview</title>
    <style>
        *,*::before,*::after{box-sizing:border-box}
        body{margin:0;background:#fff;color:#111;font:16px/1.6 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;-webkit-font-smoothing:antialiased}
        img{max-width:100%;height:auto}
        .preview-bar{position:sticky;top:0;z-index:10;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;padding:10px 20px;background:#0f0f0f;color:#fff;font-size:13px;line-height:1.4}
        .preview-bar a{color:#ffb4a3}
        main.legacy{max-width:1000px;margin:0 auto;padding:40px 20px}
    </style>
</head>
<body>
    @if($bar)
        <div class="preview-bar"><span>{{ $label }}</span>@if($back)<a href="{{ $back }}">{{ $backLabel }}</a>@endif</div>
    @endif
    {{-- Pages from the editor are full width with their own spacing; older pages sit in a column. --}}
    <main @class(['legacy' => ! str_contains($html, 'mowl-page alignfull')])>{!! $html !!}</main>
</body>
</html>
