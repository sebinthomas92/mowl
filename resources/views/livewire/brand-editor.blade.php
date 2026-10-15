<div class="app-shell">
    <a class="skip-link" href="#main-content">Skip to brand editor</a>
    @include('partials.workspace-sidebar')
    <div class="app-main">
        @include('partials.top-bar')
        <main id="main-content" class="lib-page">
            <div class="row"><a class="pill ghost sm" href="{{ route('brands.index') }}" wire:navigate><i class="ti ti-arrow-left" aria-hidden="true"></i> Brands</a></div>

            <header class="pagehead">
                <div>
                    <div class="eyebrow">{{ $brand ? 'Edit brand' : 'New brand' }}</div>
                    <h1 class="display">{{ $brand ? $brand->name : 'Add a brand' }}</h1>
                    <p class="muted">Voice, default audience and palette flow into every product of this brand. A product can override any of them.</p>
                </div>
            </header>

            @unless($profileAvailable)
                <div class="banner warn" role="status"><i class="ti ti-database-exclamation" aria-hidden="true"></i><span>Voice, audience and the full palette turn on once the latest database migration has run. Until then only the name, website, first colour and logo are saved.</span></div>
            @endunless

            <form class="add-layout" wire:submit="save">
                <div class="col add-main">
                    <section class="card col">
                        <span class="eyebrow">Identity</span>
                        <div class="grid add-grid">
                            <div class="field c6">
                                <label for="b-name">Brand name</label>
                                <input id="b-name" class="input" type="text" wire:model.live.debounce.300ms="name" maxlength="120" required @error('name') aria-invalid="true" aria-describedby="b-name-err" @enderror>
                                @error('name')<span id="b-name-err" class="err" role="alert">{{ $message }}</span>@enderror
                            </div>
                            <div class="field c6">
                                <label for="b-site">Website <span class="muted">(optional)</span></label>
                                <input id="b-site" class="input" type="url" wire:model="website" maxlength="255" placeholder="https://brand.example" @error('website') aria-invalid="true" @enderror>
                                @error('website')<span class="err" role="alert">{{ $message }}</span>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="card col">
                        <span class="eyebrow">Voice and audience</span>
                        <div class="field">
                            <label for="b-voice">Voice</label>
                            <textarea id="b-voice" class="input" rows="3" wire:model.live.debounce.400ms="voice" maxlength="1000" placeholder="Calm, practical, a little dry. Short sentences." aria-describedby="b-voice-help" @disabled(! $profileAvailable)></textarea>
                            <span id="b-voice-help" class="muted xs">How the brand sounds. Every launch pack is written in this voice. Facts never come from here.</span>
                            @error('voice')<span class="err" role="alert">{{ $message }}</span>@enderror
                        </div>
                        <div class="field">
                            <label for="b-aud">Default audience</label>
                            <input id="b-aud" class="input" type="text" wire:model.live.debounce.400ms="defaultAudience" maxlength="500" placeholder="Home office workers" aria-describedby="b-aud-help" @disabled(! $profileAvailable)>
                            <span id="b-aud-help" class="muted xs">Used for any product that has no audience of its own.</span>
                            @error('defaultAudience')<span class="err" role="alert">{{ $message }}</span>@enderror
                        </div>
                    </section>

                    <section class="card col">
                        <div class="row"><span class="eyebrow">Palette</span><span class="sp"></span><span class="muted xs">The first colour is the primary, used on banner buttons.</span></div>
                        @if($colors)
                            <ul class="palette-list" role="list">
                                @foreach($colors as $id => $color)
                                    <li class="palette-row" wire:key="color-{{ $id }}">
                                        <label class="sr-only" for="b-color-{{ $id }}">Colour {{ $loop->iteration }} picker</label>
                                        <input id="b-color-{{ $id }}" class="color-pick" type="color" wire:model.live.debounce.250ms="colors.{{ $id }}" value="{{ strtolower(is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#ffffff') }}">
                                        <label class="sr-only" for="b-hex-{{ $id }}">Colour {{ $loop->iteration }} hex value</label>
                                        <input id="b-hex-{{ $id }}" class="input hex" type="text" wire:model.live.debounce.400ms="colors.{{ $id }}" maxlength="7" spellcheck="false" @error('colors.'.$id) aria-invalid="true" @enderror>
                                        @if($loop->first)<span class="tag">Primary</span>@endif
                                        <span class="sp"></span>
                                        <button type="button" class="iconbtn flat" wire:click="removeColor('{{ $id }}')" wire:loading.attr="disabled" wire:target="removeColor" aria-label="Remove colour {{ $loop->iteration }}"><i class="ti ti-x" aria-hidden="true"></i></button>
                                        @error('colors.'.$id)<span class="err palette-err" role="alert">{{ $message }}</span>@enderror
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="muted small" role="status">No colours yet. Banners use the default accent until you add one.</p>
                        @endif
                        @error('colors')<span class="err" role="alert">{{ $message }}</span>@enderror
                        @if(count($colors) < \App\Livewire\BrandEditor::MAX_COLORS && ($profileAvailable || ! $colors))
                            <button type="button" class="pill ghost sm self-start" wire:click="addColor"><i class="ti ti-plus" aria-hidden="true"></i> Add colour</button>
                        @endif
                    </section>

                    <section class="card col">
                        <span class="eyebrow">Logo</span>
                        <div class="field">
                            <label for="b-logo">Banner logo</label>
                            <input id="b-logo" class="input" type="file" wire:model="logo" accept="image/png,image/webp" aria-describedby="b-logo-help">
                            <span id="b-logo-help" class="muted xs">PNG or WebP with a transparent background, up to 5 MB. Placed on generated banners. @if($brand?->banner_logo_path)A logo is already uploaded; a new file replaces it.@endif</span>
                            @error('logo')<span class="err" role="alert">{{ $message }}</span>@enderror
                        </div>
                    </section>

                    <div class="row">
                        <button type="submit" class="pill pri" wire:loading.attr="disabled" wire:target="save,logo">
                            <span wire:loading.remove wire:target="save">{{ $brand ? 'Save brand' : 'Create brand' }}</span><span wire:loading wire:target="save">Saving…</span>
                        </button>
                        <a class="pill ghost" href="{{ route('brands.index') }}" wire:navigate>Cancel</a>
                    </div>
                </div>

                <aside class="col add-aside" aria-label="Preview">
                    <section class="card col brand-preview">
                        <span class="eyebrow">Preview</span>
                        <div class="brand-preview-band" @if($validPalette) style="background:linear-gradient(135deg,{{ implode(',', count($validPalette) > 1 ? $validPalette : [$validPalette[0], $validPalette[0]]) }})" @endif>
                            <span class="display">{{ $name !== '' ? $name : 'Brand name' }}</span>
                        </div>
                        <div>
                            <div class="kv"><span class="muted">Voice</span><span>{{ $voice !== '' ? \Illuminate\Support\Str::limit($voice, 120) : 'Not set' }}</span></div>
                            <div class="kv"><span class="muted">Default audience</span><span>{{ $defaultAudience !== '' ? $defaultAudience : 'Not set' }}</span></div>
                            <div class="kv"><span class="muted">Products</span><span class="num">{{ $brand?->products_count ?? 0 }}</span></div>
                        </div>
                    </section>
                    <section class="card col tip">
                        <span class="eyebrow">What products inherit</span>
                        <p class="small muted">Voice and palette guide tone and colour in every launch pack. The default audience fills in for products without one, and shows as "From brand" in Knowledge until a product sets its own.</p>
                    </section>
                </aside>
            </form>
        </main>
    </div>
</div>
