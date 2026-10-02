(function () {
  var DEBOUNCE_MS = 300;
  var timers = new WeakMap();

  function fetchAndSwap(group) {
    var action = group.dataset.boardSearchAction;
    var results = document.querySelector('[data-board-results]');
    if (!action || !results) {
      return;
    }

    var searchInput = group.querySelector('[data-board-search]');
    var fromInput = group.querySelector('[data-board-from]');
    var toInput = group.querySelector('[data-board-to]');

    var params = new URLSearchParams();
    params.set('ajax_view', 'board');
    if (searchInput) {
      params.set('board_q', searchInput.value.trim());
    }
    if (fromInput) {
      params.set('board_from', fromInput.value);
    }
    if (toInput) {
      params.set('board_to', toInput.value);
    }

    var url = action + '?' + params.toString();

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('Board filter request failed');
        }
        return res.text();
      })
      .then(function (html) {
        results.innerHTML = html;
      })
      .catch(function () {
        // Fall back to a full navigation so the user isn't stuck looking
        // at a stale board if the AJAX request fails.
        window.location.href = url;
      });
  }

  document.addEventListener('input', function (event) {
    var input = event.target.closest('[data-board-search]');
    if (!input) {
      return;
    }
    var group = input.closest('[data-board-filters]');
    if (!group) {
      return;
    }

    clearTimeout(timers.get(group));
    var timer = setTimeout(function () {
      fetchAndSwap(group);
    }, DEBOUNCE_MS);
    timers.set(group, timer);
  });

  // Date inputs filter immediately on change (picking a date is already a
  // deliberate, discrete action) rather than debouncing like free-text search.
  document.addEventListener('change', function (event) {
    var input = event.target.closest('[data-board-from], [data-board-to]');
    if (!input) {
      return;
    }
    var group = input.closest('[data-board-filters]');
    if (!group) {
      return;
    }

    clearTimeout(timers.get(group));
    fetchAndSwap(group);
  });
})();
