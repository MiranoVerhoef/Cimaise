(() => {
  const root = document.getElementById('album-layout-editor');
  if (!root || root.dataset.initialized) return;
  root.dataset.initialized = 'true';
  window.cimaiseAlbumEditor?.abort();
  const events = new AbortController();
  window.cimaiseAlbumEditor = events;
  const boot = JSON.parse(document.getElementById('layout-editor-data').textContent);
  const labels = boot.labels;
  const initial = boot.state.draft || boot.state.published || {version: 1, blocks: [], include_remaining: true};
  let layout = structuredClone(initial);
  let revision = boot.state.revision;
  let customPublished = Boolean(boot.state.published);
  let selected = layout.blocks[0]?.id || null;
  let savedSnapshot = JSON.stringify(initial);
  let history = [JSON.stringify(initial)];
  let historyIndex = 0;
  let saving = false;
  let pickerType = null;
  let pickerIds = new Set();
  let dragged = null;
  const frame = document.getElementById('layout-preview');
  const viewport = document.getElementById('layout-preview-viewport');
  function resizePreview() {
    const width = {desktop: 1440, tablet: 768, mobile: 390}[frame.dataset.device || 'desktop'];
    const scale = Math.min(1, viewport.clientWidth / width);
    if (scale <= 0) return;
    frame.style.width = width + 'px';
    frame.style.height = Math.ceil(viewport.clientHeight / scale) + 'px';
    frame.style.transform = 'scale(' + scale + ')';
    frame.style.left = Math.max(0, (viewport.clientWidth - width * scale) / 2) + 'px';
  }
  const resizeObserver = new ResizeObserver(resizePreview);
  resizeObserver.observe(viewport);
  events.signal.addEventListener('abort', () => resizeObserver.disconnect());
  const controls = document.getElementById('layout-block-controls');
  const list = document.getElementById('layout-block-list');
  const status = document.getElementById('layout-editor-status');
  const picker = document.getElementById('layout-photo-picker');
  const search = document.getElementById('layout-photo-search');
  const photos = boot.images.map(photo => ({...photo, id: Number(photo.id)}));
  const remaining = document.getElementById('layout-remaining');
  const action = name => root.querySelector('[data-action="' + name + '"]');
  const current = () => layout.blocks.find(block => block.id === selected);
  const dirty = () => JSON.stringify(layout) !== savedSnapshot;

  function showStatus(message, error = false) {
    status.textContent = message;
    status.toggleAttribute('data-error', error);
  }

  function preview() {
    frame.contentWindow?.postMessage({type: 'cimaise-layout-update', layout, selected, labels}, location.origin);
  }

  function refresh() {
    root.querySelector('.layout-editor-workspace').inert = saving;
    if (!current()) selected = layout.blocks[0]?.id || null;
    const block = current();
    controls.hidden = !block;
    list.replaceChildren();
    for (const item of layout.blocks) {
      const li = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.select = item.id;
      button.draggable = true;
      button.setAttribute('aria-pressed', String(item.id === selected));
      button.textContent = labels[item.type] + (item.type === 'text' ? ' — ' + item.text.slice(0, 32) : ' (' + item.images.length + ')');
      li.append(button);
      list.append(li);
    }
    if (block) {
      document.getElementById('layout-selected-heading').textContent = labels[block.type];
      root.querySelectorAll('[data-prop]').forEach(input => {
        if (document.activeElement !== input) input.value = block[input.dataset.prop] ?? '';
      });
      document.getElementById('layout-width-value').textContent = block.width + '%';
      document.getElementById('layout-grid-controls').hidden = block.type !== 'grid';
      document.getElementById('layout-text-control').hidden = block.type !== 'text';
      document.getElementById('layout-choose').hidden = block.type === 'text';
      action('up').disabled = layout.blocks[0].id === selected;
      action('down').disabled = layout.blocks.at(-1).id === selected;
    }
    remaining.checked = layout.include_remaining;
    action('undo').disabled = saving || historyIndex === 0;
    action('redo').disabled = saving || historyIndex === history.length - 1;
    action('default').disabled = saving || !customPublished;
    action('draft').disabled = saving;
    action('publish').disabled = saving;
    showStatus(dirty() ? labels.unsaved : customPublished ? labels.custom_status : labels.default_status);
    preview();
  }

  function remember() {
    const snapshot = JSON.stringify(layout);
    if (snapshot !== history[historyIndex]) {
      history = history.slice(0, historyIndex + 1);
      history.push(snapshot);
      if (history.length > 100) history.shift();
      historyIndex = history.length - 1;
    }
    refresh();
  }

  function insert(block, after = selected) {
    const index = layout.blocks.findIndex(item => item.id === after);
    layout.blocks.splice(index + 1, 0, block);
    selected = block.id;
    remember();
  }

  function newBlock(type, images = []) {
    const randomBytes = crypto.getRandomValues(new Uint8Array(16));
    const id = Array.from(randomBytes, byte => byte.toString(16).padStart(2, '0')).join('');
    return {
      id, type, images,
      width: 100, max_width: 0, align: 'center', top: 0, bottom: 20,
      columns: 3, gap: 8, text: '',
    };
  }

  function renderPicker() {
    const container = document.getElementById('layout-picker-photos');
    container.replaceChildren();
    const query = search.value.trim().toLowerCase();
    for (const photo of photos) {
      const name = photo.alt_text || photo.caption || '#' + photo.id;
      if (query && !name.toLowerCase().includes(query) && !String(photo.id).includes(query)) continue;
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = pickerType === 'photo' ? 'radio' : 'checkbox';
      input.name = 'layout-photo-selection';
      input.value = photo.id;
      input.checked = pickerIds.has(photo.id);
      const image = document.createElement('img');
      image.alt = '';
      image.loading = 'lazy';
      if (photo.preview) image.src = photo.preview.startsWith('/') ? boot.basePath + photo.preview : photo.preview;
      const caption = document.createElement('span');
      caption.textContent = name;
      label.append(input, image, caption);
      container.append(label);
    }
    document.getElementById('layout-picker-count').textContent = pickerIds.size + ' ' + labels.selected;
    action('apply-picker').disabled = pickerIds.size === 0;
  }

  function openPicker(type, existing = false, after = selected) {
    pickerType = type;
    picker.dataset.existing = String(existing);
    picker.dataset.after = after || '';
    pickerIds = new Set(existing ? current().images : []);
    search.value = '';
    renderPicker();
    picker.showModal();
    search.focus();
  }

  async function save(mode) {
    if (saving) return;
    if (mode === 'default' && !confirm(labels.default_confirm)) return;
    saving = true;
    refresh();
    showStatus(labels.saving);
    try {
      const tokenResponse = await fetch(boot.basePath + '/admin/csrf-token', {cache: 'no-store', headers: {Accept: 'application/json'}});
      if (!tokenResponse.ok) throw new Error('Session expired. Reload and sign in again.');
      const token = await tokenResponse.json();
      const response = await fetch(root.dataset.endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token.token},
        body: JSON.stringify({csrf: token.token, action: mode, revision, layout}),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'Unable to save the layout.');
      revision = payload.revision;
      if (mode !== 'default') {
        layout = payload.layout;
        savedSnapshot = JSON.stringify(layout);
        history = [savedSnapshot];
        historyIndex = 0;
      }
      if (mode === 'publish') customPublished = true;
      if (mode === 'default') customPublished = false;
      saving = false;
      refresh();
      showStatus(mode === 'publish' ? labels.published : mode === 'draft' ? labels.saved : labels.default_status);
    } catch (error) {
      saving = false;
      refresh();
      showStatus(error.message, true);
    }
  }

  root.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button || saving) return;
    if (button.dataset.select) {
      selected = button.dataset.select;
      refresh();
      return;
    }
    if (button.dataset.add) {
      if (button.dataset.add === 'text') insert(newBlock('text'));
      else openPicker(button.dataset.add);
      return;
    }
    if (button.dataset.device) {
      frame.dataset.device = button.dataset.device;
      resizePreview();
      root.querySelectorAll('[data-device]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
      return;
    }
    const mode = button.dataset.action;
    if (['draft', 'publish', 'default'].includes(mode)) { save(mode); return; }
    if (mode === 'undo' || mode === 'redo') {
      historyIndex += mode === 'undo' ? -1 : 1;
      layout = JSON.parse(history[historyIndex]);
      refresh();
    } else if (mode === 'choose' && current()) {
      openPicker(current().type, true);
    } else if (mode === 'cancel-picker') {
      picker.close();
    } else if (mode === 'apply-picker') {
      const ids = Array.from(pickerIds);
      if (picker.dataset.existing === 'true') {
        current().images = ids;
        remember();
      } else insert(newBlock(pickerType, ids), picker.dataset.after);
      picker.close();
    } else if (mode === 'remove' && current()) {
      layout.blocks = layout.blocks.filter(block => block.id !== selected);
      remember();
    } else if ((mode === 'up' || mode === 'down') && current()) {
      const index = layout.blocks.findIndex(block => block.id === selected);
      const destination = index + (mode === 'up' ? -1 : 1);
      if (destination < 0 || destination >= layout.blocks.length) return;
      const [block] = layout.blocks.splice(index, 1);
      layout.blocks.splice(destination, 0, block);
      remember();
    }
  });

  controls.addEventListener('input', event => {
    const input = event.target;
    const block = current();
    if (!block || !input.dataset.prop || !input.checkValidity()) return;
    block[input.dataset.prop] = ['text', 'align'].includes(input.dataset.prop) ? input.value : Number(input.value || 0);
    document.getElementById('layout-width-value').textContent = block.width + '%';
    showStatus(labels.unsaved);
    preview();
  });
  controls.addEventListener('change', remember);
  remaining.addEventListener('change', () => { layout.include_remaining = remaining.checked; remember(); });
  search.addEventListener('input', renderPicker);
  picker.addEventListener('change', event => {
    if (event.target.name !== 'layout-photo-selection') return;
    const id = Number(event.target.value);
    if (pickerType === 'photo') pickerIds.clear();
    if (event.target.checked) pickerIds.add(id);
    else pickerIds.delete(id);
    document.getElementById('layout-picker-count').textContent = pickerIds.size + ' ' + labels.selected;
    action('apply-picker').disabled = pickerIds.size === 0;
  });
  list.addEventListener('dragstart', event => {
    const button = event.target.closest('[data-select]');
    if (!button || saving) return;
    dragged = button.dataset.select;
    event.dataTransfer.setData('text/plain', dragged);
    event.dataTransfer.effectAllowed = 'move';
  });
  list.addEventListener('dragover', event => { if (dragged) event.preventDefault(); });
  list.addEventListener('drop', event => {
    const destination = event.target.closest('[data-select]')?.dataset.select;
    if (!dragged || !destination || dragged === destination) return;
    event.preventDefault();
    const from = layout.blocks.findIndex(block => block.id === dragged);
    const to = layout.blocks.findIndex(block => block.id === destination);
    const [block] = layout.blocks.splice(from, 1);
    layout.blocks.splice(to, 0, block);
    selected = dragged;
    dragged = null;
    remember();
  });
  list.addEventListener('dragend', () => { dragged = null; });
  frame.addEventListener('load', preview);
  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.source !== frame.contentWindow) return;
    if (event.data?.type === 'cimaise-layout-ready') preview();
    if (event.data?.type === 'cimaise-layout-insert' && !saving && ['photo', 'grid', 'text'].includes(event.data.blockType)) {
      if (event.data.blockType === 'text') insert(newBlock('text'), event.data.after);
      else openPicker(event.data.blockType, false, event.data.after);
    }
    if (event.data?.type === 'cimaise-layout-select' && layout.blocks.some(block => block.id === event.data.id)) {
      selected = event.data.id;
      refresh();
    }
  }, {signal: events.signal});
  window.addEventListener('beforeunload', event => {
    if (root.isConnected && dirty()) { event.preventDefault(); event.returnValue = ''; }
  }, {signal: events.signal});
  refresh();
})();
