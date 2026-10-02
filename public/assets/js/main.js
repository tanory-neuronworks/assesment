(function () {
  var mobileToggle = document.querySelector('[data-sidebar-toggle]');
  var mobileBody = document.querySelector('[data-sidebar-body]');

  if (mobileToggle && mobileBody) {
    mobileToggle.addEventListener('click', function () {
      mobileBody.classList.toggle('is-open');
    });
  }

  // Desktop sidebar collapse (icon rail), state persisted per browser since
  // this is a server-rendered multi-page app, not an SPA. Two triggers share
  // this behaviour: the brand logo (always visible, doubles as "expand" when
  // collapsed) and the dedicated chevrons button (only shown once expanded -
  // see .sidebar.is-collapsed .sidebar__collapse in the stylesheet).
  var sidebar = document.querySelector('[data-sidebar]');
  var collapseToggles = document.querySelectorAll('[data-sidebar-collapse]');
  if (sidebar && collapseToggles.length > 0) {
    try {
      if (window.localStorage.getItem('sidebarCollapsed') === '1') {
        sidebar.classList.add('is-collapsed');
      }
    } catch (e) { /* localStorage unavailable - default to expanded */ }

    collapseToggles.forEach(function (toggle) {
      toggle.addEventListener('click', function () {
        var collapsed = sidebar.classList.toggle('is-collapsed');
        try {
          window.localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
        } catch (e) { /* ignore */ }
      });
    });
  }

  document.querySelectorAll('[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var message = form.dataset.confirm || 'Yakin melanjutkan?';
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

  // Row action ("burger") menus. Panels are moved to be direct children of
  // <body> ("portal" pattern) so nothing about the table (sticky column,
  // horizontal scroll container) can ever clip or misposition them - only
  // position:fixed + a JS-computed top/right is used, recalculated on every
  // open. Portaling happens lazily on first click (same pattern as
  // async-dropdown.js/enum-dropdown.js's getPanel()) rather than once at
  // page load, so burger menus in rows injected later by an AJAX search
  // swap (table or gallery) still work - an eager, load-time-only portal
  // would never see those and their toggle would silently do nothing.
  function getMenuPanel(toggleBtn) {
    if (!toggleBtn.menuPanel) {
      var panel = toggleBtn.nextElementSibling;
      if (panel?.dataset.menuPanel === undefined) {
        return null;
      }
      document.body.appendChild(panel);
      toggleBtn.menuPanel = panel;
    }
    return toggleBtn.menuPanel;
  }

  function closeAllMenus(except) {
    document.querySelectorAll('[data-menu-toggle]').forEach(function (btn) {
      var panel = btn.menuPanel;
      if (panel && panel !== except) {
        panel.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  document.addEventListener('click', function (event) {
    var toggleBtn = event.target.closest('[data-menu-toggle]');

    if (!toggleBtn) {
      if (!event.target.closest('[data-menu-panel]')) {
        closeAllMenus(null);
      }
      return;
    }

    var panel = getMenuPanel(toggleBtn);
    if (!panel) {
      return;
    }

    var willOpen = panel.hidden;
    closeAllMenus(willOpen ? panel : null);
    panel.hidden = !willOpen;
    toggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

    if (willOpen) {
      var rect = toggleBtn.getBoundingClientRect();
      var panelWidth = panel.offsetWidth || 190;
      var right = window.innerWidth - rect.right;
      if (right + panelWidth > window.innerWidth - 8) {
        right = Math.max(8, window.innerWidth - panelWidth - 8);
      }
      panel.style.top = (rect.bottom + 6) + 'px';
      panel.style.right = right + 'px';
      panel.style.left = 'auto';
    }
  });

  window.addEventListener('scroll', function (event) {
    // Scrolling *inside* a menu panel (it has its own overflow-y via the
    // shared .dropdown-panel styles) must not close it.
    if (event.target?.closest?.('[data-menu-panel]')) {
      return;
    }
    closeAllMenus(null);
  }, true);

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeAllMenus(null);
    }
  });
})();
