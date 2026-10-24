<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f7f7f7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#111110" media="(prefers-color-scheme: dark)">
    <title>{{ $product->name }} · {{ $product->brand->name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Fraunces:opsz,wght@9..144,300..600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="client-page">
        <a class="skip-link" href="#main-content">Skip to the hub</a>
        <header class="client-head">
            <svg width="30" height="20" viewBox="0 0 30 20" aria-hidden="true"><circle cx="9" cy="10" r="8" fill="#FF5A3D"/><circle cx="21" cy="10" r="8" fill="#EA38D0" opacity=".85"/><circle cx="9" cy="10" r="2.5" fill="#fff"/><circle cx="21" cy="10" r="2.5" fill="#fff"/></svg>
            <div class="grow">
                <strong class="medium">{{ $product->brand->name }} · client hub</strong><br>
                <span class="muted xs">Shared by {{ $product->brand->workspace->name }}</span>
            </div>
            <span class="tag"><i class="ti ti-lock" aria-hidden="true"></i>View only</span>
        </header>
        <main class="client-main" id="main-content">
            <p class="muted">Everything on this page is approved and ready to use. Copy any line, download the images, or open the linked files.</p>
            @include('client-hub.body', ['hub' => $hub, 'heading' => 'h1'])
            <p class="muted xs">This page only shows approved work. It updates when {{ $product->brand->workspace->name }} approves a new version.</p>
        </main>
    </div>
    @livewireScripts
</body>
</html>
