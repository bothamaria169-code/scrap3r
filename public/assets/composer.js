/* scrap3r visual email composer.
   Edits a {settings, blocks} design in place; the server re-validates and
   renders the email-safe HTML on save and preview. No build step, no libraries. */
(() => {
  'use strict';

  const D = JSON.parse(document.getElementById('composer-data').textContent);
  const state = {
    design: D.design,
    name: D.name,
    subject: D.subject,
    preheader: D.preheader,
    attachment: D.attachment,
    selected: -1,
    dirty: false,
    saving: false,
  };

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const h = (tag, attrs = {}, ...kids) => {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (v === null || v === undefined || v === false) continue;
      if (k === 'class') node.className = v;
      else if (k === 'html') node.innerHTML = v;
      else if (k === 'text') node.textContent = v;
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
      else node.setAttribute(k, v === true ? '' : v);
    }
    kids.flat().forEach((kid) => kid !== null && kid !== undefined && node.append(kid));
    return node;
  };

  const canvas = $('#canvas');
  const inspector = $('#inspector');

  const BLOCKS = {
    heading:   { label: 'Heading',       icon: 'H',  make: () => ({ type: 'heading', text: 'A clear headline', level: 1, align: 'left' }) },
    text:      { label: 'Text',          icon: '¶',  make: () => ({ type: 'text', html: '<p>Write your message here.</p>', align: 'left' }) },
    button:    { label: 'Button',        icon: '▭',  make: () => ({ type: 'button', label: 'Reply to me', href: 'mailto:{{sender_email}}', align: 'left', color: '' }) },
    image:     { label: 'Image',         icon: '▣',  make: () => ({ type: 'image', src: '', alt: '', href: '', width: 100, align: 'center' }) },
    box:       { label: 'Highlight box', icon: '▍',  make: () => ({ type: 'box', html: '<strong>Key details</strong><br>Date, place, price&hellip;' }) },
    divider:   { label: 'Divider',       icon: '—',  make: () => ({ type: 'divider' }) },
    spacer:    { label: 'Spacer',        icon: '↕',  make: () => ({ type: 'spacer', height: 16 }) },
    signature: { label: 'Signature',     icon: '✎',  make: () => ({ type: 'signature' }) },
    html:      { label: 'Custom HTML',   icon: '</>', make: () => ({ type: 'html', html: '<p>Custom HTML</p>' }) },
  };

  // ------------------------------------------------------------ helpers
  const settings = () => state.design.settings;
  const brand = () => settings().brand || D.workspace.brand || '#4f46e5';
  const tint = (hex, amount) => {
    const n = hex.replace('#', '');
    const rgb = [0, 2, 4].map((i) => parseInt(n.slice(i, i + 2), 16));
    return '#' + rgb.map((c) => Math.round(c + (255 - c) * amount).toString(16).padStart(2, '0')).join('');
  };
  const assetUrl = (url) => url; // "uploads/x.png" resolves against this page, which lives in public/

  function toast(message, kind = 'ok') {
    const t = $('#toast');
    t.textContent = message;
    t.className = 'toast toast-' + kind;
    t.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => { t.hidden = true; }, kind === 'err' ? 7000 : 3500);
  }

  function markDirty() {
    state.dirty = true;
    const s = $('#save-state');
    s.textContent = 'Unsaved changes';
    s.className = 'cbar-state is-dirty';
  }

  async function post(action, fields = {}) {
    const fd = new FormData();
    fd.append('_csrf', D.csrf);
    fd.append('action', action);
    fd.append('id', String(D.id));
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    let res;
    try {
      res = await fetch(D.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
    } catch {
      throw new Error('Could not reach the server. Check your connection.');
    }
    let data = null;
    try { data = await res.json(); } catch { /* not JSON */ }
    if (!data) throw new Error(res.status === 403 ? 'Your session expired. Reload the page and sign in again.' : `Server error (${res.status}).`);
    if (!data.ok) throw new Error(data.error || 'Something went wrong.');
    return data;
  }

  const designPayload = () => JSON.stringify(state.design);

  // ------------------------------------------------------------ canvas
  function renderHead() {
    const s = settings();
    // Same order as the server: this email's logo, its header text, then the workspace's.
    const logo = s.logo_url || (s.header_text ? '' : D.workspace.logo);
    const head = s.header_text || D.workspace.header || '';
    $('#email-card').style.borderTopColor = brand();
    $('#email-bg').style.background = s.background || '#f4f4f5';
    const el = $('#email-head');
    el.innerHTML = '';
    if (s.layout === 'none') return;
    if (logo) el.append(h('img', { src: assetUrl(logo), alt: '', class: 'email-logo' }));
    else if (head) el.append(h('div', { class: 'email-head-text', style: `color:${brand()}`, text: head.toUpperCase() }));
  }

  function renderBlocks() {
    renderHead();
    const legacy = settings().layout === 'none';
    $('#legacy-note').hidden = !legacy;
    $('#email-card').classList.toggle('is-legacy', legacy);
    canvas.innerHTML = '';
    state.design.blocks.forEach((b, i) => canvas.append(blockEl(b, i)));
    if (!state.design.blocks.length) {
      canvas.append(h('div', { class: 'canvas-empty' },
        h('strong', { text: 'This email is empty.' }), h('br'), 'Add blocks from the left to start.'));
    }
  }

  function replaceBlock(i) {
    const old = canvas.querySelector(`.blk[data-i="${i}"]`);
    if (old) old.replaceWith(blockEl(state.design.blocks[i], i));
  }

  function editable(node, onChange, { plain = false } = {}) {
    node.contentEditable = 'true';
    node.spellcheck = true;
    node.addEventListener('input', () => { onChange(node); markDirty(); });
    node.addEventListener('paste', (e) => {
      // Paste as plain text so formatting from Word/web pages can't sneak in.
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
    });
    node.addEventListener('focus', () => { lastEditable = node; });
    if (plain) {
      node.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
    }
    return node;
  }

  function blockEl(b, i) {
    const wrap = h('div', { class: 'blk' + (i === state.selected ? ' is-selected' : ''), 'data-i': i, 'data-type': b.type });
    wrap.addEventListener('mousedown', () => select(i));

    const tools = h('div', { class: 'blk-tools' },
      h('span', { class: 'blk-handle', title: 'Drag to move', draggable: 'true', text: '⋮⋮' }),
      h('span', { class: 'blk-label', text: BLOCKS[b.type].label }),
      h('button', { type: 'button', title: 'Move up', text: '↑', onclick: (e) => { e.stopPropagation(); move(i, -1); } }),
      h('button', { type: 'button', title: 'Move down', text: '↓', onclick: (e) => { e.stopPropagation(); move(i, 1); } }),
      h('button', { type: 'button', title: 'Duplicate', text: '⧉', onclick: (e) => { e.stopPropagation(); duplicate(i); } }),
      h('button', { type: 'button', title: 'Delete', class: 'is-danger', text: '✕', onclick: (e) => { e.stopPropagation(); remove(i); } }),
    );
    wrap.append(tools);

    const handle = tools.querySelector('.blk-handle');
    handle.addEventListener('dragstart', (e) => { dragFrom = i; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(i)); wrap.classList.add('is-dragging'); });
    handle.addEventListener('dragend', () => wrap.classList.remove('is-dragging'));
    wrap.addEventListener('dragover', (e) => { if (dragFrom === null) return; e.preventDefault(); wrap.classList.add('is-dropzone'); });
    wrap.addEventListener('dragleave', () => wrap.classList.remove('is-dropzone'));
    wrap.addEventListener('drop', (e) => {
      e.preventDefault(); wrap.classList.remove('is-dropzone');
      if (dragFrom === null || dragFrom === i) return;
      const [moved] = state.design.blocks.splice(dragFrom, 1);
      state.design.blocks.splice(i, 0, moved);
      state.selected = i; dragFrom = null; markDirty(); renderBlocks(); renderInspector();
    });

    const body = h('div', { class: 'blk-body' });
    const align = b.align || 'left';

    switch (b.type) {
      case 'heading': {
        const el = h(b.level === 2 ? 'h2' : 'h1', { class: 'e-heading', style: `text-align:${align}` });
        el.textContent = b.text;
        body.append(editable(el, (n) => { b.text = n.innerText.trim(); }, { plain: true }));
        break;
      }
      case 'text': {
        const el = h('div', { class: 'e-text', style: `text-align:${align}`, html: b.html });
        body.append(editable(el, (n) => { b.html = n.innerHTML; }));
        break;
      }
      case 'box': {
        const el = h('div', { class: 'e-box', style: `background:${tint(brand(), 0.92)};border-left-color:${brand()}`, html: b.html });
        body.append(editable(el, (n) => { b.html = n.innerHTML; }));
        break;
      }
      case 'button': {
        const btn = h('span', { class: 'e-button', style: `background:${b.color || brand()}` });
        btn.textContent = b.label;
        editable(btn, (n) => { b.label = n.innerText.trim(); }, { plain: true });
        body.append(h('div', { style: `text-align:${align}` }, btn));
        break;
      }
      case 'image': {
        if (b.src) {
          body.append(h('div', { style: `text-align:${align}` },
            h('img', { src: assetUrl(b.src), alt: b.alt, class: 'e-image', style: `width:${b.width}%` })));
        } else {
          body.append(h('button', { type: 'button', class: 'e-image-empty', onclick: () => pickImage(i) },
            h('strong', { text: 'Add an image' }), h('span', { text: 'PNG, JPG, GIF or WebP, under 3 MB' })));
        }
        break;
      }
      case 'divider': body.append(h('hr', { class: 'e-divider' })); break;
      case 'spacer': body.append(h('div', { class: 'e-spacer', style: `height:${b.height}px` })); break;
      case 'signature':
        body.append(h('div', { class: 'e-signature', html: D.signature || '<em>Your signature</em>' }),
                    h('div', { class: 'e-note', text: 'Signature from Settings. Edit it there once and every email updates.' }));
        break;
      case 'html': {
        const frame = h('iframe', { class: 'e-html', sandbox: '', title: 'Custom HTML' });
        frame.srcdoc = b.html;
        frame.addEventListener('load', () => {
          try { frame.style.height = Math.min(1600, frame.contentDocument.body.scrollHeight + 16) + 'px'; } catch { frame.style.height = '320px'; }
        });
        body.append(frame, h('div', { class: 'e-note', text: 'Custom HTML: select this block to edit the code.' }));
        break;
      }
    }
    wrap.append(body);
    return wrap;
  }

  let dragFrom = null;
  let lastEditable = null;
  let savedRange = null;

  // Track the text box from the selection itself. Focus events are not
  // reliable here (a background window never fires them), so a toolbar click
  // could otherwise format the previously focused block instead of this one.
  document.addEventListener('selectionchange', () => {
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    const node = sel.anchorNode;
    const el = node && (node.nodeType === 1 ? node : node.parentElement);
    const box = el && el.closest ? el.closest('#canvas [contenteditable="true"]') : null;
    if (box) {
      lastEditable = box;
      savedRange = sel.getRangeAt(0).cloneRange();
    }
  });

  function restoreSelection() {
    if (!lastEditable) return false;
    lastEditable.focus();
    if (savedRange) {
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(savedRange);
    }
    return true;
  }

  // ------------------------------------------------------------ block ops
  function select(i) {
    if (state.selected === i) return;
    state.selected = i;
    $$('.blk', canvas).forEach((el) => el.classList.toggle('is-selected', Number(el.dataset.i) === i));
    renderInspector();
  }

  function add(type) {
    const block = BLOCKS[type].make();
    const at = state.selected >= 0 ? state.selected + 1 : state.design.blocks.length;
    state.design.blocks.splice(at, 0, block);
    state.selected = at;
    markDirty(); renderBlocks(); renderInspector();
    const el = canvas.querySelector(`.blk[data-i="${at}"]`);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      const edit = el.querySelector('[contenteditable]');
      if (edit) setTimeout(() => edit.focus(), 150);
    }
    if (type === 'image') pickImage(at);
  }

  function move(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= state.design.blocks.length) return;
    const blocks = state.design.blocks;
    [blocks[i], blocks[j]] = [blocks[j], blocks[i]];
    state.selected = j; markDirty(); renderBlocks(); renderInspector();
  }

  function duplicate(i) {
    state.design.blocks.splice(i + 1, 0, JSON.parse(JSON.stringify(state.design.blocks[i])));
    state.selected = i + 1; markDirty(); renderBlocks(); renderInspector();
  }

  function remove(i) {
    state.design.blocks.splice(i, 1);
    state.selected = -1; markDirty(); renderBlocks(); renderInspector();
  }

  // ------------------------------------------------------------ inspector
  const field = (label, control, hint) => h('label', { class: 'fld' }, label, control, hint ? h('span', { class: 'hint small', text: hint }) : null);

  function alignControl(b, i) {
    const seg = h('div', { class: 'seg' });
    ['left', 'center', 'right'].forEach((a) => seg.append(h('button', {
      type: 'button', class: (b.align || 'left') === a ? 'is-on' : '', text: a[0].toUpperCase() + a.slice(1),
      onclick: () => { b.align = a; markDirty(); replaceBlock(i); renderInspector(); },
    })));
    return field('Alignment', seg);
  }

  function renderInspector() {
    inspector.innerHTML = '';
    const i = state.selected;
    const b = state.design.blocks[i];

    if (!b) {
      renderEmailPanel();
      return;
    }

    inspector.append(h('div', { class: 'insp-head' },
      h('h3', { text: BLOCKS[b.type].label }),
      h('button', { type: 'button', class: 'btn ghost sm', text: 'Email settings', onclick: () => { state.selected = -1; $$('.blk').forEach((e) => e.classList.remove('is-selected')); renderInspector(); } })));

    switch (b.type) {
      case 'heading': {
        const lvl = h('div', { class: 'seg' },
          ...[[1, 'Large'], [2, 'Medium']].map(([n, l]) => h('button', {
            type: 'button', class: b.level === n ? 'is-on' : '', text: l,
            onclick: () => { b.level = n; markDirty(); replaceBlock(i); renderInspector(); },
          })));
        inspector.append(field('Size', lvl), alignControl(b, i), h('p', { class: 'hint small', text: 'Type straight into the heading in the email.' }));
        break;
      }
      case 'text':
        inspector.append(alignControl(b, i), h('p', { class: 'hint small', text: 'Type in the email. Use the toolbar above it for bold, links, lists and personal fields like {{first_name}}.' }));
        break;
      case 'box':
        inspector.append(h('p', { class: 'hint small', text: 'A tinted panel in your brand colour. Good for dates, venue, prices: the details people scan for.' }));
        break;
      case 'button': {
        const href = h('input', { type: 'text', value: b.href, placeholder: 'https://… or mailto:…' });
        href.addEventListener('input', () => { b.href = href.value.trim(); markDirty(); });
        const quick = h('div', { class: 'chips' },
          ...[['Reply by email', 'mailto:{{sender_email}}'], ['Booking page', '{{booking_link}}']].map(([l, v]) =>
            h('button', { type: 'button', class: 'chip', text: l, onclick: () => { b.href = v; href.value = v; markDirty(); } })));
        const colour = h('input', { type: 'color', value: b.color || brand() });
        colour.addEventListener('input', () => { b.color = colour.value; markDirty(); replaceBlock(i); });
        const reset = h('button', { type: 'button', class: 'btn ghost sm', text: 'Use brand', onclick: () => { b.color = ''; colour.value = brand(); markDirty(); replaceBlock(i); } });
        inspector.append(
          field('Link', href, 'Type the button label in the email itself.'), quick,
          field('Colour', h('span', { class: 'swatch-row' }, colour, reset)),
          alignControl(b, i));
        break;
      }
      case 'image': {
        const alt = h('input', { type: 'text', value: b.alt, placeholder: 'Describe the image' });
        alt.addEventListener('input', () => { b.alt = alt.value; markDirty(); });
        const link = h('input', { type: 'text', value: b.href, placeholder: 'Optional: https://…' });
        link.addEventListener('input', () => { b.href = link.value.trim(); markDirty(); });
        const width = h('input', { type: 'range', min: '10', max: '100', step: '5', value: String(b.width) });
        const wl = h('span', { class: 'small muted', text: b.width + '%' });
        width.addEventListener('input', () => { b.width = Number(width.value); wl.textContent = b.width + '%'; markDirty(); replaceBlock(i); });
        inspector.append(
          h('div', { class: 'actions' }, h('button', { type: 'button', class: 'btn ghost', text: b.src ? 'Replace image' : 'Upload image', onclick: () => pickImage(i) })),
          field('Width', h('span', { class: 'swatch-row' }, width, wl)),
          field('Alt text', alt, 'Shown when images are blocked, which many inboxes do by default.'),
          field('Link', link), alignControl(b, i));
        break;
      }
      case 'spacer': {
        const r = h('input', { type: 'range', min: '4', max: '96', step: '4', value: String(b.height) });
        const rl = h('span', { class: 'small muted', text: b.height + ' px' });
        r.addEventListener('input', () => { b.height = Number(r.value); rl.textContent = b.height + ' px'; markDirty(); replaceBlock(i); });
        inspector.append(field('Height', h('span', { class: 'swatch-row' }, r, rl)));
        break;
      }
      case 'html': {
        const ta = h('textarea', { class: 'code', spellcheck: 'false' });
        ta.value = b.html;
        ta.addEventListener('input', () => { b.html = ta.value; markDirty(); });
        ta.addEventListener('change', () => replaceBlock(i));
        inspector.append(field('HTML', ta, 'Scripts and event handlers are removed when you save.'));
        break;
      }
      case 'signature':
        inspector.append(h('p', { class: 'hint small', text: 'Inserts your signature from Settings.' }));
        break;
      case 'divider':
        inspector.append(h('p', { class: 'hint small', text: 'A thin line to separate sections.' }));
        break;
    }
  }

  function renderEmailPanel() {
    inspector.append(h('h3', { text: 'Email settings' }));

    const subject = h('input', { type: 'text', value: state.subject, maxlength: '255' });
    subject.addEventListener('input', () => { state.subject = subject.value; markDirty(); });
    const count = h('span', { class: 'hint small' });
    const sayCount = () => { const n = subject.value.length; count.textContent = n + ' characters' + (n > 60 ? ' · long subjects get cut off on phones' : ''); };
    subject.addEventListener('input', sayCount); sayCount();

    const pre = h('input', { type: 'text', value: state.preheader, maxlength: '200', placeholder: 'The grey line after the subject in the inbox' });
    pre.addEventListener('input', () => { state.preheader = pre.value; markDirty(); });

    inspector.append(field('Subject line', subject), count, field('Preview text', pre, 'Inboxes show this next to the subject. Use it to add a reason to open.'));

    // Attachment
    const att = h('div', { class: 'att' });
    if (state.attachment) {
      const kb = state.attachment.size ? ` (${Math.round(state.attachment.size / 1024).toLocaleString()} KB)` : ' (file missing: upload it again)';
      att.append(
        h('div', { class: 'att-file' }, h('span', { class: 'att-icon', text: '📎' }), h('span', { text: state.attachment.name + kb })),
        h('div', { class: 'actions' },
          h('button', { type: 'button', class: 'btn ghost sm', text: 'Replace', onclick: () => $('#file-attachment').click() }),
          h('button', { type: 'button', class: 'btn ghost sm', text: 'Remove', onclick: removeAttachment })));
    } else {
      att.append(h('button', { type: 'button', class: 'btn ghost', text: 'Attach a file', onclick: () => $('#file-attachment').click() }),
                 h('p', { class: 'hint small', text: 'Best on the email you send once someone is interested, not on the first cold email.' }));
    }
    inspector.append(h('h3', { class: 'mt', text: 'Attachment' }), att);

    // Test send
    const to = h('input', { type: 'email', value: D.me, placeholder: 'you@company.com' });
    const go = h('button', { type: 'button', class: 'btn ghost', text: 'Send test' });
    go.addEventListener('click', async () => {
      go.disabled = true; go.textContent = 'Sending…';
      try {
        if (state.dirty) await save();
        const r = await post('test_send', { to: to.value.trim() });
        toast(r.message);
      } catch (e) { toast(e.message, 'err'); }
      go.disabled = false; go.textContent = 'Send test';
    });
    inspector.append(h('h3', { class: 'mt', text: 'Send a test' }), to, h('div', { class: 'actions' }, go),
      h('p', { class: 'hint small', text: 'Filled in with a sample contact and marked [TEST]. Saves first.' }));
  }

  // ------------------------------------------------------------ uploads
  let imageTarget = null;
  function pickImage(i) { imageTarget = i; $('#file-image').value = ''; $('#file-image').click(); }

  $('#file-image').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const fd = { image: file };
    try {
      const r = await post('upload_image', fd);
      if (imageTarget === 'logo') {
        settings().logo_url = r.url; renderHead(); syncStyle();
      } else if (imageTarget !== null && state.design.blocks[imageTarget]) {
        state.design.blocks[imageTarget].src = r.url;
        replaceBlock(imageTarget); renderInspector();
      }
      markDirty(); toast('Image uploaded.');
    } catch (err) { toast(err.message, 'err'); }
  });

  $('#file-attachment').addEventListener('change', async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    try {
      const r = await post('upload_attachment', { attachment: file });
      state.attachment = { name: r.name, size: r.size };
      toast('Attached ' + r.name + '.');
      if (state.selected < 0) renderInspector();
    } catch (err) { toast(err.message, 'err'); }
    e.target.value = '';
  });

  async function removeAttachment() {
    if (!confirm('Remove the attachment from this email?')) return;
    try { await post('remove_attachment'); state.attachment = null; renderInspector(); toast('Attachment removed.'); }
    catch (err) { toast(err.message, 'err'); }
  }

  // ------------------------------------------------------------ style panel
  function syncStyle() {
    const s = settings();
    $('#s-brand').value = brand();
    $('#s-brand-inherit').checked = !s.brand;
    $('#s-bg').value = s.background || '#f4f4f5';
    $('#s-header').value = s.header_text;
    $('#s-header').placeholder = D.workspace.header || 'Your company';
    const logo = s.logo_url || D.workspace.logo;
    $('#s-logo-preview').innerHTML = logo ? `<img src="${esc(assetUrl(logo))}" alt="">` : '<span class="muted small">None</span>';
  }

  $('#s-brand').addEventListener('input', (e) => { settings().brand = e.target.value; $('#s-brand-inherit').checked = false; markDirty(); renderBlocks(); });
  $('#s-brand-inherit').addEventListener('change', (e) => { if (e.target.checked) { settings().brand = ''; syncStyle(); markDirty(); renderBlocks(); } });
  $('#s-bg').addEventListener('input', (e) => { settings().background = e.target.value; markDirty(); renderHead(); });
  $('#s-header').addEventListener('input', (e) => { settings().header_text = e.target.value; markDirty(); renderHead(); });
  $('#s-logo-btn').addEventListener('click', () => { imageTarget = 'logo'; $('#file-image').value = ''; $('#file-image').click(); });
  $('#s-logo-clear').addEventListener('click', () => { settings().logo_url = ''; markDirty(); renderHead(); syncStyle(); });
  $('#legacy-convert').addEventListener('click', () => {
    settings().layout = 'card'; markDirty(); renderBlocks();
    toast('Now in the branded layout. If it looks doubled, rebuild the content with blocks.');
  });

  // ------------------------------------------------------------ rich text
  $$('#rt button[data-cmd]').forEach((btn) => {
    btn.addEventListener('mousedown', (e) => e.preventDefault()); // keep the text selection
    btn.addEventListener('click', () => {
      if (!restoreSelection()) { toast('Click into some text in the email first.', 'warn'); return; }
      const cmd = btn.dataset.cmd;
      if (cmd === 'link') {
        const url = prompt('Link address (https://…, mailto:…, or {{booking_link}})', 'https://');
        if (!url) return;
        if (!/^(https?:\/\/|mailto:|tel:|\{\{[a-z_]+\}\})/i.test(url.trim())) { toast('Links must start with https://, mailto: or be a field like {{booking_link}}.', 'err'); return; }
        restoreSelection();
        document.execCommand('createLink', false, url.trim());
      } else {
        document.execCommand(cmd, false, null);
      }
      lastEditable.dispatchEvent(new Event('input'));
    });
  });

  const merge = $('#rt-merge');
  D.mergeFields.filter((f) => !['{{signature}}', '{{unsubscribe_link}}'].includes(f)).forEach((f) => merge.append(h('option', { value: f, text: f })));
  merge.addEventListener('change', () => {
    const v = merge.value;
    merge.value = '';
    if (!v) return;
    if (!restoreSelection()) { toast('Click where the field should go first.', 'warn'); return; }
    document.execCommand('insertText', false, v);
    lastEditable.dispatchEvent(new Event('input'));
  });

  // ------------------------------------------------------------ save & preview
  async function save() {
    if (state.saving) return;
    state.name = $('#f-name').value.trim();
    if (!state.name) { toast('Give the design a name (top left).', 'err'); $('#f-name').focus(); throw new Error('Name required'); }
    if (!state.subject.trim()) { state.selected = -1; renderInspector(); toast('Add a subject line before saving.', 'err'); throw new Error('Subject required'); }
    state.saving = true;
    const s = $('#save-state');
    s.textContent = 'Saving…'; s.className = 'cbar-state';
    try {
      const r = await post('save', { name: state.name, subject: state.subject, preheader: state.preheader, design: designPayload() });
      state.dirty = false;
      s.textContent = 'Saved at ' + r.saved_at; s.className = 'cbar-state is-saved';
    } catch (e) {
      s.textContent = 'Not saved'; s.className = 'cbar-state is-dirty';
      toast(e.message, 'err');
      throw e;
    } finally { state.saving = false; }
  }

  $('#btn-save').addEventListener('click', () => save().catch(() => {}));
  $('#f-name').addEventListener('input', markDirty);
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save().catch(() => {}); }
    if (e.key === 'Escape' && !$('#preview-modal').hidden) closePreview();
  });
  window.addEventListener('beforeunload', (e) => { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } });

  async function openPreview() {
    const btn = $('#btn-preview');
    btn.disabled = true;
    try {
      const r = await post('render', { design: designPayload(), subject: state.subject, preheader: state.preheader });
      $('#pv-subject').textContent = r.subject || '(no subject)';
      $('#pv-frame').srcdoc = '<base target="_blank">' + r.html;
      $('#preview-modal').hidden = false;
    } catch (e) { toast(e.message, 'err'); }
    btn.disabled = false;
  }
  function closePreview() { $('#preview-modal').hidden = true; }
  $('#btn-preview').addEventListener('click', openPreview);
  $('#pv-close').addEventListener('click', closePreview);
  $('#preview-modal').addEventListener('click', (e) => { if (e.target.id === 'preview-modal') closePreview(); });
  $$('.modal .seg button').forEach((b) => b.addEventListener('click', () => {
    $$('.modal .seg button').forEach((x) => x.classList.toggle('is-on', x === b));
    $('#pv-frame').style.width = b.dataset.width + 'px';
  }));

  // ------------------------------------------------------------ send
  const sendModal = $('#send-modal');
  let audienceCount = 0;

  async function refreshAudience() {
    $('#sd-count').textContent = '…';
    $('#sd-note').textContent = '';
    $('#sd-go').disabled = true;
    try {
      const r = await post('audience', { status: $('#sd-status').value });
      audienceCount = r.count;
      $('#sd-count').textContent = r.count === 1 ? '1 contact' : r.count.toLocaleString() + ' contacts';
      $('#sd-note').textContent = r.no_email ? ` · ${r.no_email.toLocaleString()} without an email address are skipped` : '';
      $('#sd-go').disabled = r.count === 0;
      $('#sd-go').textContent = r.count ? `Send to ${r.count.toLocaleString()}` : 'Nobody to send to';
    } catch (e) { $('#sd-count').textContent = '—'; toast(e.message, 'err'); }
  }

  $('#btn-send').addEventListener('click', () => { sendModal.hidden = false; refreshAudience(); });
  $('#sd-status').addEventListener('change', refreshAudience);
  const closeSend = () => { sendModal.hidden = true; };
  $('#sd-close').addEventListener('click', closeSend);
  $('#sd-cancel').addEventListener('click', closeSend);
  sendModal.addEventListener('click', (e) => { if (e.target === sendModal) closeSend(); });
  $('#sd-go').addEventListener('click', async () => {
    if (!confirm(`Send "${state.subject}" to ${audienceCount.toLocaleString()} contact(s)?`)) return;
    const go = $('#sd-go');
    go.disabled = true; go.textContent = 'Queuing…';
    try {
      if (state.dirty) await save();
      const r = await post('broadcast', { status: $('#sd-status').value });
      toast(r.message);
      state.dirty = false;
      setTimeout(() => { window.location.href = r.url; }, 1800);
    } catch (e) {
      toast(e.message, 'err');
      go.disabled = false; go.textContent = `Send to ${audienceCount.toLocaleString()}`;
    }
  });

  // ------------------------------------------------------------ boot
  const list = $('#blocklist');
  Object.entries(BLOCKS).forEach(([type, def]) => list.append(
    h('button', { type: 'button', class: 'blocklist-item', onclick: () => add(type) },
      h('span', { class: 'bl-icon', text: def.icon }), h('span', { text: def.label }))));

  $('#f-name').value = state.name;
  syncStyle();
  renderBlocks();
  renderInspector();
})();
