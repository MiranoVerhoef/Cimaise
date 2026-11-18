(() => {
  const gallery = document.getElementById('images-gallery');
  const templates = document.getElementById('layout-preview-templates');
  if (!gallery || !templates || window.parent === window) return;
  const photos = new Map(Array.from(templates.querySelectorAll('template[data-layout-photo]'),
    template => [Number(template.dataset.layoutPhoto), template]));
  document.documentElement.dataset.layoutEditing = 'true';

  const splits = {'equal': [50, 50], '30-70': [30, 70], '70-30': [70, 30]};
  const send = (type, data = {}) => window.parent.postMessage({type: 'cimaise-layout-' + type, ...data}, location.origin);
  let draggedPhoto = null;

  function appendPhotos(container, ids, columns, gap, block = null, selectedRow = null, labels = {}) {
    container.className = 'album-layout-grid';
    container.style.setProperty('--block-gap', gap + 'px');
    for (let index = 0; index < ids.length; index += columns) {
      const row = document.createElement('div');
      row.className = 'album-layout-row';
      const rowIds = ids.slice(index, index + columns);
      const rowIndex = index / columns;
      const ratios = rowIds.map(id => {
        const image = photos.get(id).content.querySelector('img');
        const ratio = Number(image?.getAttribute('width')) / Math.max(1, Number(image?.getAttribute('height')));
        return Math.max(0.1, Math.min(10, ratio || 1));
      });
      const options = rowIds.length === 2 ? block?.rows?.[rowIndex] : null;
      const weights = splits[options?.split] || ratios;
      if (options?.fill) {
        row.dataset.rowFill = '';
        row.style.setProperty('--row-ratio', ratios.reduce((sum, ratio) => sum + ratio, 0));
      }
      if (block?.type === 'grid') {
        row.dataset.layoutRow = rowIndex;
        if (rowIndex === selectedRow) row.dataset.rowSelected = '';
      }
      rowIds.forEach((id, position) => {
        const cell = document.createElement('div');
        cell.className = 'album-layout-cell';
        cell.style.flexGrow = weights[position];
        cell.append(photos.get(id).content.cloneNode(true));
        if (block?.type === 'grid') {
          cell.dataset.layoutImage = id;
          cell.draggable = true;
          const link = cell.querySelector('.gallery-link');
          if (link) {
            link.draggable = false;
            link.setAttribute('aria-description', labels.move_photo_help);
            link.setAttribute('aria-keyshortcuts', 'Alt+ArrowLeft Alt+ArrowRight');
          }
          cell.querySelector('img')?.setAttribute('draggable', 'false');
        }
        row.append(cell);
      });
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

  function render(layout, selected, selectedRow, labels) {
    const active = document.activeElement.closest('[data-layout-image]');
    const focusedPhoto = active?.dataset.layoutImage;
    const focusedBlock = active?.closest('[data-layout-block]')?.dataset.layoutBlock;
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
        appendPhotos(content, ids, block.type === 'photo' ? 1 : block.columns, block.gap,
          block, block.id === selected ? selectedRow : null, labels);
        section.append(content);
      }
      gallery.append(section, insertionPoint(block.id, labels));
    }
    if (layout.include_remaining) {
      const section = document.createElement('section');
      section.className = 'album-layout-block';
      section.dataset.layoutRemaining = '';
      section.tabIndex = 0;
      section.setAttribute('aria-label', labels.grid);
      const content = document.createElement('div');
      appendPhotos(content, Array.from(photos.keys()).filter(id => !used.has(id)), 3, 8);
      section.append(content);
      gallery.append(section);
    }
    if (focusedPhoto && focusedBlock) {
      gallery.querySelector('[data-layout-block="' + CSS.escape(focusedBlock) + '"] [data-layout-image="' + CSS.escape(focusedPhoto) + '"] .gallery-link')
        ?.focus({preventScroll: true});
    }
  }

  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.source !== window.parent || event.data?.type !== 'cimaise-layout-update') return;
    render(event.data.layout, event.data.selected, event.data.selectedRow, event.data.labels);
  });
  gallery.addEventListener('click', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    const insert = event.target.closest('[data-layout-insert]');
    if (insert) {
      window.parent.postMessage({type: 'cimaise-layout-insert', blockType: insert.dataset.layoutInsert, after: insert.dataset.after}, location.origin);
      return;
    }
    if (event.target.closest('[data-layout-remaining]')) {
      window.parent.postMessage({type: 'cimaise-layout-customize-remaining'}, location.origin);
      return;
    }
    const block = event.target.closest('[data-layout-block]');
    if (block) selectBlock(block, event.target);
  }, true);

  function selectBlock(block, target) {
    const row = target.closest('[data-layout-row]');
    send('select', {id: block.dataset.layoutBlock, row: row ? Number(row.dataset.layoutRow) : null});
  }

  gallery.addEventListener('dragstart', event => {
    const cell = event.target.closest('[data-layout-image]');
    if (!cell) return;
    draggedPhoto = cell;
    event.dataTransfer.setData('text/plain', cell.dataset.layoutImage);
    event.dataTransfer.effectAllowed = 'move';
  });
  function dropTarget(event) {
    const cell = event.target.closest('[data-layout-image]');
    return cell && draggedPhoto && cell !== draggedPhoto
      && cell.closest('[data-layout-block]') === draggedPhoto.closest('[data-layout-block]') ? cell : null;
  }
  gallery.addEventListener('dragover', event => {
    if (!dropTarget(event)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
  });
  gallery.addEventListener('drop', event => {
    const cell = dropTarget(event);
    if (!cell) return;
    event.preventDefault();
    send('move-photo', {
      id: cell.closest('[data-layout-block]').dataset.layoutBlock,
      photo: Number(draggedPhoto.dataset.layoutImage), target: Number(cell.dataset.layoutImage),
    });
    draggedPhoto = null;
  });
  gallery.addEventListener('dragend', () => { draggedPhoto = null; });
  gallery.addEventListener('keydown', event => {
    const cell = event.target.closest('[data-layout-image]');
    if (cell && event.altKey && ['ArrowLeft', 'ArrowRight'].includes(event.key)) {
      event.preventDefault();
      send('move-photo', {id: cell.closest('[data-layout-block]').dataset.layoutBlock,
        photo: Number(cell.dataset.layoutImage), offset: event.key === 'ArrowLeft' ? -1 : 1});
      return;
    }
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (event.target.closest('[data-layout-remaining]')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      window.parent.postMessage({type: 'cimaise-layout-customize-remaining'}, location.origin);
      return;
    }
    const block = event.target.closest('[data-layout-block]');
    if (block) {
      event.preventDefault();
      event.stopImmediatePropagation();
      selectBlock(block, event.target);
    }
  });
  window.parent.postMessage({type: 'cimaise-layout-ready'}, location.origin);
})();
