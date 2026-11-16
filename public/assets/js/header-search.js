/* Shared header placement; search submission and suggestions stay with each template. */
(() => {
  const init = () => {
    const panel = document.getElementById('header-search-panel');
    const input = document.getElementById('header-search');
    const toggles = ['header-search-toggle', 'mobile-search-toggle']
      .map(id => document.getElementById(id)).filter(Boolean);
    if (!panel || !input || !toggles.length) return;
    const mobile = matchMedia('(max-width: 767px)');
    let row, opener;
    panel.classList.add('inline-header-search');
    // The closed form must not be reachable by keyboard or assistive technology.
    panel.inert = true;
    const close = (restoreFocus = false) => {
      panel.classList.add('hidden');
      panel.inert = true;
      row?.classList.remove('search-open');
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
    const measure = () => {
      if (!row) return;
      const bounds = row.getBoundingClientRect();
      const navigation = row.firstElementChild;
      const brand = navigation.matches('a') ? navigation : navigation.firstElementChild;
      const actions = row.children[1].getBoundingClientRect();
      const toggle = toggles.find(element => element.closest('.site-search-row') === row && element.getClientRects().length);
      const visibleNav = [...navigation.querySelectorAll('nav')].filter(element => element.getClientRects().length);
      const navigationEnd = Math.max(brand.getBoundingClientRect().right, ...visibleNav.map(element => element.getBoundingClientRect().right));
      const available = actions.left - navigationEnd - 24;
      const popover = mobile.matches || available < 180;
      // Never cover navigation: use a small anchored popover when inline space is tight.
      const width = popover ? Math.min(280, bounds.width) : Math.min(240, available);
      const right = popover ? Math.max(0, Math.min(bounds.width - width, bounds.right - toggle.getBoundingClientRect().right)) : bounds.right - actions.left + 12;
      panel.classList.toggle('search-popover', popover);
      row.style.setProperty('--search-width', width + 'px');
      row.style.setProperty('--search-right', right + 'px');
    };
    const open = toggle => {
      if (!row) return;
      opener = toggle;
      measure();
      const menu = document.getElementById('mobile-menu');
      if (menu && !menu.classList.contains('hidden')) {
        menu.classList.add('hidden');
        menu.style.maxHeight = '0px';
        menu.style.opacity = '0';
        document.getElementById('mobile-menu-toggle')?.setAttribute('aria-expanded', 'false');
        document.documentElement.classList.remove('mobile-menu-open');
      }
      row.classList.add('search-open');
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
    // Keep the field open when a mobile keyboard changes the viewport height.
    window.addEventListener('resize', measure);
    window.CimaiseHeaderSearch = { close };
    place();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
