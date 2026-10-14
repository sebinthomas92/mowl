<section class="card connections-card" id="connections" aria-labelledby="connections-heading">
    <div class="row"><div class="grow"><span class="eyebrow">Connections</span><h2 id="connections-heading" class="display">Stores</h2></div>
        @if($isOwner && ! $formOpen)<button type="button" class="pill ghost sm" wire:click="open"><i class="ti ti-plus" aria-hidden="true"></i> Connect a store</button>@endif
    </div>
    <p class="small muted">Pages publish to these stores. A publish only counts as live once the page address serves it.</p>
    @if($flash)<div class="banner ok" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>@endif

    @forelse($stores as $store)
        <div class="li" wire:key="store-{{ $store->id }}">
            <span class="ic"><i class="ti {{ $store->driver === 'sandbox' ? 'ti-flask' : 'ti-building-store' }}" aria-hidden="true"></i></span>
            <span class="grow">
                <strong class="medium">{{ $store->name }}@if($store->name !== $store->label()) · {{ $store->label() }}@endif</strong><br>
                <span class="muted xs break">{{ $store->driver === 'sandbox' ? 'Test store hosted by MarketingOwl. Nothing reaches a real shop.' : $store->site_url }}@if($store->brand) · {{ $store->brand->name }} only @endif · {{ trans_choice(':count page|:count pages', $store->pages_count) }}</span>
                @if(! $store->isConnected() && $store->last_error)<br><span class="xs warn-text">{{ $store->last_error }}</span>@endif
            </span>
            @if($store->isConnected())<span class="tag ok"><span class="dot"></span>Connected</span>@else<span class="tag warn"><span class="dot"></span>Needs attention</span>@endif
            @if($isOwner)
                <button type="button" class="pill ghost sm" wire:click="check({{ $store->id }})" wire:loading.attr="disabled" wire:target="check({{ $store->id }})">Check</button>
                @if($store->driver === 'woocommerce')<button type="button" class="pill ghost sm" wire:click="open({{ $store->id }})">{{ $store->isConnected() ? 'Edit' : 'Reconnect' }}</button>@endif
                <button type="button" class="iconbtn sm" wire:click="remove({{ $store->id }})" wire:confirm="Remove {{ $store->name }}? Pages already published there stay in the store." aria-label="Remove {{ $store->name }}"><i class="ti ti-trash" aria-hidden="true"></i></button>
            @endif
        </div>
    @empty
        @unless($formOpen)<div class="empty"><strong>No stores connected</strong><span class="small muted">Connect a WooCommerce store to publish landing pages, or add the sandbox store to try publishing first.</span></div>@endunless
    @endforelse

    @if($formOpen)
        <form class="connection-form" wire:submit="save">
            <div class="field full">
                <label for="store-driver">Store type</label>
                <select id="store-driver" class="input" wire:model.live="driver" @disabled($editingId)>
                    <option value="woocommerce">WooCommerce (WordPress)</option>
                    <option value="sandbox">Sandbox store, for trying publishing</option>
                </select>
            </div>
            <div class="field"><label for="store-name">Name</label><input id="store-name" class="input" wire:model="name" maxlength="120" placeholder="{{ $driver === 'sandbox' ? 'Sandbox store' : 'Northline store' }}">@error('name')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="field"><label for="store-brand">Brand</label><select id="store-brand" class="input" wire:model="brandId"><option value="">Every brand</option>@foreach($brands as $brand)<option value="{{ $brand->id }}">{{ $brand->name }}</option>@endforeach</select>@error('brandId')<span class="field-error">{{ $message }}</span>@enderror</div>
            @if($driver === 'woocommerce')
                <div class="field full"><label for="store-url">Store URL</label><input id="store-url" class="input" type="url" wire:model="siteUrl" placeholder="https://shop.example.com" autocomplete="url">@error('siteUrl')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="store-user">WordPress username</label><input id="store-user" class="input" wire:model="username" autocomplete="off" spellcheck="false">@error('username')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="field"><label for="store-secret">Application password</label><input id="store-secret" class="input" type="password" wire:model="secret" autocomplete="new-password" placeholder="{{ $editingId ? 'Leave empty to keep the current one' : 'xxxx xxxx xxxx xxxx xxxx xxxx' }}">@error('secret')<span class="field-error">{{ $message }}</span>@enderror</div>
                <p class="xs muted full">In WordPress, open Users, then Profile, then Application Passwords, and create one for MarketingOwl. The user needs to be an Editor or Administrator. WooCommerce API keys cannot publish pages. The password is stored encrypted.</p>
            @endif
            <div class="row full">
                <button type="submit" class="pill pri sm" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">{{ $editingId ? 'Check and save' : 'Check and connect' }}</span><span wire:loading wire:target="save">Checking the store…</span></button>
                <button type="button" class="pill ghost sm" wire:click="close">Cancel</button>
            </div>
        </form>
    @endif
</section>
