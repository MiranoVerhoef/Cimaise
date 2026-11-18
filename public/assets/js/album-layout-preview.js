(() => {
  const gallery = document.getElementById('images-gallery');
  const templates = document.getElementById('layout-preview-templates');
  if (!gallery || !templates || window.parent === window) return;
  const photos = new Map(Array.from(templates.querySelectorAll('template[data-layout-photo]'),
    template => [Number(template.dataset.layoutPhoto), template]));
  document.documentElement.dataset.layoutEditing = 'true';

  function appendPhotos(container, ids, columns, gap) {
    container.className = 'album-layout-grid';
    container.style.setProperty('--block-columns', columns);
    container.style.setProperty('--block-gap', gap + 'px');
    for (let index = 0; index < ids.length; index += columns) {
      const row = document.createElement('div');
      row.className = 'album-layout-row';
      for (const id of ids.slice(index, index + columns)) {
        const template = photos.get(id);
        if (!template) continue;
        const cell = document.createElement('div');
        cell.className = 'album-layout-cell';
        const image = template.content.querySelector('img');
        const ratio = Number(image?.getAttribute('width')) / Math.max(1, Number(image?.getAttribute('height')));
        cell.style.flexGrow = Math.max(0.1, Math.min(10, ratio || 1));
        cell.append(template.content.cloneNode(true));
        row.append(cell);
      }
      container.append(row);
    }
  }

  function insertionPoint(after, labels) {
    const bar = document.createElement('div');
    bar.className = 'layout-insert-control';
    for (const type of ['photo', 'grid', 'text']) {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.layoutInsert = type;
      button.dataset.after = after || '';
      button.textContent = '+ ' + labels[type];
      bar.append(button);
    }
    return bar;
  }

  function render(layout, selected, labels) {
    gallery.replaceChildren();
    gallery.append(insertionPoint(null, labels));
    const used = new Set();
    for (const block of layout.blocks) {
      const section = document.createElement('section');
      section.className = 'album-layout-block';
      section.dataset.layoutBlock = block.id;
      section.dataset.blockType = block.type;
      section.tabIndex = 0;
      section.setAttribute('aria-label', block.type);
      if (block.id === selected) section.dataset.selected = '';
      section.style.setProperty('--block-width', block.width + '%');
      section.style.setProperty('--block-max', block.max_width ? block.max_width + 'px' : '100%');
      section.style.setProperty('--block-top', block.top + 'px');
      section.style.setProperty('--block-bottom', block.bottom + 'px');
      section.style.setProperty('--block-left', block.align === 'left' ? '0' : 'auto');
      section.style.setProperty('--block-right', block.align === 'right' ? '0' : 'auto');
      if (block.type === 'text') {
        const text = document.createElement('div');
        text.className = 'album-layout-text';
        text.textContent = block.text;
        section.append(text);
      } else {
        const ids = block.images.filter(id => photos.has(id));
        ids.forEach(id => used.add(id));
        const content = document.createElement('div');
        appendPhotos(content, ids, block.type === 'photo' ? 1 : block.columns, block.gap);
        section.append(content);
      }
      gallery.append(section, insertionPoint(block.id, labels));
    }
    if (layout.include_remaining) {
      const section = document.createElement('section');
      section.className = 'album-layout-block';
      section.dataset.layoutRemaining = '';
      const content = document.createElement('div');
      appendPhotos(content, Array.from(photos.keys()).filter(id => !used.has(id)), 3, 8);
      section.append(content);
      gallery.append(section);
    }
  }

  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.source !== window.parent || event.data?.type !== 'cimaise-layout-update') return;
    render(event.data.layout, event.data.selected, event.data.labels);
  });
  gallery.addEventListener('click', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    const insert = event.target.closest('[data-layout-insert]');
    if (insert) {
      window.parent.postMessage({type: 'cimaise-layout-insert', blockType: insert.dataset.layoutInsert, after: insert.dataset.after}, location.origin);
      return;
    }
    const block = event.target.closest('[data-layout-block]');
    if (block) window.parent.postMessage({type: 'cimaise-layout-select', id: block.dataset.layoutBlock}, location.origin);
  }, true);
  gallery.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const block = event.target.closest('[data-layout-block]');
    if (block) {
      event.preventDefault();
      event.stopImmediatePropagation();
      window.parent.postMessage({type: 'cimaise-layout-select', id: block.dataset.layoutBlock}, location.origin);
    }
  });
  window.parent.postMessage({type: 'cimaise-layout-ready'}, location.origin);
})();
