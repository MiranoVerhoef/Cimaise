(() => {
  const gallery = document.getElementById('images-gallery');
  const templates = document.getElementById('layout-preview-templates');
  if (!gallery || !templates || window.parent === window) return;
  const photos = new Map(Array.from(templates.querySelectorAll('template[data-layout-photo]'),
    template => [Number(template.dataset.layoutPhoto), template]));
  document.documentElement.dataset.layoutEditing = 'true';

  const splits = {'equal': [50, 50], '30-70': [30, 70], '70-30': [70, 30]};
  const send = (type, data = {}) => window.parent.postMessage({type: 'cimaise-layout-' + type, ...data}, location.origin);
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');
  const gridOptions = new WeakMap();
  let drag = null;
  let suppressClick = false;

  function arrangeRows(grid, cells) {
    const {columns, block, selectedRow} = gridOptions.get(grid);
    const rows = [...grid.children];
    for (let index = 0; index < cells.length; index += columns) {
      const rowIndex = index / columns;
      const row = rows[rowIndex] || grid.appendChild(document.createElement('div'));
      row.className = 'album-layout-row';
      const rowCells = cells.slice(index, index + columns);
      const options = rowCells.length === 2 ? block?.rows?.[rowIndex] : null;
      const ratios = rowCells.map(cell => Number(cell.dataset.photoRatio));
      const weights = splits[options?.split] || ratios;
      row.toggleAttribute('data-row-fill', Boolean(options?.fill));
      if (options?.fill) row.style.setProperty('--row-ratio', ratios.reduce((sum, ratio) => sum + ratio, 0));
      if (block?.type === 'grid') {
        row.dataset.layoutRow = rowIndex;
        row.toggleAttribute('data-row-selected', rowIndex === selectedRow);
      }
      rowCells.forEach((cell, position) => {
        cell.style.flexGrow = weights[position];
        if (row.children[position] !== cell) row.insertBefore(cell, row.children[position] || null);
      });
    }
  }

  function appendPhotos(container, ids, columns, gap, block = null, selectedRow = null, labels = {}) {
    container.className = 'album-layout-grid';
    container.style.setProperty('--block-gap', gap + 'px');
    gridOptions.set(container, {columns, block, selectedRow});
    const cells = ids.map(id => {
      const cell = document.createElement('div');
      cell.className = 'album-layout-cell';
      cell.append(photos.get(id).content.cloneNode(true));
      const image = cell.querySelector('img');
      const ratio = Number(image?.getAttribute('width')) / Math.max(1, Number(image?.getAttribute('height')));
      cell.dataset.photoRatio = Math.max(0.1, Math.min(10, ratio || 1));
      if (block?.type === 'grid') {
        cell.dataset.layoutImage = id;
        const link = cell.querySelector('.gallery-link');
        if (link) {
          link.draggable = false;
          link.setAttribute('aria-description', labels.move_photo_help);
          link.setAttribute('aria-keyshortcuts', 'Alt+ArrowLeft Alt+ArrowRight');
        }
        if (image) image.draggable = false;
      }
      return cell;
    });
    arrangeRows(container, cells);
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
    if (drag) finishDrag(false);
    render(event.data.layout, event.data.selected, event.data.selectedRow, event.data.labels);
  });
  gallery.addEventListener('click', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    if (suppressClick && event.detail) return;
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

  gallery.addEventListener('pointerdown', event => {
    suppressClick = false;
    if (drag || !event.isPrimary || event.button !== 0 || event.pointerType === 'touch') return;
    const cell = event.target.closest('[data-layout-image]');
    if (!cell) return;
    const grid = cell.closest('.album-layout-grid');
    const cells = [...grid.querySelectorAll('[data-layout-image]')];
    if (cells.length < 2) return;
    const index = cells.indexOf(cell);
    drag = {pointer: event.pointerId, cell, grid, cells, index, destination: index,
      startX: event.clientX, startY: event.clientY, ghost: null};
  });

  function moveCells(grid, cells) {
    const previous = cells.map(cell => cell.getBoundingClientRect());
    cells.forEach(cell => cell.getAnimations().forEach(animation => animation.cancel()));
    arrangeRows(grid, cells);
    if (reduceMotion.matches) return;
    cells.forEach((cell, index) => {
      const rect = cell.getBoundingClientRect();
      const x = previous[index].left - rect.left;
      const y = previous[index].top - rect.top;
      if (x || y) cell.animate([{transform: 'translate(' + x + 'px, ' + y + 'px)'}, {transform: 'none'}],
        {duration: 180, easing: 'ease-out'});
    });
  }

  function startDrag(event) {
    const bounds = drag.grid.getBoundingClientRect();
    // Keep hit targets at their original positions while images of different sizes reflow.
    drag.originalHeight = bounds.height;
    drag.slots = drag.cells.map(cell => {
      const rect = cell.getBoundingClientRect();
      return {x: rect.left + rect.width / 2 - bounds.left, top: rect.top - bounds.top, bottom: rect.bottom - bounds.top};
    });
    const rect = drag.cell.getBoundingClientRect();
    const ghost = document.createElement('div');
    ghost.className = 'album-layout-drag-image';
    ghost.setAttribute('aria-hidden', 'true');
    const image = drag.cell.querySelector('img');
    if (image) {
      const copy = document.createElement('img');
      copy.src = image.currentSrc || image.src;
      copy.alt = '';
      ghost.append(copy);
    }
    ghost.style.width = rect.width + 'px';
    ghost.style.height = rect.height + 'px';
    drag.offsetX = drag.startX - rect.left;
    drag.offsetY = drag.startY - rect.top;
    drag.ghost = document.body.appendChild(ghost);
    drag.cell.dataset.layoutDragging = '';
    gallery.setPointerCapture(event.pointerId);
    suppressClick = true;
  }

  window.addEventListener('pointermove', event => {
    if (!drag || event.pointerId !== drag.pointer) return;
    if (!event.buttons) { finishDrag(false); return; }
    if (!drag.ghost) {
      if (Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) < 5) return;
      startDrag(event);
    }
    event.preventDefault();
    drag.ghost.style.transform = 'translate(' + (event.clientX - drag.offsetX) + 'px, ' + (event.clientY - drag.offsetY) + 'px)';
    const bounds = drag.grid.getBoundingClientRect();
    const x = event.clientX - bounds.left;
    const y = event.clientY - bounds.top;
    let destination = drag.destination;
    let bestDistance = Infinity;
    for (const [index, slot] of drag.slots.entries()) {
      const vertical = Math.max(slot.top - y, y - slot.bottom, 0);
      const distance = Math.hypot(slot.x - x, vertical * 2);
      if (distance < bestDistance) {
        bestDistance = distance;
        destination = index;
      }
    }
    if (destination === drag.destination) return;
    drag.destination = destination;
    const cells = [...drag.cells];
    cells.splice(drag.index, 1);
    cells.splice(destination, 0, drag.cell);
    moveCells(drag.grid, cells);
  });

  function finishDrag(commit) {
    const current = drag;
    drag = null;
    if (!current?.ghost) return;
    if (gallery.hasPointerCapture(current.pointer)) gallery.releasePointerCapture(current.pointer);
    current.ghost.remove();
    delete current.cell.dataset.layoutDragging;
    if (commit && current.destination !== current.index) {
      send('move-photo', {
        id: current.grid.closest('[data-layout-block]').dataset.layoutBlock,
        photo: Number(current.cell.dataset.layoutImage),
        target: Number(current.cells[current.destination].dataset.layoutImage),
      });
    } else if (current.destination !== current.index) {
      moveCells(current.grid, current.cells);
    }
  }

  window.addEventListener('pointerup', event => {
    if (event.pointerId !== drag?.pointer) return;
    const bounds = drag.grid.getBoundingClientRect();
    finishDrag(event.clientX >= bounds.left && event.clientX <= bounds.right
      && event.clientY >= bounds.top && event.clientY <= bounds.top + Math.max(bounds.height, drag.originalHeight || 0));
  });
  window.addEventListener('pointercancel', event => {
    if (event.pointerId === drag?.pointer) finishDrag(false);
  });
  window.addEventListener('blur', () => finishDrag(false));
  window.addEventListener('keydown', event => {
    if (event.key === 'Escape' && drag?.ghost) {
      event.preventDefault();
      event.stopImmediatePropagation();
      finishDrag(false);
    }
  }, true);
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
