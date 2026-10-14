@php
    $statusTag = fn (string $tone, string $label) => '<span class="tag '.$tone.'"><span class="dot"></span>'.e($label).'</span>';
    $imported = $mode === 'url' && $importedUrl !== '';
    $canSave = $mode === 'manual' || $imported;
@endphp
<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to add product</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page">
            <div class="row"><a class="pill ghost sm" href="{{ route('products.index') }}" wire:navigate><i class="ti ti-arrow-left" aria-hidden="true"></i> Products</a></div>

            <header class="pagehead">
                <div>
                    <div class="eyebrow">Add product</div>
                    <h1 class="display">Add a product</h1>
                    <p class="muted">Save a useful product without generating anything. Missing and conflicting facts stay visible in Knowledge.</p>
                </div>
            </header>

            <div class="chips" role="group" aria-label="How to add">
                <button type="button" class="chip" aria-pressed="{{ $mode === 'url' ? 'true' : 'false' }}" wire:click="setMode('url')">From a product URL</button>
                <button type="button" class="chip" aria-pressed="{{ $mode === 'manual' ? 'true' : 'false' }}" wire:click="setMode('manual')">Add manually</button>
            </div>

            <form class="add-layout" wire:submit="save">
                <div class="col add-main">
                    <section class="card col">
                        @if($mode === 'url')
                            <div class="field">
                                <label for="add-url">Product page address</label>
                                <div class="row add-url-row">
                                    <input id="add-url" class="input" type="text" inputmode="url" autocomplete="url" wire:model.blur="productUrl" wire:keydown.enter.prevent="import" placeholder="shop.example/product-name" aria-describedby="add-url-help" @error('productUrl') aria-invalid="true" @enderror>
                                    <button type="button" class="pill dark" wire:click="import" wire:loading.attr="disabled" wire:target="import">
                                        <span wire:loading.remove wire:target="import">{{ $imported ? 'Import again' : 'Import' }}</span>
                                        <span wire:loading wire:target="import">Importing…</span>
                                    </button>
                                </div>
                                <span id="add-url-help" class="muted xs">We read the page once and show every fact for you to confirm.</span>
                                @error('productUrl')
                                    <span class="err" role="alert">{{ $message }} @unless($imported)<button type="button" class="linkbtn xs" wire:click="setMode('manual')">Add manually</button>@endunless</span>
                                @enderror
                            </div>
                        @else
                            <div class="grid add-grid">
                                <div class="field c6">
                                    <label for="m-name">Product name</label>
                                    <input id="m-name" class="input" type="text" wire:model="name" maxlength="160" @error('name') aria-invalid="true" aria-describedby="m-name-err" @enderror>
                                    @error('name')<span id="m-name-err" class="err" role="alert">{{ $message }}</span>@enderror
                                </div>
                                <div class="field c6">
                                    <label for="m-price">Price</label>
                                    <input id="m-price" class="input" type="text" wire:model="price" maxlength="40" placeholder="$0.00">
                                    @error('price')<span class="err" role="alert">{{ $message }}</span>@enderror
                                </div>
                                <div class="field c6">
                                    <label for="m-aud">Audience</label>
                                    <input id="m-aud" class="input" type="text" wire:model="audience" maxlength="500" placeholder="{{ $brand?->default_audience ? 'Brand default: '.$brand->default_audience : 'Who buys this?' }}">
                                </div>
                                <div class="field c6">
                                    <label for="m-url">Product page address <span class="muted">(optional)</span></label>
                                    <input id="m-url" class="input" type="url" wire:model="productUrl" placeholder="https://shop.example/product-name">
                                    @error('productUrl')<span class="err" role="alert">{{ $message }}</span>@enderror
                                </div>
                                <div class="field c12">
                                    <label for="m-desc">Description</label>
                                    <textarea id="m-desc" class="input" rows="3" wire:model="description" maxlength="1000" placeholder="What it is, in a sentence or two"></textarea>
                                </div>
                                <div class="field c12">
                                    <label for="m-ben">Benefits</label>
                                    <textarea id="m-ben" class="input" rows="3" wire:model="benefits" maxlength="1000" placeholder="One benefit per line"></textarea>
                                </div>
                            </div>
                        @endif

                        <div class="grid add-grid">
                            <div class="field c6">
                                <label for="add-brand">Brand</label>
                                <select id="add-brand" class="input" wire:model.live="brandId">
                                    @foreach($brands as $option)
                                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                                    @endforeach
                                    @if($canCreateBrands)<option value="new" @disabled($brandLimitReached)>New brand…{{ $brandLimitReached ? ' (limit reached)' : '' }}</option>@endif
                                </select>
                                @error('brandId')<span class="err" role="alert">{{ $message }}</span>@enderror
                            </div>
                            @if($brandId === 'new')
                                <div class="field c6">
                                    <label for="add-brand-name">New brand name</label>
                                    <input id="add-brand-name" class="input" type="text" wire:model="newBrandName" maxlength="120" @error('newBrandName') aria-invalid="true" @enderror>
                                    @error('newBrandName')<span class="err" role="alert">{{ $message }}</span>@enderror
                                </div>
                            @endif
                        </div>
                    </section>

                    @if($mode === 'url')
                        <section class="card col" wire:loading.flex wire:target="import" aria-live="polite">
                            <div class="row"><span class="ic rev"><i class="ti ti-loader-2 spin" aria-hidden="true"></i></span><strong class="medium">Reading the product page…</strong></div>
                            @foreach([80, 60, 90, 50] as $width)<div class="skel" style="width:{{ $width }}%"></div>@endforeach
                        </section>
                    @endif

                    @if($imported)
                        @if($duplicate && ! $keepBoth)
                            <div class="banner warn" role="alert">
                                <i class="ti ti-alert-triangle" aria-hidden="true"></i>
                                <span class="grow"><strong>Possible duplicate.</strong> {{ $duplicate->name }} ({{ $duplicate->brand->name }}) already uses this address.</span>
                                <a class="pill ghost sm" href="{{ route('products.show', $duplicate) }}" wire:navigate>Open existing</a>
                                <button type="button" class="pill ghost sm" wire:click="$set('keepBoth', true)">Keep both</button>
                            </div>
                        @elseif($duplicate)
                            <div class="banner neu" role="status"><i class="ti ti-copy" aria-hidden="true"></i><span>Both products will be kept. {{ $duplicate->name }} stays as it is.</span></div>
                        @endif

                        <section class="card col" wire:loading.remove wire:target="import">
                            <div class="row"><span class="eyebrow">Confirm what we found</span><span class="sp"></span><span class="muted xs">{{ count($source) }} of 4 page facts found</span></div>
                            <div class="tw flat">
                                <table class="tbl add-table">
                                    <caption class="sr-only">Facts found on the product page</caption>
                                    <thead><tr><th scope="col">Fact</th><th scope="col">Source says</th><th scope="col">Your confirmed value</th><th scope="col">Status</th></tr></thead>
                                    <tbody>
                                        <tr>
                                            <th scope="row" class="fact-name">Name</th>
                                            <td class="fact-source">{!! isset($source['name']) ? e($source['name']) : '<span class="muted">Not found</span>' !!}</td>
                                            <td class="fact-value"><label class="sr-only" for="af-name">Confirmed name</label><input id="af-name" class="input" type="text" wire:model.blur="name" maxlength="160" @error('name') aria-invalid="true" @enderror>@error('name')<span class="err" role="alert">{{ $message }}</span>@enderror</td>
                                            <td>{!! filled($name) ? $statusTag('ok', 'Confirmed') : $statusTag('', 'Missing') !!}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" class="fact-name">Price</th>
                                            <td class="fact-source">{!! isset($source['price']) ? e($source['price']) : '<span class="muted">Not found</span>' !!}</td>
                                            <td class="fact-value"><label class="sr-only" for="af-price">Confirmed price</label><input id="af-price" class="input" type="text" wire:model.blur="price" maxlength="40" placeholder="Enter the price you sell at"></td>
                                            <td>{!! filled($price) ? $statusTag('ok', 'Confirmed') : $statusTag('', 'Missing') !!}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" class="fact-name">Description</th>
                                            <td class="fact-source"><span class="clamp">{!! isset($source['description']) ? e($source['description']) : '<span class="muted">Not found</span>' !!}</span></td>
                                            <td class="fact-value"><label class="sr-only" for="af-desc">Confirmed description</label><textarea id="af-desc" class="input" rows="3" wire:model.blur="description" maxlength="1000"></textarea></td>
                                            <td>{!! filled($description) ? $statusTag('ok', 'Confirmed') : $statusTag('', 'Missing') !!}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" class="fact-name">Availability</th>
                                            <td class="fact-source">{!! isset($source['availability']) ? e($source['availability']) : '<span class="muted">Not found</span>' !!}</td>
                                            <td class="fact-value muted">{{ isset($source['availability']) ? 'Kept from the page' : 'Add it later in Knowledge' }}</td>
                                            <td>{!! isset($source['availability']) ? $statusTag('ok', 'Confirmed') : $statusTag('', 'Missing') !!}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" class="fact-name">Audience<span class="xs muted">Added by your team</span></th>
                                            <td class="fact-source muted">Not on the page</td>
                                            <td class="fact-value"><label class="sr-only" for="af-aud">Audience</label><input id="af-aud" class="input" type="text" wire:model.blur="audience" maxlength="500" placeholder="{{ $brand?->default_audience ? 'Brand default: '.$brand->default_audience : 'Who buys this?' }}"></td>
                                            <td>{!! filled($audience) ? $statusTag('ok', 'Confirmed') : ($brand?->default_audience ? $statusTag('', 'From brand') : $statusTag('', 'Missing')) !!}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" class="fact-name">Benefits<span class="xs muted">Added by your team</span></th>
                                            <td class="fact-source muted">Not on the page</td>
                                            <td class="fact-value"><label class="sr-only" for="af-ben">Benefits</label><textarea id="af-ben" class="input" rows="3" wire:model.blur="benefits" maxlength="1000" placeholder="One benefit per line"></textarea></td>
                                            <td>{!! filled($benefits) ? $statusTag('ok', 'Confirmed') : $statusTag('', 'Missing') !!}</td>
                                        </tr>
                                        <tr>
                                            <th scope="row" class="fact-name">Images</th>
                                            <td class="fact-source">{{ $imageCount ? trans_choice(':count image found|:count images found', $imageCount) : 'None found' }}</td>
                                            <td class="fact-value muted">{{ $imageCount ? 'The first usable image is imported' : 'Upload images later' }}</td>
                                            <td>{!! $imageCount ? $statusTag('ok', 'Found') : $statusTag('', 'Missing') !!}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    @endif

                    <div class="row">
                        <button type="submit" class="pill pri" @disabled(! $canSave) wire:loading.attr="disabled" wire:target="save,import">
                            <span wire:loading.remove wire:target="save">Save product</span><span wire:loading wire:target="save">Saving…</span>
                        </button>
                        <span class="muted small">Saving never starts generation.</span>
                    </div>
                </div>

                <aside class="col add-aside" aria-label="About this product's brand">
                    <section class="card col">
                        <span class="eyebrow">Brand inheritance</span>
                        @if($brand)
                            <p class="small"><strong class="medium">{{ $brand->name }}</strong> supplies the voice, palette and audience defaults. A product's own value always wins and is labelled in Knowledge.</p>
                            @if($profileAvailable)
                                <div>
                                    <div class="kv"><span class="muted">Voice</span><span>{{ $brand->voice ?: 'Not set' }}</span></div>
                                    <div class="kv"><span class="muted">Default audience</span><span>{{ $brand->default_audience ?: 'Not set' }}</span></div>
                                </div>
                            @endif
                            @if($brand->colors())
                                <div class="row swatches">@foreach($brand->colors() as $color)<span class="swatch sm" style="background:{{ $color }}" role="img" aria-label="Brand colour {{ $color }}"></span>@endforeach</div>
                            @endif
                            <a class="linkbtn" href="{{ route('brands.edit', $brand) }}" wire:navigate>Edit {{ $brand->name }}</a>
                        @else
                            <p class="small">A new brand starts empty. Add its voice, audience and palette afterwards, and every product of the brand picks them up.</p>
                        @endif
                    </section>
                    <section class="card col tip">
                        <div class="row"><svg width="30" height="20" viewBox="0 0 30 20" aria-hidden="true"><circle cx="9" cy="10" r="8" fill="#FF5A3D"/><circle cx="21" cy="10" r="8" fill="#EA38D0" opacity=".85"/><circle cx="9" cy="10" r="2.5" fill="#fff"/><circle cx="21" cy="10" r="2.5" fill="#fff"/></svg><strong class="medium">Tip from the owl</strong></div>
                        <p class="small muted">Confirm the price you actually sell at. Every launch pack uses the confirmed value, never the raw source.</p>
                    </section>
                </aside>
            </form>
        </main>
    </div>
</div>
