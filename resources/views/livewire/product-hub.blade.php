@php($isPublic = false)
@php($shareToken = null)
<div class="product-hub-shell" x-data="productHub()">
    <a class="skip-link" href="#product-hub-content">Skip to product hub</a>
    @include('partials.workspace-sidebar')
    @include('product-hubs.surface')
</div>

@script
<script>
    Alpine.data('productHub', () => ({
        section: 'google_ads', campaign: 'search', copied: null, mobileNav: false,
        async copy(value, key) {
            await navigator.clipboard.writeText(value);
            this.copied = key;
            setTimeout(() => { if (this.copied === key) this.copied = null }, 1600);
        }
    }))
</script>
@endscript
