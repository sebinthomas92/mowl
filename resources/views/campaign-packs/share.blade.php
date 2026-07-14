<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $version->campaignPack->name }} · Marketing Owl</title>
    <style>
        body{margin:0;background:#f5f2ed;color:#26333c;font:15px system-ui,sans-serif;line-height:1.55}.wrap{max-width:920px;margin:48px auto;padding:36px;background:#fff;border:1px solid #e1ddd5}.k{font-size:11px;letter-spacing:.14em;color:#c96518;font-weight:700}h1,h2,h3{font-family:Georgia,serif}h1{font-size:42px;margin:10px 0}h2{margin-top:36px}.meta{color:#68747c}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.card{border:1px solid #e1ddd5;padding:18px}.rank{color:#c96518;font-size:12px;font-weight:700}.route{margin-top:18px;padding-top:18px;border-top:1px solid #e1ddd5}.pill{display:inline-block;background:#eef3f3;padding:4px 8px;margin:2px;font-size:12px}@media(max-width:700px){.wrap{margin:0;padding:24px}.grid{grid-template-columns:1fr}h1{font-size:34px}}
    </style>
</head>
<body>
<main class="wrap">
    <p class="k">MARKETING OWL · APPROVED CAMPAIGN PACK</p>
    <h1>{{ $content['product_truth']['name'] }}</h1>
    <p>{{ $content['overview']['summary'] }}</p>
    <p class="meta">Version {{ $version->version }} · approved {{ $version->reviewed_at?->format('M j, Y') }}</p>

    <h2>Product Truth</h2>
    <p><strong>{{ $content['product_truth']['price'] }}</strong> · {{ $content['product_truth']['availability'] }}</p>
    <ul>@foreach($content['product_truth']['verified_facts'] as $fact)<li>{{ $fact['statement'] }}</li>@endforeach</ul>

    <h2>Strongest angles</h2>
    <div class="grid">
        @foreach(collect($content['ranked_angles'])->take(3) as $angle)
            <article class="card"><span class="rank">#{{ $angle['rank'] }}</span><h3>{{ $angle['title'] }}</h3><p>{{ $angle['core_idea'] }}</p><small>{{ $angle['target_audience'] }} · {{ $angle['buyer_moment'] }}</small></article>
        @endforeach
    </div>

    <h2>Creative routes</h2>
    @foreach($content['creative_routes'] as $route)
        <article class="route">
            <p class="k">ROUTE {{ $loop->iteration }} · {{ $route['total_duration_seconds'] }} SECONDS</p>
            <h3>{{ $route['name'] }}</h3>
            <p>{{ $route['core_promise'] }}</p>
            <p>@foreach($route['hooks'] as $hook)<span class="pill">{{ $hook }}</span>@endforeach</p>
            <ol>@foreach($route['voiceover'] as $line)<li><strong>{{ $line['start_seconds'] }}–{{ $line['end_seconds'] }}s</strong> {{ $line['line'] }}</li>@endforeach</ol>
        </article>
    @endforeach
</main>
</body>
</html>
