{{--
    One setting of the selected section, drawn by the editor script.
    $f is the name of the field's script variable and $path the script expression for its place in the
    section's settings ("f.key", or "f.key + '.' + ii + '.' + cf.key" inside a list item).
--}}
<div class="pe-field" :class="'is-' + {{ $f }}.type">
    <template x-if="{{ $f }}.type !== 'toggle'">
        <div class="pe-field-head">
            <label class="pe-label" :for="'pe-f-' + ({!! $path !!}).replaceAll('.', '-')" x-text="{{ $f }}.label"></label>
            <template x-if="({{ $f }}.type === 'text' || {{ $f }}.type === 'textarea') && {{ $f }}.max">
                <span class="pe-count" :class="String(val({!! $path !!}) ?? '').length >= {{ $f }}.max && 'is-full'" x-text="String(val({!! $path !!}) ?? '').length + ' / ' + {{ $f }}.max"></span>
            </template>
        </div>
    </template>

    <template x-if="{{ $f }}.type === 'text' || {{ $f }}.type === 'url'">
        <input class="input input-sm" :id="'pe-f-' + ({!! $path !!}).replaceAll('.', '-')" :type="{{ $f }}.type === 'url' ? 'url' : 'text'"
            :value="val({!! $path !!}) ?? ''" @input="setField({!! $path !!}, $event.target.value)"
            :maxlength="{{ $f }}.max || ({{ $f }}.type === 'url' ? 500 : null)" :placeholder="{{ $f }}.placeholder || ({{ $f }}.type === 'url' ? 'https://' : '')"
            :spellcheck="{{ $f }}.type === 'url' ? 'false' : 'true'" autocomplete="off">
    </template>

    <template x-if="{{ $f }}.type === 'textarea'">
        <textarea class="input input-sm pe-textarea" :id="'pe-f-' + ({!! $path !!}).replaceAll('.', '-')" :rows="({{ $f }}.max || 0) > 700 ? 7 : (({{ $f }}.max || 0) > 300 ? 4 : 3)"
            :value="val({!! $path !!}) ?? ''" @input="setField({!! $path !!}, $event.target.value)"
            :maxlength="{{ $f }}.max || null" :placeholder="{{ $f }}.placeholder || ''"></textarea>
    </template>

    <template x-if="{{ $f }}.type === 'select' && {{ $f }}.key === 'background'">
        <div class="pe-swatches" role="radiogroup" :aria-label="{{ $f }}.label">
            <template x-for="(label, key) in {{ $f }}.options" :key="key">
                <button type="button" class="pe-swatch" role="radio" :aria-checked="val({!! $path !!}) === key ? 'true' : 'false'" :title="label" @click="setField({!! $path !!}, key)">
                    <span class="pe-swatch-chip" :style="'background:' + bandColor(key)" aria-hidden="true"></span><span x-text="label"></span>
                </button>
            </template>
        </div>
    </template>

    <template x-if="{{ $f }}.type === 'select' && {{ $f }}.key !== 'background'">
        <div class="pe-seg" role="radiogroup" :aria-label="{{ $f }}.label">
            <template x-for="(label, key) in {{ $f }}.options" :key="key">
                <button type="button" role="radio" :aria-checked="String(val({!! $path !!})) === String(key) ? 'true' : 'false'" @click="setField({!! $path !!}, key)" x-text="label"></button>
            </template>
        </div>
    </template>

    <template x-if="{{ $f }}.type === 'toggle'">
        <button type="button" class="pe-toggle" role="switch" :aria-checked="val({!! $path !!}) ? 'true' : 'false'" @click="setField({!! $path !!}, !val({!! $path !!}))">
            <span class="pe-switch" aria-hidden="true"></span><span x-text="{{ $f }}.label"></span>
        </button>
    </template>

    <template x-if="{{ $f }}.type === 'icon'">
        <div class="pe-iconfield" @click.outside="if (iconFor === selected + ':' + ({!! $path !!})) iconFor = null">
            <button type="button" class="pe-iconcur" :aria-expanded="iconFor === selected + ':' + ({!! $path !!}) ? 'true' : 'false'"
                @click="iconFor = iconFor === selected + ':' + ({!! $path !!}) ? null : selected + ':' + ({!! $path !!})">
                <span class="pe-emoji" x-text="config.library.icons[val({!! $path !!})] || '·'" aria-hidden="true"></span>
                <span>Change icon</span><i class="ti ti-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="pe-icons" x-show="iconFor === selected + ':' + ({!! $path !!})" x-cloak role="listbox" :aria-label="{{ $f }}.label">
                <template x-for="(emoji, key) in config.library.icons" :key="key">
                    <button type="button" role="option" :aria-selected="val({!! $path !!}) === key ? 'true' : 'false'" :title="key" :aria-label="key" @click="setField({!! $path !!}, key); iconFor = null" x-text="emoji"></button>
                </template>
            </div>
        </div>
    </template>

    <template x-if="{{ $f }}.type === 'rating'">
        <div class="pe-rating" role="radiogroup" :aria-label="{{ $f }}.label">
            <template x-for="n in 5" :key="n">
                <button type="button" role="radio" :aria-checked="Number(val({!! $path !!})) === n ? 'true' : 'false'" :aria-label="n + (n === 1 ? ' star' : ' stars')" :class="Number(val({!! $path !!})) >= n && 'on'" @click="setField({!! $path !!}, n)"><i class="ti ti-star-filled" aria-hidden="true"></i></button>
            </template>
        </div>
    </template>

    <template x-if="{{ $f }}.type === 'image'">
        <div class="pe-image">
            <button type="button" class="pe-thumb" @click="openPicker(selected, {!! $path !!})" :aria-label="val({!! $path !!}) ? 'Change image' : 'Choose an image'">
                <template x-if="imageUrl(val({!! $path !!}))"><img :src="imageUrl(val({!! $path !!}))" alt=""></template>
                <template x-if="!imageUrl(val({!! $path !!}))">
                    <span class="pe-thumb-empty"><i class="ti ti-photo-plus" aria-hidden="true"></i><span x-text="val({!! $path !!}) ? 'This image is no longer available' : 'Choose an image'"></span></span>
                </template>
            </button>
            <template x-if="val({!! $path !!})">
                <div class="pe-image-meta">
                    <div class="pe-image-tools">
                        <button type="button" class="linkbtn" @click="openPicker(selected, {!! $path !!})">Change</button>
                        <button type="button" class="linkbtn" @click="clearImage({!! $path !!})">Remove</button>
                    </div>
                    <input class="input input-sm" :value="val({!! $path !!})?.alt ?? ''" @input="setField({!! $path !!} + '.alt', $event.target.value)" maxlength="160" placeholder="Describe the image for people who cannot see it" aria-label="Image description">
                </div>
            </template>
        </div>
    </template>

    <template x-if="{{ $f }}.type === 'images'">
        <div class="pe-images">
            <template x-for="(ref, ri) in items({!! $path !!})" :key="(ref?.key || 'none') + ':' + ri">
                <div class="pe-images-item">
                    <template x-if="imageUrl(ref)"><img :src="imageUrl(ref)" alt=""></template>
                    <template x-if="!imageUrl(ref)"><span class="pe-images-missing"><i class="ti ti-photo-off" aria-hidden="true"></i></span></template>
                    <span class="pe-images-main" x-show="ri === 0 && sec()?.type === 'product_hero'">Main</span>
                    <div class="pe-images-tools">
                        <button type="button" @click="moveImage({!! $path !!}, ri, -1)" :disabled="ri === 0" aria-label="Move earlier"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>
                        <button type="button" @click="moveImage({!! $path !!}, ri, 1)" :disabled="ri === items({!! $path !!}).length - 1" aria-label="Move later"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>
                        <button type="button" @click="removeImage({!! $path !!}, ri)" aria-label="Remove image"><i class="ti ti-x" aria-hidden="true"></i></button>
                    </div>
                </div>
            </template>
            <button type="button" class="pe-images-add" x-show="items({!! $path !!}).length < ({{ $f }}.max || 12)" @click="openPicker(selected, {!! $path !!})">
                <i class="ti ti-plus" aria-hidden="true"></i><span>Add</span>
            </button>
        </div>
    </template>

    <template x-if="{{ $f }}.help">
        <p class="pe-help" x-text="{{ $f }}.help"></p>
    </template>
</div>
