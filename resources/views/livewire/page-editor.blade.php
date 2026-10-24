<div class="pe-root">
    {{-- The editor runs in the browser (resources/js/page-editor.js). Livewire leaves this part alone after the first render. --}}
    <div class="pe" wire:ignore x-data="pageEditor" :class="{ 'is-busy': !!ai.busy, 'has-conflict': !!conflict }">
        @if($config)
            <script type="application/json" data-pe-config>@json($config)</script>
        @endif

        <header class="pe-top">
            <div class="pe-top-left">
                <button type="button" class="pe-tb" @click="goBack()" aria-label="Back to the product" title="Back to the product"><i class="ti ti-arrow-left" aria-hidden="true"></i></button>
                <button type="button" class="pe-tb pe-narrow-only" @click="leftOpen = !leftOpen" :aria-expanded="leftOpen" aria-label="Sections" title="Sections"><i class="ti ti-layout-list" aria-hidden="true"></i></button>
                <button type="button" class="pe-title" @click="rightTab = 'page'" title="Page settings">
                    <strong x-text="state.name || (config && config.page.name)">{{ $page->name }}</strong>
                    <span x-text="config && config.product.name">{{ $product->name }}</span>
                </button>
            </div>

            <div class="pe-top-mid">
                <div class="pe-devices" role="radiogroup" aria-label="Preview size">
                    <button type="button" role="radio" :aria-checked="device === 'desktop'" @click="device = 'desktop'" aria-label="Desktop" title="Desktop"><i class="ti ti-device-desktop" aria-hidden="true"></i></button>
                    <button type="button" role="radio" :aria-checked="device === 'tablet'" @click="device = 'tablet'" aria-label="Tablet" title="Tablet"><i class="ti ti-device-tablet" aria-hidden="true"></i></button>
                    <button type="button" role="radio" :aria-checked="device === 'mobile'" @click="device = 'mobile'" aria-label="Phone" title="Phone"><i class="ti ti-device-mobile" aria-hidden="true"></i></button>
                </div>
                <div class="pe-history">
                    <button type="button" class="pe-tb" @click="undo()" :disabled="!history.length || !!ai.busy" aria-label="Undo" title="Undo (Ctrl+Z)"><i class="ti ti-arrow-back-up" aria-hidden="true"></i></button>
                    <button type="button" class="pe-tb" @click="redo()" :disabled="!future.length || !!ai.busy" aria-label="Redo" title="Redo (Ctrl+Shift+Z)"><i class="ti ti-arrow-forward-up" aria-hidden="true"></i></button>
                </div>
            </div>

            <div class="pe-top-right">
                <span class="pe-save" :class="'is-' + saveState" role="status" aria-live="polite">
                    <i class="ti" :class="{ saved: 'ti-cloud-check', saving: 'ti-loader-2', unsaved: 'ti-pencil', error: 'ti-cloud-off', conflict: 'ti-alert-triangle' }[saveState]" aria-hidden="true"></i>
                    <span class="pe-wide-only" x-text="saveLabel()"></span>
                </span>
                <button type="button" class="pe-tb pe-ai-btn" :class="rightTab === 'ai' && 'is-on'" @click="rightTab = 'ai'" aria-label="Write with AI" title="Write with AI"><i class="ti ti-sparkles" aria-hidden="true"></i></button>
                <button type="button" class="pill ghost sm" @click="openPreview()" title="Open the draft in a new tab"><i class="ti ti-external-link" aria-hidden="true"></i><span class="pe-wide-only">Preview</span></button>
                <button type="button" class="pe-checks" x-show="checkCount() > 0" x-cloak @click="openPublish()" :title="'What needs checking before you publish'">
                    <i class="ti ti-alert-triangle" aria-hidden="true"></i><span x-text="checkCount() + (checkCount() === 1 ? ' check' : ' checks')"></span>
                </button>
                <span class="tag pe-state pe-wide-only" :class="state.tone" x-show="state.live || state.publish !== 'idle'" x-cloak><span class="dot"></span><span x-text="state.label"></span></span>
                <button type="button" class="pill pri sm" @click="openPublish()">Publish</button>
            </div>
        </header>

        <div class="pe-body">
            {{-- Sections --}}
            <aside class="pe-left" :class="leftOpen && 'is-open'" :inert="!!ai.busy || !!conflict" aria-label="Sections">
                <div class="pe-panel-head">
                    <h2>Sections</h2>
                    <span class="pe-count" x-text="doc ? doc.sections.length : ''"></span>
                    <span class="sp"></span>
                    <button type="button" class="pe-tb sm pe-narrow-only" @click="leftOpen = false" aria-label="Close sections"><i class="ti ti-x" aria-hidden="true"></i></button>
                </div>
                <ol class="pe-layers" x-sort="reorder($item, $position)" aria-label="Sections on the page, top to bottom">
                    <template x-for="sec in (doc ? doc.sections : [])" :key="sec.id + ':' + listVersion">
                        <li class="pe-layer" x-sort:item="sec.id" :class="{ 'is-sel': selected === sec.id, 'is-hidden': sec.hidden, 'is-hover': hovered === sec.id && selected !== sec.id }"
                            @mouseenter="hovered = sec.id; drawOverlay()" @mouseleave="hovered = null; drawOverlay()">
                            <span class="pe-grip" x-sort:handle title="Drag to move" aria-hidden="true"><i class="ti ti-grip-vertical"></i></span>
                            <button type="button" class="pe-layer-name" @click="select(sec.id, true)" :aria-current="selected === sec.id ? 'true' : 'false'">
                                <i class="ti" :class="def(sec.type)?.icon" aria-hidden="true"></i>
                                <span x-text="layerName(sec)"></span>
                            </button>
                            <button type="button" class="pe-layer-eye" @click="toggleHidden(sec.id)" :aria-label="sec.hidden ? 'Show on the page' : 'Hide from the page'" :title="sec.hidden ? 'Hidden. Show on the page' : 'Hide from the page'">
                                <i class="ti" :class="sec.hidden ? 'ti-eye-off' : 'ti-eye'" aria-hidden="true"></i>
                            </button>
                        </li>
                    </template>
                </ol>
                <template x-if="doc && !doc.sections.length">
                    <p class="pe-empty-note">The page is empty. Add a section, or start over from a template on the Page tab.</p>
                </template>
                <div class="pe-left-foot">
                    <button type="button" class="pill pri sm pe-block" @click="openLibrary(null)"><i class="ti ti-plus" aria-hidden="true"></i>Add section</button>
                    <button type="button" class="pill ghost sm pe-block" @click="rightTab = 'theme'; leftOpen = false"><i class="ti ti-palette" aria-hidden="true"></i>Colours and fonts</button>
                </div>
            </aside>
            <div class="pe-left-scrim pe-narrow-only" x-show="leftOpen" x-cloak @click="leftOpen = false"></div>

            {{-- Canvas --}}
            <main class="pe-stage" aria-label="Page canvas">
                <div class="pe-device" :class="'is-' + device" :style="'width:' + frameWidth()">
                    <iframe x-ref="frame" class="pe-frame" sandbox="allow-same-origin" title="Your page. Click a section to edit it, click text to type."></iframe>
                </div>
                <div class="pe-busy" x-show="ai.busy === 'page'" x-cloak role="status" aria-live="polite">
                    <div class="pe-busy-card">
                        <span class="pe-spark" aria-hidden="true"><i class="ti ti-sparkles"></i></span>
                        <strong>Writing your page</strong>
                        <span class="small muted">From the product page, Knowledge and your brand. This usually takes under a minute.</span>
                        <span class="xs muted" x-text="ai.elapsed + ' s'"></span>
                    </div>
                </div>
            </main>

            {{-- Settings --}}
            <aside class="pe-right" :inert="!!ai.busy || !!conflict" aria-label="Settings">
                <div class="pe-tabs" role="tablist" aria-label="Settings">
                    <button type="button" role="tab" :aria-selected="rightTab === 'section'" @click="rightTab = 'section'">Section</button>
                    <button type="button" role="tab" :aria-selected="rightTab === 'theme'" @click="rightTab = 'theme'">Theme</button>
                    <button type="button" role="tab" :aria-selected="rightTab === 'page'" @click="rightTab = 'page'">Page</button>
                    <button type="button" role="tab" :aria-selected="rightTab === 'ai'" @click="rightTab = 'ai'"><i class="ti ti-sparkles" aria-hidden="true"></i> AI</button>
                </div>

                <div class="pe-panel" x-show="rightTab === 'section'" role="tabpanel">
                    <template x-if="!sec()">
                        <div class="pe-empty">
                            <i class="ti ti-pointer" aria-hidden="true"></i>
                            <strong>Pick a section</strong>
                            <span class="small muted">Click a section on the page or in the list to change it. Click text on the page to type over it.</span>
                        </div>
                    </template>
                    <template x-if="sec()">
                        <div class="pe-section">
                            <div class="pe-sec-head">
                                <span class="pe-sec-icon" aria-hidden="true"><i class="ti" :class="def(sec().type)?.icon"></i></span>
                                <div class="pe-sec-title">
                                    <strong x-text="def(sec().type)?.label"></strong>
                                    <span x-text="def(sec().type)?.description"></span>
                                </div>
                            </div>
                            <div class="pe-sec-tools">
                                <button type="button" class="pe-tb sm" @click="move(selected, -1)" :disabled="indexOf(selected) === 0" aria-label="Move up" title="Move up"><i class="ti ti-arrow-up" aria-hidden="true"></i></button>
                                <button type="button" class="pe-tb sm" @click="move(selected, 1)" :disabled="indexOf(selected) === doc.sections.length - 1" aria-label="Move down" title="Move down"><i class="ti ti-arrow-down" aria-hidden="true"></i></button>
                                <button type="button" class="pe-tb sm" @click="duplicate(selected)" aria-label="Duplicate" title="Duplicate"><i class="ti ti-copy" aria-hidden="true"></i></button>
                                <button type="button" class="pe-tb sm" @click="toggleHidden(selected)" :aria-label="sec().hidden ? 'Show on the page' : 'Hide from the page'" :title="sec().hidden ? 'Show on the page' : 'Hide from the page'"><i class="ti" :class="sec().hidden ? 'ti-eye-off' : 'ti-eye'" aria-hidden="true"></i></button>
                                <span class="sp"></span>
                                <button type="button" class="pe-tb sm danger" @click="remove(selected)" aria-label="Delete section" title="Delete section"><i class="ti ti-trash" aria-hidden="true"></i></button>
                            </div>
                            <template x-if="sec().hidden">
                                <div class="banner warn xs"><i class="ti ti-eye-off" aria-hidden="true"></i><span>Hidden: this section stays in the editor but is not published.</span></div>
                            </template>

                            <template x-if="writable(sec().type)">
                                <div class="pe-rewrite">
                                    <label class="pe-label" for="pe-rewrite"><i class="ti ti-sparkles" aria-hidden="true"></i> Rewrite with AI</label>
                                    <div class="pe-rewrite-row">
                                        <input id="pe-rewrite" class="input input-sm" x-model="ai.section" maxlength="300" placeholder="What should change? Optional" @keydown.enter.prevent="rewrite()" :disabled="!!ai.busy">
                                        <button type="button" class="pill dark sm" @click="rewrite()" :disabled="!!ai.busy" x-text="ai.busy === selected ? 'Writing…' : 'Rewrite'"></button>
                                    </div>
                                    <div class="pe-ideas">
                                        <template x-for="idea in ai.ideas" :key="idea">
                                            <button type="button" class="pe-idea" @click="rewrite(idea)" :disabled="!!ai.busy" x-text="idea"></button>
                                        </template>
                                    </div>
                                    <p class="pe-help">Included in your plan. The AI writes from the product's facts only; it never invents prices, reviews, numbers or policies.</p>
                                </div>
                            </template>

                            <div class="pe-fields">
                                <template x-for="f in fields()" :key="selected + ':' + f.key">
                                    <div class="pe-fieldwrap">
                                        <template x-if="f.type !== 'list'">
                                            <div>@include('livewire.page-editor.field', ['f' => 'f', 'path' => 'f.key'])</div>
                                        </template>
                                        <template x-if="f.type === 'list'">
                                            <div class="pe-list">
                                                <div class="pe-field-head">
                                                    <span class="pe-label" x-text="f.label"></span>
                                                    <span class="pe-count" x-text="items(f.key).length + ' / ' + (f.max || 10)"></span>
                                                </div>
                                                <template x-for="(item, ii) in items(f.key)" :key="(item.id || 'item') + ':' + ii">
                                                    <div class="pe-item" :class="isOpen(f.key, item, ii) && 'is-open'">
                                                        <div class="pe-item-head">
                                                            <button type="button" class="pe-item-toggle" @click="toggleItem(f.key, item, ii)" :aria-expanded="isOpen(f.key, item, ii)">
                                                                <i class="ti ti-chevron-right" aria-hidden="true"></i><span x-text="itemTitle(f, item, ii)"></span>
                                                            </button>
                                                            <button type="button" class="pe-tb xs" @click="moveItem(f.key, ii, -1)" :disabled="ii === 0" aria-label="Move up"><i class="ti ti-chevron-up" aria-hidden="true"></i></button>
                                                            <button type="button" class="pe-tb xs" @click="moveItem(f.key, ii, 1)" :disabled="ii === items(f.key).length - 1" aria-label="Move down"><i class="ti ti-chevron-down" aria-hidden="true"></i></button>
                                                            <button type="button" class="pe-tb xs danger" @click="removeItem(f.key, ii)" :disabled="items(f.key).length <= (f.min || 0)" aria-label="Remove"><i class="ti ti-x" aria-hidden="true"></i></button>
                                                        </div>
                                                        <template x-if="isOpen(f.key, item, ii)">
                                                            <div class="pe-item-body">
                                                                <template x-for="cf in f.fields" :key="cf.key">
                                                                    <div>@include('livewire.page-editor.field', ['f' => 'cf', 'path' => "f.key + '.' + ii + '.' + cf.key"])</div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                                <button type="button" class="pe-add-item" x-show="items(f.key).length < (f.max || 10)" @click="addItem(f)">
                                                    <i class="ti ti-plus" aria-hidden="true"></i><span x-text="'Add ' + String(f.item_label || 'item').toLowerCase()"></span>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="pe-panel" x-show="rightTab === 'theme'" x-cloak role="tabpanel">
                    <template x-if="doc">
                        <div class="pe-theme">
                            <div class="pe-group">
                                <h3>Colour palette</h3>
                                <div class="pe-palettes">
                                    <template x-for="(palette, key) in config.theme.palettes" :key="key">
                                        <button type="button" class="pe-palette" :aria-pressed="doc.theme.palette === key" @click="pickPalette(key)">
                                            <span class="pe-palette-dots" aria-hidden="true">
                                                <span :style="'background:' + palette.primary"></span><span :style="'background:' + palette.dark"></span><span :style="'background:' + palette.surface"></span>
                                            </span>
                                            <span x-text="palette.label"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <div class="pe-group">
                                <h3>Colours</h3>
                                <template x-for="(label, key) in config.theme.colors" :key="key">
                                    <div class="pe-color">
                                        <input type="color" :id="'pe-color-' + key" :value="doc.theme.colors[key]" @input="setColor(key, $event.target.value)">
                                        <label :for="'pe-color-' + key" x-text="label"></label>
                                        <input class="input input-sm pe-hex" :value="doc.theme.colors[key]" @change="setColor(key, $event.target.value.trim())" maxlength="7" spellcheck="false" :aria-label="label + ' colour code'">
                                    </div>
                                </template>
                                <p class="pe-help">Text on accent and dark panels switches between light and dark by itself, so it stays readable.</p>
                            </div>
                            <div class="pe-group">
                                <h3>Fonts</h3>
                                <span class="pe-label">Headings</span>
                                <div class="pe-fonts">
                                    <template x-for="(font, key) in config.theme.fonts" :key="key">
                                        <button type="button" class="pe-font" :aria-pressed="doc.theme.heading_font === key" @click="setTheme('heading_font', key)">
                                            <span class="pe-font-sample" :style="'font-family:' + fontStack(key)">Aa</span><span x-text="font.label"></span>
                                        </button>
                                    </template>
                                </div>
                                <span class="pe-label">Text</span>
                                <div class="pe-fonts">
                                    <template x-for="(font, key) in config.theme.fonts" :key="key">
                                        <button type="button" class="pe-font" :aria-pressed="doc.theme.body_font === key" @click="setTheme('body_font', key)">
                                            <span class="pe-font-sample" :style="'font-family:' + fontStack(key)">Aa</span><span x-text="font.label"></span>
                                        </button>
                                    </template>
                                </div>
                                <p class="pe-help">Stores cannot load extra fonts into a page, so these use fonts every phone and computer has. "Store font" uses your theme's own.</p>
                                <span class="pe-label">Heading weight</span>
                                <div class="pe-seg" role="radiogroup" aria-label="Heading weight">
                                    <template x-for="(label, key) in config.theme.weights" :key="key">
                                        <button type="button" role="radio" :aria-checked="String(doc.theme.heading_weight) === String(key)" @click="setTheme('heading_weight', key)" x-text="label"></button>
                                    </template>
                                </div>
                                <span class="pe-label">Heading letters</span>
                                <div class="pe-seg" role="radiogroup" aria-label="Heading letters">
                                    <template x-for="(label, key) in config.theme.cases" :key="key">
                                        <button type="button" role="radio" :aria-checked="doc.theme.heading_case === key" @click="setTheme('heading_case', key)" x-text="label"></button>
                                    </template>
                                </div>
                            </div>
                            <div class="pe-group">
                                <h3>Shape and space</h3>
                                <div class="pe-field-head"><label class="pe-label" for="pe-radius">Corner radius</label><span class="pe-count" x-text="doc.theme.radius + ' px'"></span></div>
                                <input id="pe-radius" type="range" min="0" max="32" step="2" class="pe-range" :value="doc.theme.radius" @input="setTheme('radius', $event.target.value)">
                                <span class="pe-label">Buttons</span>
                                <div class="pe-seg" role="radiogroup" aria-label="Buttons">
                                    <template x-for="(label, key) in config.theme.buttons" :key="key">
                                        <button type="button" role="radio" :aria-checked="doc.theme.button === key" @click="setTheme('button', key)" x-text="label"></button>
                                    </template>
                                </div>
                                <span class="pe-label">Spacing between sections</span>
                                <div class="pe-seg" role="radiogroup" aria-label="Spacing">
                                    <template x-for="(label, key) in config.theme.spacing" :key="key">
                                        <button type="button" role="radio" :aria-checked="doc.theme.spacing === key" @click="setTheme('spacing', key)" x-text="label"></button>
                                    </template>
                                </div>
                                <span class="pe-label">Content width</span>
                                <div class="pe-seg" role="radiogroup" aria-label="Content width">
                                    <template x-for="(label, key) in config.theme.widths" :key="key">
                                        <button type="button" role="radio" :aria-checked="String(doc.theme.width) === String(key)" @click="setTheme('width', key)" x-text="label"></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="pe-panel" x-show="rightTab === 'page'" x-cloak role="tabpanel">
                    <template x-if="doc">
                        <div class="pe-pageset">
                            <form class="pe-group" @submit.prevent="saveDetails()">
                                <h3>Page</h3>
                                <div class="pe-field">
                                    <label class="pe-label" for="pe-name">Page title</label>
                                    <input id="pe-name" class="input input-sm" x-model="details.name" maxlength="120" :aria-invalid="!!details.errors.name">
                                    <template x-if="details.errors.name"><span class="field-error" x-text="details.errors.name[0]"></span></template>
                                </div>
                                <div class="pe-field">
                                    <label class="pe-label" for="pe-slug">Address</label>
                                    <div class="pe-prefix"><span>/</span><input id="pe-slug" class="input input-sm" x-model="details.slug" maxlength="120" spellcheck="false" autocomplete="off" :aria-invalid="!!details.errors.slug"></div>
                                    <template x-if="details.errors.slug"><span class="field-error" x-text="details.errors.slug[0]"></span></template>
                                    <p class="pe-help">Lowercase letters, numbers and dashes. The store adds its own address in front.</p>
                                </div>
                                <button type="submit" class="pill ghost sm" :disabled="details.saving" x-text="details.saving ? 'Saving…' : 'Save page details'"></button>
                            </form>

                            <div class="pe-group">
                                <h3>Buy link</h3>
                                <div class="pe-field">
                                    <label class="pe-label" for="pe-buy">Where buy buttons go</label>
                                    <input id="pe-buy" class="input input-sm" type="url" :value="doc.buy_url" @input="setBuyUrl($event.target.value)" maxlength="500" placeholder="https://" spellcheck="false">
                                    <p class="pe-help">Every button links here unless its section has its own link.</p>
                                    <template x-if="config.product.buyUrl && config.product.buyUrl !== doc.buy_url">
                                        <button type="button" class="linkbtn" @click="setBuyUrl(config.product.buyUrl)">Use the product page</button>
                                    </template>
                                </div>
                            </div>

                            <div class="pe-group">
                                <h3>Templates</h3>
                                <p class="pe-help">Try another look on your sections, or start over with a template's sections. Both can be undone.</p>
                                <div class="pe-looks">
                                    <template x-for="template in config.templates" :key="template.key">
                                        <div class="pe-look" :class="config.page.template === template.key && 'is-current'">
                                            <div class="pe-look-swatch" aria-hidden="true" :style="'background:' + template.theme.colors.paper + ';color:' + template.theme.colors.ink">
                                                <span class="pe-look-aa" :style="'font-family:' + fontStack(template.theme.heading_font) + ';font-weight:' + template.theme.heading_weight + (template.theme.heading_case === 'upper' ? ';text-transform:uppercase' : '')">Aa</span>
                                                <span class="pe-look-bar" :style="'background:' + template.theme.colors.primary + ';border-radius:' + ({ square: 0, rounded: 6, pill: 99 }[template.theme.button]) + 'px'"></span>
                                            </div>
                                            <div class="pe-look-body">
                                                <strong x-text="template.label"></strong>
                                                <span class="xs muted" x-text="template.description"></span>
                                                <div class="pe-look-actions">
                                                    <button type="button" class="linkbtn" @click="useLook(template)">Use this look</button>
                                                    <button type="button" class="linkbtn" @click="startOver(template)">Start over</button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="pe-group">
                                <h3>Versions</h3>
                                <div class="kv"><span>Draft</span><span x-text="'v' + state.draft"></span></div>
                                <div class="kv"><span>Live</span><span x-text="state.live ? 'v' + state.live : 'Not published yet'"></span></div>
                                <template x-if="state.url"><div class="kv"><span>Address</span><a class="pe-break" :href="state.url" target="_blank" rel="noopener" x-text="state.url"></a></div></template>
                                <p class="pe-help" x-text="state.frozen ? 'v' + state.draft + ' was published. Your next change starts draft v' + (state.draft + 1) + '; the live page stays as it is until you publish.' : 'Changes save to this draft. The live page changes only when you publish.'"></p>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="pe-panel" x-show="rightTab === 'ai'" x-cloak role="tabpanel">
                    <template x-if="doc">
                        <div class="pe-aiset">
                            <div class="pe-group">
                                <h3><i class="ti ti-sparkles" aria-hidden="true"></i> Write the page with AI</h3>
                                <p class="small">The AI writes the text of every section from the product page, its Knowledge and your brand. Images, prices, reviews and numbers stay as they are.</p>
                                <div class="pe-field">
                                    <label class="pe-label" for="pe-ai-brief">Direction <span class="muted">(optional)</span></label>
                                    <textarea id="pe-ai-brief" class="input input-sm pe-textarea" rows="3" maxlength="600" x-model="ai.instruction" placeholder="e.g. For students who read in bed. Warm and simple. Stress the adjustable angle."></textarea>
                                </div>
                                <button type="button" class="pill pri sm pe-block" @click="writePage(false)" :disabled="!!ai.busy">
                                    <i class="ti ti-sparkles" aria-hidden="true"></i><span x-text="ai.busy === 'page' ? 'Writing…' : (ai.state.written ? 'Write the whole page again' : 'Write the whole page')"></span>
                                </button>
                                <p class="pe-help" x-text="(ai.state.pageCost || 0) === 0 ? 'Included: the first AI write of each page is free.' : 'Uses 1 credit. ' + (ai.state.credits ?? 0) + ' left.'"></p>
                                <template x-if="(ai.state.pageCost || 0) > 0 && (ai.state.credits ?? 0) < 1">
                                    <p class="pe-help">No credits left. <a :href="config.urls.billing">Buy credits</a></p>
                                </template>
                                <p class="pe-help">To change one section, select it and use Rewrite on the Section tab. That is included.</p>
                            </div>
                            <div class="pe-group">
                                <h3>What the AI will not do</h3>
                                <ul class="pe-rules">
                                    <li>Invent prices, discounts, reviews, ratings or numbers</li>
                                    <li>Promise delivery, returns or warranty terms your sources do not state</li>
                                    <li>Name competitor brands</li>
                                </ul>
                                <p class="pe-help">Claims the sources do not back up are underlined on the page and listed before you publish. <a :href="config.urls.knowledge">Add facts in Knowledge</a> to give it more to work with.</p>
                            </div>
                        </div>
                    </template>
                </div>
            </aside>
        </div>

        {{-- Section library --}}
        <div class="pe-modal" x-show="library.open" x-cloak x-transition.opacity.duration.150ms>
            <div class="pe-modal-scrim" @click="library.open = false"></div>
            <div class="pe-dialog pe-library" role="dialog" aria-modal="true" aria-labelledby="pe-lib-title">
                <header class="pe-dialog-head">
                    <h2 id="pe-lib-title">Add a section</h2>
                    <span class="sp"></span>
                    <button type="button" class="pe-tb" @click="library.open = false" aria-label="Close"><i class="ti ti-x" aria-hidden="true"></i></button>
                </header>
                <div class="pe-lib-body">
                    <nav class="pe-lib-groups" aria-label="Section groups">
                        <button type="button" :aria-pressed="library.group === 'all'" @click="library.group = 'all'">All sections</button>
                        <template x-for="(label, key) in (config ? config.library.groups : {})" :key="key">
                            <button type="button" :aria-pressed="library.group === key" @click="library.group = key" x-text="label"></button>
                        </template>
                    </nav>
                    <div class="pe-lib-grid">
                        <template x-for="t in (config ? libraryTypes() : [])" :key="t.type">
                            <button type="button" class="pe-lib-card" @mouseenter="previewType(t.type)" @focus="previewType(t.type)" @click="addSection(t.type)" :disabled="library.busy" :class="library.hover === t.type && 'is-hover'">
                                <span class="pe-lib-icon" aria-hidden="true"><i class="ti" :class="t.icon"></i></span>
                                <span class="pe-lib-text"><strong x-text="t.label"></strong><span x-text="t.description"></span></span>
                            </button>
                        </template>
                    </div>
                    <div class="pe-lib-preview" aria-hidden="true">
                        <span class="pe-lib-preview-label" x-text="library.hover ? def(library.hover)?.label + ', filled from your product' : 'Point at a section to preview it with your product'"></span>
                        <div class="pe-lib-preview-frame"><iframe x-ref="libraryFrame" sandbox="allow-same-origin" title="Section preview" tabindex="-1"></iframe></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Image picker --}}
        <div class="pe-modal" x-show="picker.open" x-cloak x-transition.opacity.duration.150ms>
            <div class="pe-modal-scrim" @click="picker.open = false"></div>
            <div class="pe-dialog pe-picker" role="dialog" aria-modal="true" aria-labelledby="pe-pick-title">
                <header class="pe-dialog-head">
                    <h2 id="pe-pick-title" x-text="picker.multiple ? 'Choose images' : 'Choose an image'"></h2>
                    <span class="sp"></span>
                    <button type="button" class="pe-tb" @click="picker.open = false" aria-label="Close"><i class="ti ti-x" aria-hidden="true"></i></button>
                </header>
                <div class="pe-pick-actions">
                    <label class="pill ghost sm pe-upload" :class="picker.uploading && 'is-busy'">
                        <i class="ti ti-upload" aria-hidden="true"></i>
                        <span x-text="picker.uploading ? 'Uploading ' + Math.round(picker.progress) + '%' : 'Upload an image'"></span>
                        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" class="pe-file" @change="uploadFiles($event.target.files); $event.target.value = ''" :disabled="picker.uploading">
                    </label>
                    <button type="button" class="pill ghost sm" @click="importPhotos()" :disabled="picker.importing">
                        <i class="ti ti-download" aria-hidden="true"></i><span x-text="picker.importing ? 'Getting photos…' : 'Get photos from the product page'"></span>
                    </button>
                    <span class="small muted pe-pick-msg" role="status" x-text="picker.message"></span>
                </div>
                <div class="pe-pick-grid">
                    <template x-for="image in images" :key="image.key">
                        <button type="button" class="pe-pick" @click="pick(image)" :aria-pressed="picker.multiple ? chosenIndex(image) >= 0 : inUse(image)" :title="image.label || ''">
                            <img :src="image.url" :alt="image.alt || ''" loading="lazy">
                            <span class="pe-pick-num" x-show="picker.multiple && chosenIndex(image) >= 0" x-text="chosenIndex(image) + 1"></span>
                            <span class="pe-pick-tag" x-show="inUse(image)">In use</span>
                            <span class="pe-pick-kind" x-text="image.kind === 'banner' ? 'Banner' : (image.label || 'Photo')"></span>
                        </button>
                    </template>
                </div>
                <template x-if="!images.length">
                    <p class="pe-empty-note">No images yet. Upload one, or get the photos from the product page.</p>
                </template>
                <footer class="pe-dialog-foot" x-show="picker.multiple">
                    <span class="small muted" x-text="picker.chosen.length ? picker.chosen.length + ' selected, added in this order' : 'Select images in the order you want them'"></span>
                    <span class="sp"></span>
                    <button type="button" class="pill ghost sm" @click="picker.open = false">Cancel</button>
                    <button type="button" class="pill pri sm" @click="addChosen()" :disabled="!picker.chosen.length" x-text="picker.chosen.length === 1 ? 'Add 1 image' : 'Add ' + (picker.chosen.length || '') + ' images'"></button>
                </footer>
            </div>
        </div>

        {{-- Out of date --}}
        <div class="pe-conflict" x-show="conflict" x-cloak role="alert">
            <i class="ti ti-alert-triangle" aria-hidden="true"></i>
            <span x-text="conflict"></span>
            <button type="button" class="pill pri sm" @click="reload()">Reload</button>
        </div>

        <div class="pe-toasts" aria-live="polite">
            <template x-for="toast in toasts" :key="toast.id">
                <div class="pe-toast" :class="toast.tone && 'is-' + toast.tone" x-text="toast.message"></div>
            </template>
        </div>
    </div>

    {{-- Publishing --}}
    @if($state['publish'] === 'verifying')
        <span wire:poll.5s="verifyPending"></span>
    @endif

    @if($drawer)
        @php
            $open = collect($issues)->filter(fn (array $issue) => $issue['kind'] === 'blocker' || ! $issue['confirmed'])->values();
            $confirmed = collect($issues)->filter(fn (array $issue) => $issue['kind'] === 'confirm' && $issue['confirmed'])->values();
            $busy = $page->isBusy();
            $isLive = $page->publish_state === 'live' && $page->live_version === $page->draft_version;
            $lastWarning = $attempts->first()?->warning;
        @endphp
        <div class="scrim" wire:click="closeDrawer"></div>
        <aside class="drawer pe-drawer" x-data role="dialog" aria-modal="true" aria-labelledby="pe-pub-title" @keydown.escape.window="$wire.closeDrawer()">
            <div class="drawer-head">
                <h2 id="pe-pub-title">Publish</h2>
                <button type="button" class="iconbtn" wire:click="closeDrawer" aria-label="Close"><i class="ti ti-x" aria-hidden="true"></i></button>
            </div>
            <div class="row"><span class="tag {{ $state['tone'] }}"><span class="dot"></span>{{ $state['label'] }}</span></div>

            <section class="pe-pub-block">
                <div class="kv">
                    <span>Store</span>
                    @if($stores->isEmpty())
                        <span><a href="{{ route('workspace.settings') }}#connections">Connect a store</a></span>
                    @else
                        <select class="input input-sm pe-store" aria-label="Store to publish to" wire:change="chooseStore($event.target.value)" @disabled($busy || ! $isOwner)>
                            <option value="">Choose a store</option>
                            @foreach($stores as $option)
                                <option value="{{ $option->id }}" @selected($option->id === $page->store_connection_id)>{{ $option->name }} ({{ $option->label() }})</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                @if($store)
                    <div class="kv"><span>Connection</span><span>@if($store->isConnected())<span class="tag ok"><span class="dot"></span>Connected</span>@else<span class="tag warn"><span class="dot"></span>Needs attention</span>@endif</span></div>
                @endif
                <div class="kv"><span>Address</span><span class="pe-break">@if($page->remote_url)<a href="{{ $page->remote_url }}" target="_blank" rel="noopener">{{ $page->remote_url }}</a>@else{{ '/'.$page->slug }}@endif</span></div>
                <div class="kv"><span>Version</span><span>v{{ $page->draft_version }}@if($page->live_version && $page->live_version !== $page->draft_version) replaces live v{{ $page->live_version }}@endif</span></div>
            </section>

            <section class="pe-pub-block" aria-labelledby="pe-checks-title">
                <h3 id="pe-checks-title" class="pe-pub-h">Checks</h3>
                @if($open->isEmpty())
                    <div class="banner ok xs"><i class="ti ti-circle-check" aria-hidden="true"></i><span>Everything the page claims is backed by the product's sources{{ $confirmed->isNotEmpty() ? ' or confirmed by an owner' : '' }}.</span></div>
                @endif
                @foreach($open as $issue)
                    <div class="pe-issue {{ $issue['kind'] === 'blocker' ? 'is-bad' : 'is-warn' }}" wire:key="issue-{{ $issue['key'] ?? $loop->index }}">
                        <div class="pe-issue-head">
                            <i class="ti {{ $issue['kind'] === 'blocker' ? 'ti-ban' : 'ti-alert-triangle' }}" aria-hidden="true"></i>
                            <strong>{{ $issue['where'] }}</strong>
                            <span class="sp"></span>
                            <span class="xs">{{ $issue['kind'] === 'blocker' ? 'Fix before publishing' : 'Needs an owner' }}</span>
                        </div>
                        <p class="small">{{ $issue['message'] }}</p>
                        @if(($issue['text'] ?? '') !== '')
                            <blockquote class="pe-quote">{{ \Illuminate\Support\Str::limit($issue['text'], 180) }}</blockquote>
                        @endif
                        <div class="row pe-issue-actions">
                            @if($issue['section'])
                                <button type="button" class="linkbtn" x-on:click="$dispatch('pe-show', @js(['section' => $issue['section'], 'path' => $issue['path']]))">Show on the page</button>
                            @endif
                            @if($issue['kind'] === 'confirm' && $isOwner)
                                <span class="sp"></span>
                                <button type="button" class="pill ghost sm" x-on:click="$dispatch('pe-confirm', @js(['key' => $issue['key']]))" wire:loading.attr="disabled" wire:target="confirmClaim">I confirm this is true</button>
                            @endif
                        </div>
                    </div>
                @endforeach
                @if($open->isNotEmpty() && ! $isOwner && $open->contains('kind', 'confirm'))
                    <p class="xs muted">Only workspace owners can confirm claims. Change the text, or ask an owner to confirm it.</p>
                @endif
                @if($confirmed->isNotEmpty())
                    <details class="pe-confirmed">
                        <summary class="small">{{ trans_choice(':count claim confirmed|:count claims confirmed', $confirmed->count()) }}</summary>
                        @foreach($confirmed as $issue)
                            <p class="xs"><strong>{{ $issue['where'] }}</strong>@if(($issue['text'] ?? '') !== ''): {{ \Illuminate\Support\Str::limit($issue['text'], 120) }}@endif<br><span class="muted">Confirmed by {{ $issue['confirmed']['name'] ?? 'an owner' }}@if(! empty($issue['confirmed']['at'])), {{ \Illuminate\Support\Carbon::parse($issue['confirmed']['at'])->format('j M, H:i') }}@endif</span></p>
                        @endforeach
                    </details>
                @endif
            </section>

            @if($store && ! $store->isConnected())
                <div class="banner warn xs"><i class="ti ti-plug-x" aria-hidden="true"></i><span>{{ $store->last_error ?: 'The store connection needs attention.' }} <a href="{{ route('workspace.settings') }}#connections">Reconnect the store</a></span></div>
            @endif
            @if($page->publish_state === 'failed' && $page->last_error)
                <div class="banner bad xs" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $page->last_error }}</span></div>
            @endif
            @if($lastWarning)
                <div class="banner warn xs"><i class="ti ti-info-circle" aria-hidden="true"></i><span>{{ $lastWarning }}</span></div>
            @endif
            @if($flash)
                <div class="banner ok xs" role="status"><i class="ti ti-circle-check" aria-hidden="true"></i><span>{{ $flash }}</span></div>
            @endif
            @error('publish')<div class="banner bad xs" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror
            @error('page')<div class="banner bad xs" role="alert"><i class="ti ti-alert-triangle" aria-hidden="true"></i><span>{{ $message }}</span></div>@enderror

            @if($page->publish_state !== 'idle' || $page->live_version)
                @php
                    $at = ['publishing' => 0, 'failed' => 0, 'verifying' => 1, 'live' => 2][$page->publish_state] ?? -1;
                    $failedStep = $page->publish_state === 'failed' ? ($attempts->first()?->status === 'unverified' ? 1 : 0) : null;
                    $shown = $attempts->first()?->pageVersion?->version ?? $page->draft_version;
                @endphp
                <ol class="timeline" aria-label="Publishing steps">
                    @foreach([['Publishing', "Send v{$shown} to the store"], ['Verification pending', 'Fetch the page address and compare'], ['Verified live', 'Confirm the store serves this version']] as $index => [$label, $detail])
                        @php $dot = $failedStep === $index ? 'bad' : (($failedStep !== null ? $index < $failedStep : ($index < $at || $page->publish_state === 'live')) ? 'ok' : ($index === $at && $failedStep === null ? 'cur' : '')); @endphp
                        <li class="tstep"><span class="tdot {{ $dot }}" aria-hidden="true"></span><span><strong class="medium">{{ $failedStep === $index ? ($index === 0 ? 'Publish failed' : 'Not verified') : $label }}</strong><br><span class="muted xs">{{ $index === 2 && $page->publish_state === 'live' && $page->verified_at ? 'Checked '.$page->verified_at->diffForHumans() : $detail }}</span></span></li>
                    @endforeach
                </ol>
            @endif

            @if($isOwner)
                <button type="button" class="pill pri pe-block" x-on:click="$dispatch('pe-publish')" wire:loading.attr="disabled" wire:target="publish,rollback" @disabled($blockers || $busy || $isLive || ! $store || ! $store->isConnected())>
                    <span wire:loading.remove wire:target="publish">{{ $page->publish_state === 'failed' ? "Retry publishing v{$page->draft_version}" : ($isLive ? "v{$page->draft_version} is live" : "Publish v{$page->draft_version}") }}</span>
                    <span wire:loading wire:target="publish">Publishing…</span>
                </button>
                @if($blockers)
                    <p class="xs muted">{{ trans_choice('Publishing is held by :count check above.|Publishing is held by :count checks above.', count($blockers)) }}</p>
                @elseif(! $store)
                    <p class="xs muted">Choose a store to publish to.</p>
                @endif
                @if($rollbackTo && ! $busy)
                    <button type="button" class="pill ghost pe-block" wire:click="rollback({{ $rollbackTo }})" wire:confirm="Put v{{ $rollbackTo }} back on the live page? Your draft is kept." wire:loading.attr="disabled" wire:target="publish,rollback">Roll back live to v{{ $rollbackTo }}</button>
                @endif
            @else
                <div class="banner xs"><i class="ti ti-lock" aria-hidden="true"></i><span>Only workspace owners can publish pages. Your changes are saved for them.</span></div>
            @endif

            @if($attempts->isNotEmpty())
                <div class="col pages-log">
                    @foreach($attempts as $attempt)
                        <span class="muted xs">{{ $attempt->created_at->format('j M, H:i') }} · {{ $attempt->action === 'rollback' ? 'Rollback to' : 'Publish' }} v{{ $attempt->pageVersion?->version }} · {{ ['publishing' => 'sending', 'verifying' => 'verifying', 'live' => 'verified live', 'failed' => 'failed', 'unverified' => 'not verified'][$attempt->status] ?? $attempt->status }}@if($attempt->user) by {{ $attempt->user->name }}@endif</span>
                    @endforeach
                </div>
            @endif
            <span class="muted xs">Saving is not publishing. A publish is live only once the page address serves the new version.</span>
        </aside>
    @endif
</div>
