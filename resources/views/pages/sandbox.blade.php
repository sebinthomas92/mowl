<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $page?->name ?? $store->name }} · Sandbox store</title>
    <style>
        body{margin:0;background:#F7F7F7;color:#0F0F0F;font:16px/1.6 system-ui,-apple-system,'Segoe UI',sans-serif}
        .sandbox-bar{background:#0F0F0F;color:#fff;font-size:13px;padding:10px 20px;display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between}
        .sandbox-bar a{color:#CFCFCF}
        main{max-width:1000px;margin:0 auto;padding:40px 20px}
        h1,h2{line-height:1.15}
    </style>
</head>
<body>
    <div class="sandbox-bar"><span>{{ $store->name }} · sandbox store. Not a real shop; MarketingOwl hosts this page so you can try publishing.</span><a href="{{ route('sandbox-store.home', $store->api_base) }}">All pages</a></div>
    <main>
        @if($html !== null)
            {!! $html !!}
        @else
            <h1>{{ $store->name }}</h1>
            @forelse($pages as $listed)
                <p><a href="{{ $listed->remote_url }}">{{ $listed->name }}</a></p>
            @empty
                <p>Nothing has been published here yet.</p>
            @endforelse
        @endif
    </main>
</body>
</html>
