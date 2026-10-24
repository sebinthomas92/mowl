/**
 * The page editor (resources/views/livewire/page-editor.blade.php, app/Livewire/PageEditor.php).
 *
 * The page is one document held here. The canvas is an iframe showing the server's rendering of it
 * (the same renderer that publishes), with editing hooks: click a section to select it, click text to
 * type into it, click an image to change it. Panels edit the same document. Every change can be undone
 * and is saved automatically; a save from an out-of-date page is refused by the server, never merged.
 */

const FRAME_CSS = `
html,body{margin:0;padding:0;background:#fff}
*,*::before,*::after{box-sizing:border-box}
body{font:16px/1.6 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#111;-webkit-font-smoothing:antialiased}
img{max-width:100%;height:auto}
#root{min-height:100vh}
[data-mowl-field]{cursor:text;border-radius:3px}
[data-mowl-field]:hover{outline:1.5px dashed #7d8bff;outline-offset:3px}
[data-mowl-field][contenteditable]{outline:2px solid #4f63ff!important;outline-offset:3px;caret-color:#4f63ff}
[data-mowl-field][contenteditable]:focus{outline:2px solid #4f63ff!important}
[data-mowl-image]{cursor:pointer}
[data-mowl-image]:hover{outline:2px dashed #7d8bff;outline-offset:2px}
[data-mowl-hidden] [data-mowl-field]:hover,[data-mowl-hidden] [data-mowl-image]:hover{outline-color:#9aa0b8}
.pe-flag{text-decoration:underline wavy #e2a400 1.5px!important;text-underline-offset:5px;text-decoration-skip-ink:none}
#pe-ov{position:absolute;left:0;top:0;width:0;height:0;z-index:2147483000;pointer-events:none}
.pe-box{position:absolute;pointer-events:none}
.pe-box.hover{box-shadow:inset 0 0 0 1.5px #9aa5ff}
.pe-box.sel{box-shadow:inset 0 0 0 2px #4f63ff}
.pe-chip{position:absolute;left:10px;top:10px;display:flex;align-items:center;gap:6px;background:#4f63ff;color:#fff;font:600 12px/1 system-ui,-apple-system,sans-serif;padding:7px 10px;border-radius:7px;white-space:nowrap;box-shadow:0 4px 14px #4f63ff40}
.pe-chip.hidden{background:#6b6f80}
.pe-tools{position:absolute;right:10px;top:10px;display:flex;gap:2px;padding:3px;border-radius:10px;background:#fff;box-shadow:0 6px 20px #0000002e,0 0 0 1px #0000000f;pointer-events:auto}
.pe-tools button{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:0;border-radius:8px;background:transparent;color:#2a2d3a;cursor:pointer}
.pe-tools button:hover{background:#eef0ff;color:#4f63ff}
.pe-tools button.ai{color:#7a3cf0}
.pe-tools button.danger:hover{background:#fdecea;color:#b4231a}
.pe-tools button:disabled{opacity:.3;cursor:default;background:transparent;color:#2a2d3a}
.pe-tools .sep{width:1px;margin:6px 3px;background:#e4e6ef}
.pe-insert{position:absolute;left:50%;transform:translate(-50%,-50%);display:inline-flex;align-items:center;gap:6px;padding:7px 13px 7px 10px;border:0;border-radius:999px;background:#4f63ff;color:#fff;font:600 12.5px/1 system-ui,-apple-system,sans-serif;box-shadow:0 6px 18px #4f63ff59;cursor:pointer;pointer-events:auto}
.pe-insert:hover{background:#3d50ee}
.pe-working{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#ffffffb8;font:600 14px/1.4 system-ui,-apple-system,sans-serif;color:#3d2a8a;pointer-events:auto}
`;

const svg = (paths) => `<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;

/* Tabler icon paths (MIT), the icon set the app already uses. */
const TOOL_ICONS = {
    up: svg('<path d="M12 5l0 14"/><path d="M18 11l-6 -6"/><path d="M6 11l6 -6"/>'),
    down: svg('<path d="M12 5l0 14"/><path d="M18 13l-6 6"/><path d="M6 13l6 6"/>'),
    ai: svg('<path d="M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm0 -12a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm-7 12a6 6 0 0 1 6 -6a6 6 0 0 1 -6 -6a6 6 0 0 1 -6 6a6 6 0 0 1 6 6z"/>'),
    copy: svg('<path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z"/><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1"/>'),
    eye: svg('<path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/>'),
    eyeOff: svg('<path d="M10.585 10.587a2 2 0 0 0 2.829 2.828"/><path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87"/><path d="M3 3l18 18"/>'),
    trash: svg('<path d="M4 7l16 0"/><path d="M10 11l0 6"/><path d="M14 11l0 6"/><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/>'),
    plus: svg('<path d="M12 5l0 14"/><path d="M5 12l14 0"/>'),
};

const DEVICE_WIDTHS = { desktop: null, tablet: 820, mobile: 390 };

const AI_IDEAS = ['Shorter', 'Warmer', 'More premium', 'Simpler words', 'For gift buyers', 'More benefit-led'];

function frameDocument(body = '') {
    return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>${FRAME_CSS}</style></head><body><div id="root">${body}</div><div id="pe-ov"></div></body></html>`;
}

function newId(prefix) {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    const bytes = crypto.getRandomValues(new Uint8Array(8));
    let id = prefix;
    for (const byte of bytes) id += chars[byte % 36];

    return id;
}

function clone(value) {
    return JSON.parse(JSON.stringify(value ?? null));
}

/** JSON with sorted keys, so two documents compare by content. */
function stable(value) {
    if (Array.isArray(value)) return '[' + value.map(stable).join(',') + ']';
    if (value && typeof value === 'object') {
        return '{' + Object.keys(value).sort().map((key) => JSON.stringify(key) + ':' + stable(value[key])).join(',') + '}';
    }

    return JSON.stringify(value === undefined ? null : value);
}

function getPath(object, path) {
    return String(path).split('.').reduce((value, key) => (value === null || value === undefined ? undefined : value[key]), object);
}

function setPath(object, path, value) {
    const keys = String(path).split('.');
    let target = object;
    for (let index = 0; index < keys.length - 1; index++) {
        const key = keys[index];
        if (target[key] === null || typeof target[key] !== 'object') target[key] = /^\d+$/.test(keys[index + 1]) ? [] : {};
        target = target[key];
    }
    target[keys[keys.length - 1]] = value;
}

function fieldDefault(field) {
    switch (field.type) {
        case 'list': return clone(field.default || []);
        case 'images': return [];
        case 'image': return null;
        case 'toggle': return Boolean(field.default);
        case 'rating': return Number(field.default || 5);
        case 'select': return field.default ?? Object.keys(field.options || {})[0];
        default: return String(field.default ?? '');
    }
}

function rgb(hex) {
    let value = String(hex || '#000000').replace('#', '');
    if (value.length === 3) value = value.split('').map((char) => char + char).join('');
    const number = parseInt(value.slice(0, 6), 16) || 0;

    return [(number >> 16) & 255, (number >> 8) & 255, number & 255];
}

/** a moved toward b by amount, like Theme::mix(). */
function mix(a, b, amount) {
    const [ar, ag, ab] = rgb(a);
    const [br, bg, bb] = rgb(b);
    const channel = (from, to) => Math.round(from + (to - from) * amount).toString(16).padStart(2, '0');

    return '#' + channel(ar, br) + channel(ag, bg) + channel(ab, bb);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function pageEditor() {
    return {
        config: null,
        doc: null,
        revision: 0,
        state: {},
        checks: [],
        images: [],
        selected: null,
        hovered: null,
        device: 'desktop',
        rightTab: 'page',
        leftOpen: false,
        listVersion: 0,
        open: {},
        iconFor: null,
        saveState: 'saved',
        conflict: null,
        history: [],
        future: [],
        details: { name: '', slug: '', errors: {}, saving: false },
        ai: { busy: null, instruction: '', section: '', elapsed: 0, state: {}, ideas: AI_IDEAS },
        library: { open: false, group: 'all', at: null, hover: null, busy: false },
        picker: { open: false, sid: null, path: null, multiple: false, max: 1, chosen: [], uploading: false, progress: 0, importing: false, message: '' },
        toasts: [],

        // Not reactive: the canvas and its bookkeeping.
        _frame: null,

        init() {
            const holder = this.$el.querySelector('script[data-pe-config]');
            if (!holder) return;
            this.config = JSON.parse(holder.textContent);
            this.doc = clone(this.config.document);
            this.revision = this.config.revision;
            this.state = this.config.state;
            this.checks = this.config.checks || [];
            this.images = this.config.images || [];
            this.ai.state = this.config.ai || {};
            this.details.name = this.config.page.name;
            this.details.slug = this.config.page.slug;
            this._frame = { saved: stable(this.config.document), dirty: 0, saving: false, again: false, saveTimer: null, renderTimer: null, renderSeq: 0, raw: {}, editing: null, history: { key: null, at: 0 }, retry: 0, previews: {}, previewTimer: null };
            if (this.doc.sections.length) this.selected = this.doc.sections.find((section) => section.type === 'product_hero')?.id || this.doc.sections[0].id;
            this.rightTab = this.selected ? 'section' : 'page';

            this.$nextTick(() => this.mountFrame());
            window.addEventListener('keydown', (event) => this.onKey(event));
            window.addEventListener('beforeunload', (event) => {
                if (this.unsaved()) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') this.flush();
            });
            window.addEventListener('pe-confirm', (event) => this.confirmClaim(event.detail.key));
            window.addEventListener('pe-publish', () => this.publish());
            window.addEventListener('pe-show', (event) => this.showIssue(event.detail.section, event.detail.path));
            window.addEventListener('page-state', (event) => this.adoptState(event.detail.state));
            if (this.config.write) {
                // Opened from "Write the copy with AI": write once, and not again on reload.
                window.history.replaceState(null, '', window.location.pathname);
                this.$nextTick(() => this.writePage(true));
            }
        },

        /* ------------------------------------------------------------ the canvas */

        mountFrame() {
            const frame = this.$refs.frame;
            frame.addEventListener('load', () => {
                const f = this._frame;
                f.doc = frame.contentDocument;
                f.win = frame.contentWindow;
                f.root = f.doc.getElementById('root');
                f.overlay = f.doc.getElementById('pe-ov');
                this.bindFrame();
                this.renderNow();
            });
            frame.srcdoc = frameDocument();
        },

        bindFrame() {
            const f = this._frame;
            f.doc.addEventListener('mousedown', (event) => this.onCanvasDown(event), true);
            f.doc.addEventListener('click', (event) => this.onCanvasClick(event), true);
            f.doc.addEventListener('dragstart', (event) => event.preventDefault());
            f.doc.addEventListener('mousemove', (event) => {
                if (event.target.closest?.('#pe-ov')) return;
                const id = event.target.closest?.('[data-mowl-section]')?.getAttribute('data-mowl-section') || null;
                if (id !== this.hovered) {
                    this.hovered = id;
                    this.drawOverlay();
                }
            });
            f.doc.addEventListener('mouseleave', () => {
                this.hovered = null;
                this.drawOverlay();
            });
            f.doc.addEventListener('beforeinput', (event) => this.onBeforeInput(event));
            f.doc.addEventListener('input', (event) => this.onInlineInput(event));
            f.doc.addEventListener('keydown', (event) => this.onInlineKey(event));
            f.doc.addEventListener('paste', (event) => this.onInlinePaste(event));
            f.doc.addEventListener('focusout', (event) => {
                if (f.editing && event.target === f.editing.el) this.endInline();
            });
            f.doc.addEventListener('keydown', (event) => this.onKey(event));
            f.overlay.addEventListener('mousedown', (event) => event.stopPropagation());
            f.overlay.addEventListener('click', (event) => {
                const button = event.target.closest('[data-act]');
                if (!button) return;
                event.preventDefault();
                event.stopPropagation();
                this.toolAction(button.getAttribute('data-act'));
            });
            const observer = new f.win.ResizeObserver(() => this.queueOverlay());
            observer.observe(f.root);
            f.win.addEventListener('resize', () => this.queueOverlay());
        },

        /** Asks the server for the page as it will publish and puts it on the canvas. */
        render(delay = 70) {
            clearTimeout(this._frame.renderTimer);
            this._frame.renderTimer = setTimeout(() => this.renderNow(), delay);
        },

        async renderNow() {
            const f = this._frame;
            if (!f.root) return;
            const sequence = ++f.renderSeq;
            let html;
            try {
                html = await this.$wire.preview(this.plain());
            } catch (error) {
                return;
            }
            if (sequence !== f.renderSeq || typeof html !== 'string') return;
            this.applyHtml(html);
        },

        /** Replaces only the sections whose HTML changed, so images do not reload as you type. */
        applyHtml(html) {
            const f = this._frame;
            const template = f.doc.createElement('template');
            template.innerHTML = html;
            const next = template.content.firstElementChild;
            if (!next) return;
            const current = f.root.firstElementChild;
            const key = (element) => element.getAttribute('data-mowl-section') || '';
            const incoming = Array.from(next.children);
            const editingSection = f.editing ? f.editing.el.closest('[data-mowl-section]') : null;
            const sameShape = current && current.getAttribute('style') === next.getAttribute('style')
                && Array.from(current.children).map(key).join(',') === incoming.map(key).join(',');
            if (!sameShape) {
                if (f.editing) this.endInline(false);
                f.raw = {};
                incoming.forEach((element) => { f.raw[key(element)] = element.outerHTML; });
                f.root.replaceChildren(next);
            } else {
                Array.from(current.children).forEach((element, index) => {
                    const fresh = incoming[index];
                    const markup = fresh.outerHTML;
                    if (f.raw[key(fresh)] === markup) return;
                    if (editingSection === element) return;
                    f.raw[key(fresh)] = markup;
                    element.replaceWith(fresh);
                });
            }
            f.root.querySelectorAll('a, img').forEach((element) => element.setAttribute('draggable', 'false'));
            this.decorateChecks();
            this.queueOverlay();
            if (f.scrollTo) {
                const target = this.sectionEl(f.scrollTo);
                f.scrollTo = null;
                target?.scrollIntoView({ block: 'start', behavior: 'smooth' });
            }
        },

        sectionEl(id) {
            const f = this._frame;
            if (!f.root || !id) return null;

            return f.root.querySelector(`[data-mowl-section="${CSS.escape(id)}"]`);
        },

        queueOverlay() {
            const f = this._frame;
            if (!f.win || f.overlayQueued) return;
            f.overlayQueued = true;
            f.win.requestAnimationFrame(() => {
                f.overlayQueued = false;
                this.drawOverlay();
            });
        },

        /** Selection and hover outlines, the section toolbar and the "add section" button. */
        drawOverlay() {
            const f = this._frame;
            if (!f.overlay) return;
            const overlay = f.overlay;
            overlay.replaceChildren();
            const box = (id, kind) => {
                const element = this.sectionEl(id);
                if (!element) return null;
                const rect = element.getBoundingClientRect();
                const div = f.doc.createElement('div');
                div.className = 'pe-box ' + kind;
                div.style.top = rect.top + f.win.scrollY + 'px';
                div.style.left = rect.left + f.win.scrollX + 'px';
                div.style.width = rect.width + 'px';
                div.style.height = rect.height + 'px';
                overlay.append(div);

                return { div, rect };
            };
            if (this.hovered && this.hovered !== this.selected) box(this.hovered, 'hover');
            if (!this.selected) return;
            const drawn = box(this.selected, 'sel');
            const section = this.section(this.selected);
            if (!drawn || !section) return;
            const index = this.indexOf(section.id);
            const short = drawn.rect.height < 72;
            const outside = short ? (index === 0 ? 'calc(100% + 8px)' : '-46px') : null;

            const chip = f.doc.createElement('div');
            chip.className = 'pe-chip' + (section.hidden ? ' hidden' : '');
            chip.textContent = this.def(section.type)?.label + (section.hidden ? ' · hidden' : '');
            if (outside) chip.style.top = outside;
            drawn.div.append(chip);

            const working = this.ai.busy === section.id;
            const tools = f.doc.createElement('div');
            tools.className = 'pe-tools';
            if (outside) tools.style.top = outside;
            const button = (act, icon, label, extra = '', disabled = false) => `<button type="button" data-act="${act}" title="${label}" aria-label="${label}" class="${extra}" ${disabled ? 'disabled' : ''}>${icon}</button>`;
            tools.innerHTML = button('up', TOOL_ICONS.up, 'Move up', '', index === 0)
                + button('down', TOOL_ICONS.down, 'Move down', '', index === this.doc.sections.length - 1)
                + (this.writable(section.type) ? button('ai', TOOL_ICONS.ai, 'Rewrite with AI', 'ai', Boolean(this.ai.busy)) : '')
                + '<span class="sep"></span>'
                + button('copy', TOOL_ICONS.copy, 'Duplicate')
                + button('hide', section.hidden ? TOOL_ICONS.eyeOff : TOOL_ICONS.eye, section.hidden ? 'Show on the page' : 'Hide from the page')
                + button('delete', TOOL_ICONS.trash, 'Delete', 'danger');
            drawn.div.append(tools);

            const insert = f.doc.createElement('button');
            insert.type = 'button';
            insert.className = 'pe-insert';
            insert.setAttribute('data-act', 'insert');
            insert.innerHTML = TOOL_ICONS.plus + '<span>Add section</span>';
            insert.style.top = '100%';
            drawn.div.append(insert);

            if (working) {
                const busy = f.doc.createElement('div');
                busy.className = 'pe-working';
                busy.textContent = 'Rewriting this section…';
                drawn.div.append(busy);
            }
        },

        toolAction(action) {
            const id = this.selected;
            if (!id || this.ai.busy) return;
            if (action === 'up') this.move(id, -1);
            if (action === 'down') this.move(id, 1);
            if (action === 'copy') this.duplicate(id);
            if (action === 'hide') this.toggleHidden(id);
            if (action === 'delete') this.remove(id);
            if (action === 'insert') this.openLibrary(this.indexOf(id) + 1);
            if (action === 'ai') {
                this.rightTab = 'section';
                this.$nextTick(() => document.getElementById('pe-rewrite')?.focus());
            }
        },

        decorateChecks() {
            const f = this._frame;
            if (!f.root) return;
            f.root.querySelectorAll('.pe-flag').forEach((element) => {
                element.classList.remove('pe-flag');
                element.removeAttribute('title');
            });
            for (const check of this.checks) {
                if (check.confirmed || !check.section || !check.path) continue;
                const element = f.root.querySelector(`[data-mowl-section="${CSS.escape(check.section)}"] [data-mowl-field="${CSS.escape(check.path)}"]`);
                if (element) {
                    element.classList.add('pe-flag');
                    element.setAttribute('title', check.message);
                }
            }
        },

        onCanvasDown(event) {
            const f = this._frame;
            if (event.button !== 0 || this.ai.busy || this.conflict) return;
            const target = event.target;
            if (target.closest?.('#pe-ov')) return;
            if (f.editing && f.editing.el.contains(target)) return;
            const sectionEl = target.closest?.('[data-mowl-section]');
            if (f.editing) this.endInline();
            if (!sectionEl) return;
            const id = sectionEl.getAttribute('data-mowl-section');
            if (this.selected !== id) this.select(id);
            const field = target.closest('[data-mowl-field]');
            if (field && sectionEl.contains(field)) this.beginInline(field, id);
        },

        onCanvasClick(event) {
            const target = event.target;
            if (target.closest?.('#pe-ov')) return;
            if (target.closest?.('a, summary')) event.preventDefault();
            if (this.ai.busy || this.conflict) return;
            const image = target.closest?.('[data-mowl-image]');
            if (image && !target.closest('[data-mowl-field]')) {
                const sectionEl = image.closest('[data-mowl-section]');
                if (sectionEl) this.openPicker(sectionEl.getAttribute('data-mowl-section'), image.getAttribute('data-mowl-image'));
            }
        },

        /* ------------------------------------------------------------ typing on the canvas */

        beginInline(element, sectionId) {
            const f = this._frame;
            const section = this.section(sectionId);
            const path = element.getAttribute('data-mowl-field');
            const field = section && this.fieldDef(section.type, path);
            if (!field || !['text', 'textarea'].includes(field.type)) return;
            this.remember('inline:' + sectionId + ':' + path);
            if (element.hasAttribute('data-mowl-empty')) {
                element.textContent = '';
                element.removeAttribute('data-mowl-empty');
                element.style.opacity = '';
                element.style.fontStyle = '';
            }
            element.setAttribute('contenteditable', 'plaintext-only');
            const plain = element.contentEditable === 'plaintext-only';
            if (!plain) element.setAttribute('contenteditable', 'true');
            element.setAttribute('spellcheck', 'true');
            if (field.type === 'textarea') element.style.whiteSpace = 'pre-wrap';
            f.editing = { el: element, sid: sectionId, path, field, plain, before: getPath(section.settings, path) ?? '' };
            setTimeout(() => {
                if (f.editing?.el === element && f.doc.activeElement !== element) element.focus();
            }, 0);
        },

        /** Ends typing on the canvas; the canvas then shows the text as it will publish. */
        endInline(rerender = true) {
            const f = this._frame;
            if (!f.editing) return;
            const { el } = f.editing;
            f.editing = null;
            el.removeAttribute('contenteditable');
            el.removeAttribute('spellcheck');
            el.style.whiteSpace = '';
            if (rerender) this.render(0);
        },

        inlineText(editing) {
            const text = editing.el.innerText.replace(/\r/g, '').replace(/ /g, ' ');

            return editing.field.type === 'textarea' ? text.replace(/\n{3,}/g, '\n\n').replace(/\n+$/, '') : text.replace(/\s+/g, ' ');
        },

        onBeforeInput(event) {
            const editing = this._frame.editing;
            if (!editing || !editing.el.contains(event.target)) return;
            const multiline = editing.field.type === 'textarea';
            if (!multiline && ['insertParagraph', 'insertLineBreak'].includes(event.inputType)) {
                event.preventDefault();

                return;
            }
            if (event.inputType.startsWith('insert')) {
                const max = editing.field.max || (multiline ? 1000 : 200);
                const selection = this._frame.win.getSelection();
                const selected = selection && editing.el.contains(selection.anchorNode) ? selection.toString().length : 0;
                const adding = event.data ? event.data.length : 1;
                if (this.inlineText(editing).length - selected + adding > max) event.preventDefault();
            }
        },

        onInlineInput(event) {
            const editing = this._frame.editing;
            if (!editing || !editing.el.contains(event.target)) return;
            const section = this.section(editing.sid);
            if (!section) return;
            setPath(section.settings, editing.path, this.inlineText(editing));
            this.touched(false);
        },

        onInlineKey(event) {
            const editing = this._frame.editing;
            if (!editing || !editing.el.contains(event.target)) return;
            if (event.key === 'Enter' && editing.field.type !== 'textarea') {
                event.preventDefault();
                this.endInline();
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                const section = this.section(editing.sid);
                if (section) setPath(section.settings, editing.path, editing.before);
                this.touched(false);
                this.endInline();
            }
        },

        onInlinePaste(event) {
            const editing = this._frame.editing;
            if (!editing || editing.plain || !editing.el.contains(event.target)) return;
            event.preventDefault();
            const text = (event.clipboardData?.getData('text/plain') || '').replace(/\r/g, '');
            this._frame.doc.execCommand('insertText', false, editing.field.type === 'textarea' ? text : text.replace(/\s+/g, ' '));
        },

        /* ------------------------------------------------------------ the document */

        plain() {
            return clone(this.doc);
        },

        def(type) {
            return this.config.library.sections[type] || null;
        },

        section(id) {
            return (this.doc && id && this.doc.sections.find((section) => section.id === id)) || null;
        },

        sec() {
            return this.selected ? this.section(this.selected) : null;
        },

        indexOf(id) {
            return this.doc.sections.findIndex((section) => section.id === id);
        },

        fieldDef(type, path) {
            let fields = this.def(type)?.fields || [];
            let found = null;
            for (const key of String(path).split('.')) {
                if (/^\d+$/.test(key)) continue;
                found = fields.find((field) => field.key === key);
                if (!found) return null;
                fields = found.fields || [];
            }

            return found;
        },

        /** Whether the AI writes anything in a section of this type. */
        writable(type) {
            return (this.def(type)?.fields || []).some((field) => field.ai || (field.fields || []).some((child) => child.ai));
        },

        fields() {
            const section = this.sec();

            return section ? (this.def(section.type)?.fields || []) : [];
        },

        val(path) {
            const section = this.sec();

            return section ? getPath(section.settings, path) : undefined;
        },

        setField(path, value) {
            const section = this.sec();
            if (!section) return;
            this.remember('field:' + section.id + ':' + path);
            setPath(section.settings, path, value);
            // Typing waits for a pause before the canvas catches up; a click shows at once.
            const typing = typeof value === 'string' && ['text', 'textarea', 'url'].includes(this.fieldDef(section.type, path)?.type || 'text');
            this.touched(true, typing ? 260 : 30);
        },

        items(path) {
            const value = this.val(path);

            return Array.isArray(value) ? value : [];
        },

        itemTitle(field, item, index) {
            const first = (field.fields || []).find((child) => ['text', 'textarea'].includes(child.type) && String(item[child.key] || '').trim() !== '');
            const text = first ? String(item[first.key]).trim() : '';

            return (field.item_label || 'Item') + ' ' + (index + 1) + (text ? ': ' + (text.length > 38 ? text.slice(0, 37) + '…' : text) : '');
        },

        isOpen(path, item, index) {
            return Boolean(this.open[this.selected + ':' + path + ':' + (item.id || index)]);
        },

        toggleItem(path, item, index) {
            const key = this.selected + ':' + path + ':' + (item.id || index);
            this.open[key] = !this.open[key];
        },

        addItem(field) {
            const list = this.items(field.key);
            if (list.length >= (field.max || 10)) return;
            const item = { id: newId('i') };
            for (const child of field.fields || []) item[child.key] = fieldDefault(child);
            this.remember();
            this.setRaw(field.key, [...clone(list), item]);
            this.open[this.selected + ':' + field.key + ':' + item.id] = true;
            this.touched();
        },

        removeItem(path, index) {
            const list = clone(this.items(path));
            list.splice(index, 1);
            this.remember();
            this.setRaw(path, list);
            this.touched();
        },

        moveItem(path, index, direction) {
            const list = clone(this.items(path));
            const to = index + direction;
            if (to < 0 || to >= list.length) return;
            [list[index], list[to]] = [list[to], list[index]];
            this.remember();
            this.setRaw(path, list);
            this.touched();
        },

        setRaw(path, value) {
            const section = this.sec();
            if (section) setPath(section.settings, path, value);
        },

        layerName(section) {
            const label = this.def(section.type)?.label || section.type;
            const s = section.settings || {};
            const text = [s.heading, s.title, s.text, s.caption].find((value) => typeof value === 'string' && value.trim() !== '');

            return text ? label + ' · ' + text.trim() : label;
        },

        select(id, fromList = false) {
            if (id && !this.section(id)) return;
            if (this.selected !== id) {
                this.selected = id;
                this.iconFor = null;
            }
            if (id) this.rightTab = 'section';
            this.leftOpen = false;
            this.drawOverlay();
            if (fromList && id) this.sectionEl(id)?.scrollIntoView({ block: 'start', behavior: 'smooth' });
        },

        async addSection(type) {
            if (this.library.busy) return;
            this.library.busy = true;
            let starter = null;
            try {
                starter = await this.$wire.starter(type, this.plain());
            } catch (error) {
                starter = null;
            }
            this.library.busy = false;
            if (!starter) {
                this.toast('That section could not be added. Try again.', 'bad');

                return;
            }
            const at = this.library.at ?? (this.selected ? this.indexOf(this.selected) + 1 : this.doc.sections.length);
            this.remember();
            this.doc.sections.splice(Math.max(0, Math.min(at, this.doc.sections.length)), 0, starter);
            this.library.open = false;
            this.listVersion++;
            this.selected = starter.id;
            this.rightTab = 'section';
            this._frame.scrollTo = starter.id;
            this.touched();
        },

        duplicate(id) {
            const index = this.indexOf(id);
            if (index < 0) return;
            const copy = clone(this.doc.sections[index]);
            copy.id = newId('s');
            for (const value of Object.values(copy.settings || {})) {
                if (Array.isArray(value)) value.forEach((item) => { if (item && typeof item === 'object' && 'id' in item && !('kind' in item)) item.id = newId('i'); });
            }
            this.remember();
            this.doc.sections.splice(index + 1, 0, copy);
            this.listVersion++;
            this.selected = copy.id;
            this.touched();
        },

        remove(id) {
            const index = this.indexOf(id);
            if (index < 0) return;
            const label = this.def(this.doc.sections[index].type)?.label || 'section';
            if (!window.confirm(`Delete the ${label.toLowerCase()} section? You can undo this.`)) return;
            this.remember();
            this.doc.sections.splice(index, 1);
            this.listVersion++;
            this.selected = this.doc.sections[Math.min(index, this.doc.sections.length - 1)]?.id || null;
            if (!this.selected) this.rightTab = 'page';
            this.touched();
        },

        move(id, direction) {
            const index = this.indexOf(id);
            const to = index + direction;
            if (index < 0 || to < 0 || to >= this.doc.sections.length) return;
            this.remember();
            const [section] = this.doc.sections.splice(index, 1);
            this.doc.sections.splice(to, 0, section);
            this.listVersion++;
            this._frame.scrollTo = id;
            this.touched();
        },

        /** Drag and drop in the section list. */
        reorder(id, position) {
            const from = this.indexOf(id);
            if (from < 0 || from === position) {
                this.listVersion++;

                return;
            }
            this.remember();
            const [section] = this.doc.sections.splice(from, 1);
            this.doc.sections.splice(position, 0, section);
            this.listVersion++;
            this.touched();
        },

        toggleHidden(id) {
            const section = this.section(id);
            if (!section) return;
            this.remember();
            section.hidden = !section.hidden;
            this.touched();
        },

        /* ------------------------------------------------------------ theme */

        theme() {
            return this.doc.theme;
        },

        setTheme(key, value) {
            this.remember('theme:' + key);
            this.doc.theme[key] = ['radius', 'width', 'heading_weight'].includes(key) ? Number(value) : value;
            this.touched(true, key === 'radius' ? 120 : 30);
        },

        pickPalette(key) {
            const palette = this.config.theme.palettes[key];
            if (!palette) return;
            this.remember();
            this.doc.theme.palette = key;
            for (const color of Object.keys(this.config.theme.colors)) this.doc.theme.colors[color] = String(palette[color]).toLowerCase();
            this.touched();
        },

        setColor(key, value) {
            if (!/^#[0-9a-fA-F]{6}$/.test(value)) return;
            this.remember('color:' + key);
            this.doc.theme.palette = 'custom';
            this.doc.theme.colors[key] = value.toLowerCase();
            this.touched(true, 120);
        },

        fontStack(key) {
            const stack = this.config.theme.fonts[key]?.stack;

            return !stack || stack === 'inherit' ? 'inherit' : stack;
        },

        /** The colour of a section background, for the swatches. */
        bandColor(background) {
            const colors = this.doc.theme.colors;

            return { default: colors.paper, surface: colors.surface, soft: mix(colors.primary, colors.paper, 0.9), accent: colors.primary, dark: colors.dark }[background] || colors.paper;
        },

        useLook(template) {
            this.remember();
            this.doc.theme = clone(template.theme);
            this.touched();
            this.toast(`The page now uses the ${template.label} look.`);
        },

        async startOver(template) {
            if (!window.confirm(`Replace every section with the ${template.label} template? Your current sections go, but you can undo this.`)) return;
            const fresh = await this.$wire.templateDocument(template.key);
            if (!fresh) return;
            this.remember();
            const confirmations = this.doc.confirmations;
            this.doc = { ...fresh, confirmations };
            this.listVersion++;
            this.selected = this.doc.sections[0]?.id || null;
            this.touched();
        },

        /* ------------------------------------------------------------ history and saving */

        /**
         * Keeps the page as it is now for undo. Changes to the same field in quick succession are one
         * step, so undo takes back a word or a sentence, not a letter.
         */
        remember(key = null) {
            const memory = this._frame.history;
            const now = Date.now();
            if (key && key === memory.key && now - memory.at < 1500) {
                memory.at = now;

                return;
            }
            this.history.push(JSON.stringify(this.doc));
            if (this.history.length > 120) this.history.shift();
            this.future = [];
            memory.key = key;
            memory.at = now;
        },

        undo() {
            if (!this.history.length || this.ai.busy) return;
            this.endInline(false);
            this.future.push(JSON.stringify(this.doc));
            this.doc = JSON.parse(this.history.pop());
            this._frame.history.key = null;
            this.afterJump();
        },

        redo() {
            if (!this.future.length || this.ai.busy) return;
            this.endInline(false);
            this.history.push(JSON.stringify(this.doc));
            this.doc = JSON.parse(this.future.pop());
            this._frame.history.key = null;
            this.afterJump();
        },

        afterJump() {
            if (this.selected && !this.section(this.selected)) this.selected = this.doc.sections[0]?.id || null;
            this.listVersion++;
            this.touched();
        },

        /** Something changed: show it, and save soon. */
        touched(rerender = true, delay = 30) {
            this._frame.dirty++;
            this.saveState = 'unsaved';
            if (rerender) this.render(delay);
            this.scheduleSave();
        },

        unsaved() {
            return !this.conflict && (this.saveState !== 'saved' || this._frame.saving);
        },

        scheduleSave(delay = 900) {
            clearTimeout(this._frame.saveTimer);
            this._frame.saveTimer = setTimeout(() => this.save(), delay);
        },

        async save() {
            const f = this._frame;
            if (this.conflict) return;
            if (f.saving) {
                f.again = true;

                return;
            }
            clearTimeout(f.saveTimer);
            const body = this.plain();
            const sent = f.dirty;
            if (stable(body) === f.saved) {
                this.saveState = 'saved';

                return;
            }
            f.saving = true;
            this.saveState = 'saving';
            let result = null;
            try {
                result = await this.$wire.save(body, this.revision);
            } catch (error) {
                result = null;
            }
            f.saving = false;
            if (!result) {
                this.saveState = 'error';
                f.retry = Math.min(f.retry + 1, 5);
                this.scheduleSave(1500 * f.retry);

                return;
            }
            f.retry = 0;
            if (!result.ok) {
                if (result.conflict) {
                    this.conflict = result.message;
                    this.saveState = 'conflict';
                } else {
                    this.saveState = 'error';
                    this.toast(result.message || 'The page could not be saved.', 'bad');
                }

                return;
            }
            this.revision = result.revision;
            f.saved = stable(result.document);
            this.adoptState(result.state);
            this.checks = result.checks || [];
            const quiet = f.dirty === sent && !f.editing && !this.$root.contains(document.activeElement && ['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName) ? document.activeElement : null);
            if (quiet && stable(result.document) !== stable(this.doc)) {
                // The server tidied something (spacing, lengths, ids): show what it keeps.
                this.doc = result.document;
                this.render(0);
            } else {
                this.decorateChecks();
            }
            this.saveState = f.dirty === sent ? 'saved' : 'unsaved';
            if (f.again || f.dirty !== sent) {
                f.again = false;
                this.scheduleSave(300);
            }
            if (this.$wire.drawer) this.$wire.$refresh();
        },

        /** Saves now and waits for it, before anything that reads the saved page. */
        async flush() {
            const f = this._frame;
            clearTimeout(f.saveTimer);
            for (let wait = 0; f.saving && wait < 300; wait++) await sleep(100);
            if (!this.conflict && stable(this.plain()) !== f.saved) await this.save();
            for (let wait = 0; f.saving && wait < 300; wait++) await sleep(100);

            return !this.conflict && this.saveState === 'saved';
        },

        adoptState(state) {
            if (state && typeof state === 'object') this.state = { ...this.state, ...state };
        },

        saveLabel() {
            return { saved: 'Saved', saving: 'Saving…', unsaved: 'Unsaved changes', error: 'Not saved yet. Retrying…', conflict: 'Changed elsewhere' }[this.saveState] || '';
        },

        reload() {
            this.conflict = null;
            window.location.reload();
        },

        /* ------------------------------------------------------------ AI */

        async writePage(first = false) {
            if (this.ai.busy) return;
            const cost = this.ai.state.pageCost || 0;
            if (!first && cost > 0 && !window.confirm(`Writing the whole page again uses 1 credit (${this.ai.state.credits} left) and replaces the text in every section. You can undo it. Continue?`)) return;
            if (!first && cost === 0 && this.ai.state.written === false && this.doc.sections.length && !window.confirm('The AI rewrites the text in every section from the product\'s facts. Images, prices and links stay. You can undo it. Continue?')) return;
            this.endInline(false);
            if (!(await this.flush())) {
                this.toast('Save the page before the AI writes. Check your connection and try again.', 'bad');

                return;
            }
            await this.runAi(null, this.ai.instruction);
        },

        async rewrite(instruction) {
            const section = this.sec();
            if (!section || this.ai.busy) return;
            this.endInline(false);
            if (!(await this.flush())) {
                this.toast('Save the page before the AI writes. Check your connection and try again.', 'bad');

                return;
            }
            await this.runAi(section.id, instruction ?? this.ai.section);
        },

        async runAi(sectionId, instruction) {
            this.ai.busy = sectionId || 'page';
            this.ai.elapsed = 0;
            const timer = setInterval(() => { this.ai.elapsed++; }, 1000);
            this.drawOverlay();
            let result = null;
            try {
                result = await this.$wire.generate(sectionId, String(instruction || ''), this.revision);
            } catch (error) {
                result = null;
            }
            clearInterval(timer);
            this.ai.busy = null;
            if (!result || !result.ok) {
                if (result?.conflict) {
                    this.conflict = result.message;
                    this.saveState = 'conflict';
                }
                this.drawOverlay();
                this.toast(result?.message || 'The AI could not write just now. Nothing changed. Try again in a minute.', 'bad');

                return;
            }
            this.remember();
            this.doc = result.document;
            this.revision = result.revision;
            this._frame.saved = stable(result.document);
            this.checks = result.checks || [];
            this.ai.state = result.ai || this.ai.state;
            this.adoptState(result.state);
            this.saveState = 'saved';
            this.listVersion++;
            if (this.selected && !this.section(this.selected)) this.selected = this.doc.sections[0]?.id || null;
            this.render(0);
            const flagged = this.checks.filter((check) => !check.confirmed).length;
            this.toast(sectionId
                ? 'Section rewritten. Undo brings back the old text.'
                : 'Your page is written. Read every section before you publish' + (flagged ? `: ${flagged} ${flagged === 1 ? 'claim needs' : 'claims need'} a check.` : '.'), 'ok');
        },

        /* ------------------------------------------------------------ images */

        imageUrl(ref) {
            if (!ref) return null;

            return this.images.find((image) => image.key === ref.key)?.url || null;
        },

        openPicker(sectionId, path) {
            const section = this.section(sectionId);
            const field = section && this.fieldDef(section.type, path);
            if (!field || !['image', 'images'].includes(field.type)) return;
            if (this.selected !== sectionId) this.select(sectionId);
            this.picker = { ...this.picker, open: true, sid: sectionId, path, multiple: field.type === 'images', max: field.max || 12, chosen: [], message: '' };
        },

        pickerField() {
            const section = this.section(this.picker.sid);

            return section ? this.fieldDef(section.type, this.picker.path) : null;
        },

        chosenIndex(image) {
            return this.picker.chosen.indexOf(image.key);
        },

        inUse(image) {
            const section = this.section(this.picker.sid);
            const value = section ? getPath(section.settings, this.picker.path) : null;

            return Array.isArray(value) ? value.some((ref) => ref?.key === image.key) : value?.key === image.key;
        },

        pick(image) {
            const section = this.section(this.picker.sid);
            if (!section) return;
            if (this.picker.multiple) {
                const at = this.picker.chosen.indexOf(image.key);
                if (at >= 0) this.picker.chosen.splice(at, 1);
                else this.picker.chosen.push(image.key);

                return;
            }
            const current = getPath(section.settings, this.picker.path);
            this.remember();
            setPath(section.settings, this.picker.path, { kind: image.kind, id: image.id, key: image.key, alt: current?.alt || this.config.product.name });
            this.picker.open = false;
            this.touched();
        },

        addChosen() {
            const section = this.section(this.picker.sid);
            if (!section || !this.picker.chosen.length) return;
            const current = Array.isArray(getPath(section.settings, this.picker.path)) ? clone(getPath(section.settings, this.picker.path)) : [];
            for (const key of this.picker.chosen) {
                const image = this.images.find((candidate) => candidate.key === key);
                if (image && !current.some((ref) => ref.key === key)) current.push({ kind: image.kind, id: image.id, key: image.key, alt: this.config.product.name });
            }
            this.remember();
            setPath(section.settings, this.picker.path, current.slice(0, this.picker.max));
            this.picker.open = false;
            this.touched();
        },

        clearImage(path) {
            this.remember();
            this.setRaw(path, null);
            this.touched();
        },

        removeImage(path, index) {
            const list = clone(this.items(path));
            list.splice(index, 1);
            this.remember();
            this.setRaw(path, list);
            this.touched();
        },

        moveImage(path, index, direction) {
            this.moveItem(path, index, direction);
        },

        uploadFiles(files) {
            const file = files?.[0];
            if (!file) return;
            if (file.size > 10 * 1024 * 1024) {
                this.picker.message = 'That image is over 10 MB. Use a smaller one.';

                return;
            }
            this.picker.uploading = true;
            this.picker.progress = 0;
            this.picker.message = '';
            this.$wire.upload('upload', file, async () => {
                const result = await this.$wire.storeUpload();
                this.picker.uploading = false;
                if (!result?.ok) {
                    this.picker.message = result?.message || 'The image could not be uploaded.';

                    return;
                }
                this.images = result.images;
                const image = this.images.find((candidate) => candidate.key === result.key);
                if (image) {
                    if (this.picker.multiple) {
                        if (!this.picker.chosen.includes(image.key)) this.picker.chosen.push(image.key);
                    } else if (this.picker.sid) {
                        this.pick(image);
                    }
                }
            }, () => {
                this.picker.uploading = false;
                this.picker.message = 'The image could not be uploaded. Use a JPG, PNG, WebP or GIF under 10 MB.';
            }, (event) => {
                this.picker.progress = event.detail?.progress || 0;
            });
        },

        async importPhotos() {
            if (this.picker.importing) return;
            this.picker.importing = true;
            this.picker.message = '';
            let result = null;
            try {
                result = await this.$wire.importPhotos();
            } catch (error) {
                result = null;
            }
            this.picker.importing = false;
            if (result?.ok) this.images = result.images;
            this.picker.message = result?.message || 'The product page could not be read just now.';
        },

        /* ------------------------------------------------------------ library */

        openLibrary(at = null) {
            if (this.ai.busy) return;
            this.endInline();
            this.library.at = at;
            this.library.open = true;
            this.library.hover = null;
        },

        libraryTypes() {
            return Object.entries(this.config.library.sections)
                .filter(([, definition]) => this.library.group === 'all' || definition.group === this.library.group)
                .map(([type, definition]) => ({ type, ...definition }));
        },

        /** A preview of a section type filled from this product, shown beside the library. */
        previewType(type) {
            const f = this._frame;
            this.library.hover = type;
            clearTimeout(f.previewTimer);
            if (f.previews[type]) {
                this.showPreview(f.previews[type]);

                return;
            }
            f.previewTimer = setTimeout(async () => {
                let html = '';
                try {
                    const starter = await this.$wire.starter(type, this.plain());
                    html = starter ? await this.$wire.preview({ theme: this.plain().theme, buy_url: this.doc.buy_url, sections: [starter], confirmations: [] }) : '';
                } catch (error) {
                    html = '';
                }
                f.previews[type] = html;
                if (this.library.hover === type) this.showPreview(html);
            }, 180);
        },

        showPreview(html) {
            const frame = this.$refs.libraryFrame;
            if (frame) frame.srcdoc = frameDocument(html);
        },

        /* ------------------------------------------------------------ page details, publishing */

        async saveDetails() {
            this.details.saving = true;
            this.details.errors = {};
            const result = await this.$wire.saveDetails(this.details.name, this.details.slug);
            this.details.saving = false;
            if (!result?.ok) {
                this.details.errors = result?.errors || { name: [result?.message || 'Not saved.'] };

                return;
            }
            this.adoptState(result.state);
            this.toast('Page details saved.', 'ok');
        },

        setBuyUrl(value) {
            this.remember('buy_url');
            this.doc.buy_url = value;
            this.touched(true, 400);
        },

        checkCount() {
            return this.checks.filter((check) => check.kind === 'blocker' || !check.confirmed).length;
        },

        async openPublish() {
            this.endInline(false);
            await this.flush();
            await this.$wire.openDrawer();
        },

        async publish() {
            if (!(await this.flush())) {
                this.toast('The page has unsaved changes. Wait for it to save, then publish.', 'bad');

                return;
            }
            await this.$wire.publish();
        },

        async confirmClaim(key) {
            if (!(await this.flush())) return;
            const result = await this.$wire.confirmClaim(key, this.revision);
            if (!result?.ok) {
                if (result?.conflict) {
                    this.conflict = result.message;
                    this.saveState = 'conflict';
                }
                this.toast(result?.message || 'Not confirmed.', 'bad');

                return;
            }
            this.revision = result.revision;
            this.doc.confirmations = result.document.confirmations;
            this._frame.saved = stable(result.document);
            this.checks = result.checks || [];
            this.adoptState(result.state);
            this.decorateChecks();
        },

        showIssue(sectionId, path) {
            this.$wire.closeDrawer();
            if (!sectionId) return;
            this.select(sectionId, true);
            if (path) {
                setTimeout(() => {
                    const element = this.sectionEl(sectionId)?.querySelector(`[data-mowl-field="${CSS.escape(path)}"]`);
                    element?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }, 350);
            }
        },

        openPreview() {
            const tab = window.open('about:blank', '_blank');
            this.flush().then(() => {
                if (tab) tab.location.href = this.config.urls.preview;
            });
        },

        async goBack() {
            await this.flush();
            window.location.href = this.config.urls.back;
        },

        frameWidth() {
            const width = DEVICE_WIDTHS[this.device];

            return width ? width + 'px' : '100%';
        },

        toast(message, tone = '') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, tone });
            setTimeout(() => { this.toasts = this.toasts.filter((toast) => toast.id !== id); }, tone === 'bad' ? 7000 : 4500);
        },

        onKey(event) {
            const mod = event.metaKey || event.ctrlKey;
            const key = String(event.key || '').toLowerCase();
            if (key === 'escape') {
                if (this.picker.open) this.picker.open = false;
                else if (this.library.open) this.library.open = false;
                this.iconFor = null;

                return;
            }
            if (!mod) return;
            if (key === 's') {
                event.preventDefault();
                this.flush();

                return;
            }
            const target = event.target;
            const typing = target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
            if (typing) return;
            if (key === 'z' && !event.shiftKey) {
                event.preventDefault();
                this.undo();
            } else if ((key === 'z' && event.shiftKey) || key === 'y') {
                event.preventDefault();
                this.redo();
            }
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('pageEditor', pageEditor);
});
