(function () {
  const sectionSelector = '.app-section';
  const tabSelector = '.native-bottom-tab[data-section]';
  const mobileQuery = window.matchMedia('(max-width: 1023px)');

  function enhanceToasts() {
    const toast = document.querySelector('.toast');
    if (!toast) return;
    window.setTimeout(() => {
      toast.style.transition = 'opacity .2s ease, transform .2s ease';
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-8px)';
      window.setTimeout(() => toast.remove(), 220);
    }, 3800);
  }

  function getSections() {
    return Array.from(document.querySelectorAll(sectionSelector));
  }

  function getTabs() {
    return Array.from(document.querySelectorAll(tabSelector));
  }

  function isMobileNavActive() {
    return mobileQuery.matches;
  }

  function isAccountTab(tab) {
    return tab?.dataset?.account === 'true';
  }

  function setActiveTab(sectionId) {
    getTabs().forEach((tab) => {
      const active = tab.dataset.section === sectionId;
      tab.classList.toggle('native-bottom-tab--active', active);
    });
  }

  function showSection(id) {
    if (!isMobileNavActive()) {
      return;
    }
    const sections = getSections();
    if (!sections.length) return;

    let nextId = id;
    const target = nextId ? document.getElementById(nextId) : null;
    const firstSection = sections[0];

    if (!target) {
      nextId = firstSection?.id || '';
    }

    sections.forEach((section) => {
      const visible = section.id === nextId;
      section.hidden = !visible;
      section.classList.toggle('app-section--active', visible);
      section.classList.toggle('app-section--fade-in', visible);
      if (visible) {
        window.requestAnimationFrame(() => {
          section.classList.remove('app-section--fade-in');
        });
      }
    });

    setActiveTab(nextId);
    if (nextId && window.location.hash !== `#${nextId}`) {
      history.replaceState(null, '', `#${nextId}`);
    }
  }

  function openAccountSheet() {
    if (!isMobileNavActive()) return;
    const sheet = document.getElementById('account-sheet');
    if (!sheet) return;
    sheet.hidden = false;
    sheet.setAttribute('aria-hidden', 'false');
  }

  function closeAccountSheet() {
    if (!isMobileNavActive()) return;
    const sheet = document.getElementById('account-sheet');
    if (!sheet) return;
    if (sheet.contains(document.activeElement)) {
      document.activeElement?.blur?.();
    }
    sheet.hidden = true;
    sheet.setAttribute('aria-hidden', 'true');
  }

  function openMoreSheet() {
    if (!isMobileNavActive()) return;
    const sheet = document.getElementById('more-sheet');
    if (!sheet) return;
    sheet.hidden = false;
    sheet.setAttribute('aria-hidden', 'false');
  }

  function closeMoreSheet() {
    if (!isMobileNavActive()) return;
    const sheet = document.getElementById('more-sheet');
    if (!sheet) return;
    if (sheet.contains(document.activeElement)) {
      document.activeElement?.blur?.();
    }
    sheet.hidden = true;
    sheet.setAttribute('aria-hidden', 'true');
  }

  function shouldHandleLink(link) {
    return Boolean(
      link?.dataset?.section ||
      link?.dataset?.account ||
      (link?.tagName === 'A' && /[?&]section=/.test(link.getAttribute('href') || ''))
    );
  }

  function sectionExists(id) {
    return Boolean(id && document.getElementById(id));
  }

  function linkHasExtraQueryState(link) {
    try {
      const url = new URL(link.getAttribute('href') || '', window.location.href);
      const params = url.searchParams;
      const allowed = new Set(['page', 'section']);
      for (const key of params.keys()) {
        if (!allowed.has(key)) return true;
      }
      return false;
    } catch (error) {
      return false;
    }
  }

  document.addEventListener('click', (event) => {
    if (!isMobileNavActive()) {
      return;
    }
    const tab = event.target.closest(tabSelector);
    if (tab) {
      event.preventDefault();
      if (isAccountTab(tab)) {
        openAccountSheet();
        return;
      }
      if (tab.dataset.more === 'true') {
        openMoreSheet();
        return;
      }
      showSection(tab.dataset.section);
      return;
    }

    const link = event.target.closest('a[data-section], a[data-account], a[href*="section="]');
    if (link && shouldHandleLink(link)) {
      if (link.dataset.account === 'true') {
        event.preventDefault();
        openAccountSheet();
        return;
      }
      if (link.dataset.more === 'true') {
        event.preventDefault();
        openMoreSheet();
        return;
      }
      if (linkHasExtraQueryState(link)) {
        return;
      }
      const targetSection = link.dataset.section || new URL(link.href, window.location.href).searchParams.get('section');
      if (sectionExists(targetSection)) {
        event.preventDefault();
        showSection(targetSection);
      }
    }
  });

  document.addEventListener('click', (event) => {
    if (!isMobileNavActive()) {
      return;
    }
    if (event.target.closest('[data-account-close]')) {
      closeAccountSheet();
    }
    if (event.target.closest('[data-more-close]')) {
      closeMoreSheet();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (!isMobileNavActive()) {
      return;
    }
    if (event.key === 'Escape') {
      closeAccountSheet();
      closeMoreSheet();
    }
  });

  window.addEventListener('hashchange', () => {
    if (!isMobileNavActive()) {
      return;
    }
    const sectionId = window.location.hash.replace('#', '');
    if (sectionId) {
      showSection(sectionId);
    }
  });

  window.addEventListener('DOMContentLoaded', () => {
    enhanceToasts();
    if (!isMobileNavActive()) {
      return;
    }
    const sections = getSections();
    if (!sections.length) return;

    const urlSection = new URL(window.location.href).searchParams.get('section') || '';
    const hashSection = window.location.hash.replace('#', '');
    const knownSection =
      (hashSection && document.getElementById(hashSection) && hashSection) ||
      (urlSection && document.getElementById(urlSection) && urlSection) ||
      sections[0].id;
    showSection(knownSection);
    window.__showSection = showSection;
    window.__openAccountSheet = openAccountSheet;
    window.__openMoreSheet = openMoreSheet;
  });
})();
