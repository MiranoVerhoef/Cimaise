/* Shared header placement; search submission and suggestions stay with each template. */
(() => {
  const init = () => {
    const panel = document.getElementById('header-search-panel');
    const input = document.getElementById('header-search');
    const toggles = ['header-search-toggle', 'mobile-search-toggle']
      .map(id => document.getElementById(id)).filter(Boolean);
    if (!panel || !input || !toggles.length) return;
    const mobile = matchMedia('(max-width: 767px)');
    let row, opener, hiddenControls = [];
    panel.classList.add('inline-header-search');
    // The closed form must not be reachable by keyboard or assistive technology.
    panel.inert = true;
    const close = (restoreFocus = false) => {
      panel.classList.add('hidden');
      panel.inert = true;
      row?.classList.remove('search-open');
      hiddenControls.forEach(([element, wasInert]) => { element.inert = wasInert; });
      hiddenControls = [];
      toggles.forEach(toggle => {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.querySelector('i')?.classList.replace('fa-times', 'fa-search');
      });
      if (restoreFocus) opener?.focus();
    };
    const place = () => {
      close();
      const toggle = toggles.find(element => element.getClientRects().length);
      row = toggle?.closest('.site-search-row');
      if (!row) return;
      row.appendChild(panel);
      const brand = row.firstElementChild;
      const actions = row.children[1];
      row.classList.add('has-inline-search');
      brand.classList.add('search-brand-navigation');
      actions.classList.add('search-header-actions');
    };
    const open = toggle => {
      if (!row) return;
      opener = toggle;
      const bounds = row.getBoundingClientRect();
      const brand = row.firstElementChild.firstElementChild || row.firstElementChild;
      const actions = row.children[1].getBoundingClientRect();
      // Mobile temporarily gives the logo's space to search; the bar never grows.
      const left = mobile.matches ? 0 : Math.max(0, brand.getBoundingClientRect().right - bounds.left + 24);
      const right = mobile.matches ? 52 : Math.max(0, bounds.right - actions.left + 16);
      row.style.setProperty('--search-left', left + 'px');
      row.style.setProperty('--search-right', right + 'px');
      const menu = document.getElementById('mobile-menu');
      if (menu && !menu.classList.contains('hidden')) {
        menu.classList.add('hidden');
        menu.style.maxHeight = '0px';
        menu.style.opacity = '0';
        document.getElementById('mobile-menu-toggle')?.setAttribute('aria-expanded', 'false');
        document.documentElement.classList.remove('mobile-menu-open');
      }
      row.classList.add('search-open');
      const obscured = mobile.matches
        ? [...row.querySelectorAll('a, button')].filter(element => !panel.contains(element) && !toggles.includes(element))
        : [...row.querySelectorAll('nav')];
      hiddenControls = obscured.map(element => [element, element.inert]);
      hiddenControls.forEach(([element]) => { element.inert = true; });
      panel.classList.remove('hidden');
      panel.inert = false;
      toggles.forEach(element => {
        element.setAttribute('aria-expanded', 'true');
        element.querySelector('i')?.classList.replace('fa-search', 'fa-times');
      });
      input.focus({ preventScroll: true });
    };
    toggles.forEach(toggle => toggle.addEventListener('click', () => {
      if (panel.classList.contains('hidden')) open(toggle); else close(true);
    }));
    document.addEventListener('click', event => {
      if (!panel.contains(event.target) && !toggles.some(toggle => toggle.contains(event.target))) close();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !panel.classList.contains('hidden')) {
        event.preventDefault();
        close(true);
      }
    });
    mobile.addEventListener('change', place);
    // Recalculate available space on resize, without leaving a stale open overlay.
    window.addEventListener('resize', () => { if (!panel.classList.contains('hidden')) close(); });
    window.CimaiseHeaderSearch = { close };
    place();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
