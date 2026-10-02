(function () {
  var DEBOUNCE_MS = 300;
  var timers = new WeakMap();

  // Same measurement as gallery-load-more.js (duplicated rather than
  // shared, to keep these two files load-order independent) - counts how
  // many cards fit in a row of whichever gallery grid is currently on
  // screen (falling back to a throwaway probe if every grid is still
  // empty), so a search's results come back already sized to a full row
  // instead of a fixed guess that needs correcting afterwards.
  // Matches the CSS @media (max-width: 640px) breakpoint that forces
  // .gallery-card to exactly 2 columns - checked directly so a sub-pixel
  // rounding quirk on some phone width can never measure 1 or 3 instead.
  var MOBILE_QUERY = '(max-width: 640px)';

  function measureCardsPerRow() {
    if (window.matchMedia?.(MOBILE_QUERY).matches) {
      return 2;
    }

    var grid = document.querySelector('[data-gallery-grid]');
    if (!grid) {
      return 5;
    }

    var card = grid.querySelector('.gallery-card');
    var probe = null;

    if (!card) {
      probe = document.createElement('div');
      probe.className = 'gallery-card';
      probe.style.visibility = 'hidden';
      probe.setAttribute('aria-hidden', 'true');
      grid.appendChild(probe);
      card = probe;
    }

    var cardWidth = card.offsetWidth;
    var styles = window.getComputedStyle(grid);
    var gap = Number.parseFloat(styles.columnGap || styles.gap) || 0;
    var perRow = cardWidth ? Math.floor((grid.clientWidth + gap) / (cardWidth + gap)) : 5;

    if (probe) {
      probe.remove();
    }

    return Math.max(1, perRow);
  }

  function fetchAndSwap(input) {
    var action = input.dataset.gallerySearchAction;
    var results = document.querySelector('[data-gallery-results]');
    if (!action || !results) {
      return;
    }

    var params = new URLSearchParams();
    params.set('ajax_view', 'gallery');
    params.set('gallery_q', input.value.trim());
    params.set('limit', String(measureCardsPerRow()));

    var url = action + '?' + params.toString();

    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('Gallery search failed');
        }
        return res.text();
      })
      .then(function (html) {
        results.innerHTML = html;
        // Tells gallery-load-more.js to re-measure and re-sync each fresh
        // grid to a full row - the swapped-in groups start server-rendered
        // at a fixed count again, same as a first page load.
        document.dispatchEvent(new CustomEvent('gallery:results-updated'));
      })
      .catch(function () {
        // Fall back to a full navigation so the user isn't stuck looking
        // at a stale gallery if the AJAX request fails.
        window.location.href = url;
      });
  }

  document.addEventListener('input', function (event) {
    var input = event.target.closest('[data-gallery-search]');
    if (!input) {
      return;
    }

    clearTimeout(timers.get(input));
    var timer = setTimeout(function () {
      fetchAndSwap(input);
    }, DEBOUNCE_MS);
    timers.set(input, timer);
  });
})();
