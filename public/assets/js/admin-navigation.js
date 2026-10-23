/* Keep plugin anchors and existing URLs intact when grouping the admin menu. */
(() => {
  if (window.AdminNavigation) return;
  let groups = [];
  let entries = [];
  let source;
  let heading;
  let tabs;
  let basePath = '';

  const normalize = path => path.replace(/\/+$/, '') || '/';
  const matches = (path, route) => {
    if (route.includes('#')) return path === route;
    const pathname = path.split('#')[0];
    return pathname === route || (route !== `${basePath}/admin` && pathname.startsWith(`${route}/`));
  };

  function init() {
    if (groups.length) return;
    const primary = document.getElementById('admin-primary-navigation');
    source = document.getElementById('admin-navigation-source');
    heading = document.getElementById('admin-section-title');
    tabs = document.getElementById('admin-section-tabs');
    if (!primary || !source || !heading || !tabs) return;
    basePath = normalize(primary.dataset.basePath || '') === '/' ? '' : normalize(primary.dataset.basePath);
    groups = Array.from(primary.querySelectorAll('[data-nav-group]')).map(link => ({
      id: link.dataset.navGroup,
      label: link.textContent.trim(),
      link,
      routes: JSON.parse(link.dataset.navRoutes).map(route => `${basePath}${route}`)
    }));
    entries = Array.from(source.querySelectorAll('a[href]')).map(link => {
      const url = new URL(link.href, location.href);
      const route = normalize(url.pathname) + url.hash;
      // Unknown plugin menus remain available under System; metadata hooks keep their section.
      const group = groups.find(item => item.routes.some(prefix => matches(route, prefix)))
        || groups.find(item => item.id === link.closest('[data-nav-fallback]')?.dataset.navFallback)
        || groups.find(item => item.id === 'system');
      link.classList.add('admin-section-tab');
      if (group.id === 'dashboard' || group.id === 'gallery') {
        const title = link.querySelector('.nav-title');
        const subtitle = link.querySelector('.nav-sub');
        if (title && subtitle) title.textContent = subtitle.textContent;
      }
      return { link, route, group };
    }).sort((a, b) => {
      const rank = item => {
        const index = item.group.routes.indexOf(item.route);
        return index < 0 ? item.group.routes.length : index;
      };
      return groups.indexOf(a.group) - groups.indexOf(b.group) || rank(a) - rank(b);
    });
    source.hidden = true;
    primary.hidden = false;
    document.getElementById('admin-section-navigation').hidden = false;
    update(location.href);
  }

  function update(href) {
    if (!groups.length) return;
    const url = new URL(href, location.href);
    const currentPath = normalize(url.pathname) + url.hash;
    const selected = entries.filter(item => matches(currentPath, item.route)).sort((a, b) => b.route.length - a.route.length)[0];
    const group = selected?.group || groups.find(item => item.id === 'system');
    heading.textContent = group.label;
    groups.forEach(item => {
      item.link.classList.toggle('active', item === group);
      if (item === group) item.link.setAttribute('aria-current', 'true');
      else item.link.removeAttribute('aria-current');
    });
    entries.forEach(item => {
      const parent = item.group === group ? tabs : source;
      if (item.link.parentElement !== parent) parent.appendChild(item.link);
      item.link.classList.toggle('active', item === selected);
      if (item === selected) item.link.setAttribute('aria-current', 'page');
      else item.link.removeAttribute('aria-current');
    });
    if (selected) {
      const link = selected.link;
      const offset = link.offsetLeft - tabs.offsetLeft;
      if (offset < tabs.scrollLeft) tabs.scrollLeft = offset;
      else if (offset + link.offsetWidth > tabs.scrollLeft + tabs.clientWidth) tabs.scrollLeft = offset + link.offsetWidth - tabs.clientWidth;
    }
  }

  window.AdminNavigation = { init, update };
  // This deferred script needs no widget bundle; hydrate page tools as soon as the DOM is parsed.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
