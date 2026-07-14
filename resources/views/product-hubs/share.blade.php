<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product->name }} — Marketing Owl</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @php($isPublic = true)
    @php($shareToken = $share->token)
    @php($workspace = null)
    @php($sections = ['overview' => 'Overview', 'product_details' => 'Product details', 'key_messaging' => 'Key messaging', 'meta_ads' => 'Meta Ads', 'google_ads' => 'Google Ads', 'email_sms' => 'Email & SMS', 'organic_social' => 'Organic social', 'asset_links' => 'Asset links', 'campaign_history' => 'Campaign history'])
    @php($resourceLabels = [])
    @php($canManage = false)
    <div class="product-hub-shell public" x-data="{ section: 'google_ads', campaign: 'search', copied: null, mobileNav: false, async copy(value, key) { await navigator.clipboard.writeText(value); this.copied = key; setTimeout(() => { if (this.copied === key) this.copied = null }, 1600) } }">
        <a class="skip-link" href="#product-hub-content">Skip to product hub</a>
        @include('product-hubs.surface')
    </div>
</body>
</html>
