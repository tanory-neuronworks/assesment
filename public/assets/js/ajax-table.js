(function () {
  var DEBOUNCE_MS = 300;
  var searchTimers = new WeakMap();

  // Each page has at most one results container - looked up fresh on every
  // call rather than via .closest(), since the toolbar (search input,
  // entries picker) that triggers these swaps deliberately lives *outside*
  // [data-ajax-table] (see components/data-table.php) so it's never
  // destroyed/recreated by a swap.
  function ajaxTable() {
    return document.querySelector('[data-ajax-table]');
  }

  // Portaled dropdown panels (row-menu, async-dropdown) live in <body>,
  // detached from the table markup they logically belong to. Swapping the
  // container's innerHTML would otherwise orphan them there forever - so any
  // panel belonging to a wrapper inside the container being replaced must be
  // removed first.
  function cleanupPortals(container) {
    container.querySelectorAll('[data-async-dropdown]').forEach(function (wrapper) {
      if (wrapper.asyncPanel?.parentNode) {
        wrapper.asyncPanel.remove();
      }
    });
    container.querySelectorAll('[data-menu-toggle]').forEach(function (toggle) {
      if (toggle.menuPanel?.parentNode) {
        toggle.menuPanel.remove();
      }
    });
  }

  function fetchAndSwap(container, url, options) {
    options = options || {};

    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('Request failed');
        }
        return res.text();
      })
      .then(function (html) {
        cleanupPortals(container);
        container.innerHTML = html;
        if (options.pushState !== false) {
          history.pushState(null, '', url);
        }
      })
      .catch(function () {
        window.location.href = url;
      });
  }

  function searchFormUrl(form) {
    var params = new URLSearchParams(new FormData(form));

    return form.getAttribute('action') + '?' + params.toString();
  }

  document.addEventListener('submit', function (event) {
    var form = event.target.closest('.table-toolbar__search');
    var container = ajaxTable();
    if (!form || !container) {
      return;
    }
    event.preventDefault();
    clearTimeout(searchTimers.get(form));
    fetchAndSwap(container, searchFormUrl(form));
  });

  document.addEventListener('input', function (event) {
    if (event.target.type !== 'search') {
      return;
    }
    var form = event.target.closest('.table-toolbar__search');
    var container = ajaxTable();
    if (!form || !container) {
      return;
    }

    clearTimeout(searchTimers.get(form));
    var timer = setTimeout(function () {
      fetchAndSwap(container, searchFormUrl(form));
    }, DEBOUNCE_MS);
    searchTimers.set(form, timer);
  });

  document.addEventListener('click', function (event) {
    var link = event.target.closest('.pagination__link');
    var container = ajaxTable();
    if (!link || link.classList.contains('is-disabled') || !container) {
      return;
    }
    event.preventDefault();
    fetchAndSwap(container, link.getAttribute('href'));
  });

  window.addEventListener('popstate', function () {
    var container = ajaxTable();
    if (!container) {
      return;
    }
    fetchAndSwap(container, window.location.href, { pushState: false });
  });

  // Hook for enum-dropdown.js's "Show N entries" (mode="navigate") picker:
  // swaps the results in place instead of a full page navigation. Returns
  // false when there's no ajax-table on the page, so the caller falls back
  // to a normal redirect.
  window.AjaxTable = {
    tryNavigate: function (url) {
      var container = ajaxTable();
      if (!container) {
        return false;
      }
      fetchAndSwap(container, url);

      return true;
    },
  };
})();
