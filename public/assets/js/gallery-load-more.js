(function () {
  // How many cards actually fit in one row of the grid's current layout -
  // measured from a real rendered card's width plus the grid's own gap and
  // available width, so it adapts to whatever the container turns out to
  // be (desktop's fixed 200px columns, mobile's 2-per-row) instead of a
  // fixed guess. The grid starts with zero product cards (the server never
  // knows the real viewport width, so it renders none rather than a wrong
  // guess) - when there's nothing to measure yet, a throwaway probe with
  // the same .gallery-card class is inserted just long enough to read its
  // layout size, then removed, so the count is known *before* the first
  // AJAX fetch instead of guessing then correcting afterwards.
  // Matches the CSS @media (max-width: 640px) breakpoint that forces
  // .gallery-card to exactly 2 columns - checked directly rather than left
  // to the width/gap arithmetic below, so a sub-pixel rounding quirk on
  // some phone width can never make it measure 1 or 3 instead of 2.
  var MOBILE_QUERY = '(max-width: 640px)';

  function measureCardsPerRow(grid) {
    if (window.matchMedia?.(MOBILE_QUERY).matches) {
      return 2;
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
    var perRow = cardWidth ? Math.floor((grid.clientWidth + gap) / (cardWidth + gap)) : null;

    if (probe) {
      probe.remove();
    }

    return perRow === null ? null : Math.max(1, perRow);
  }

  function galleryQuery() {
    var input = document.querySelector('[data-gallery-search]');
    return input ? input.value : '';
  }

  function findButton(grid) {
    var next = grid.nextElementSibling;
    return next?.dataset.galleryLoadMore === undefined ? null : next;
  }

  function findLoadingIndicator(grid) {
    var prev = grid.previousElementSibling;
    return prev?.dataset.galleryLoading === undefined ? null : prev;
  }

  // Belt-and-suspenders: once every product in this category is actually
  // showing, the button has nothing left to load - hide it outright
  // instead of trusting only the server's per-request hasMore flag (which
  // reflects that one request's slice, not "does the total on screen now
  // match the category's real count").
  function hideButtonIfComplete(grid) {
    var total = Number.parseInt(grid.dataset.total || '0', 10);
    if (!total) {
      return;
    }

    var visible = grid.querySelectorAll('.gallery-card:not([hidden])').length;
    if (visible < total) {
      return;
    }

    var btn = findButton(grid);
    if (btn) {
      btn.hidden = true;
    }
  }

  // Fetches `limit` more cards from the server for this category and
  // appends them - the only path that ever talks to the network, real
  // lazy loading rather than shipping the whole catalog up front.
  function fetchMore(grid, btn, limit) {
    var categoryId = grid.dataset.categoryId || '';
    var offset = btn.dataset.offset || '0';
    var url = '/products?ajax_view=gallery-more'
      + '&category_id=' + encodeURIComponent(categoryId)
      + '&offset=' + encodeURIComponent(offset)
      + '&limit=' + encodeURIComponent(limit)
      + '&gallery_q=' + encodeURIComponent(galleryQuery());

    return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Gagal memuat produk.');
        }
        return response.json();
      })
      .then(function (data) {
        grid.insertAdjacentHTML('beforeend', data.html);
        btn.dataset.offset = data.nextOffset;
        btn.hidden = !data.hasMore;
        // Refresh from this response's freshly-requeried count (not the
        // page's initial render) - someone else may have added/removed
        // products in this category since the page loaded.
        if (typeof data.total === 'number') {
          grid.dataset.total = data.total;
        }
      });
  }

  // Makes exactly `target` cards visible in the grid: hides any surplus
  // (kept in the DOM, not removed - they were already fetched from the
  // server, so a later row can reveal them again for free instead of
  // re-fetching), reveals any already-hidden surplus from a previous trim,
  // and only reaches the network for a real shortfall. Used both right
  // after render (to match the real column count instead of a fixed
  // server-side guess) and on every "Muat Lebih Banyak" click (to add
  // exactly one more row).
  function ensureVisible(grid, target) {
    var visible = grid.querySelectorAll('.gallery-card:not([hidden])');

    if (visible.length > target) {
      for (var i = target; i < visible.length; i++) {
        visible[i].hidden = true;
      }
      hideButtonIfComplete(grid);
      return Promise.resolve();
    }

    if (visible.length === target) {
      hideButtonIfComplete(grid);
      return Promise.resolve();
    }

    var deficit = target - visible.length;
    var hiddenCards = grid.querySelectorAll('.gallery-card[hidden]');
    for (var j = 0; j < hiddenCards.length && deficit > 0; j++) {
      hiddenCards[j].hidden = false;
      deficit--;
    }

    if (deficit <= 0) {
      hideButtonIfComplete(grid);
      return Promise.resolve();
    }

    var btn = findButton(grid);
    if (!btn) {
      // No button was even rendered - the server already knows this
      // category has nothing more than what's shown.
      hideButtonIfComplete(grid);
      return Promise.resolve();
    }
    // Note: btn.hidden is NOT treated as "nothing left" here - the button
    // starts out hidden on purpose during the initial pre-measurement
    // load (see gallery-results.php's $isInitialLoad), so a hidden button
    // can still have real products behind it waiting to be fetched. Only
    // fetchMore()'s own response (a fresh hasMore from the server) is
    // trusted to actually toggle it back off after this.

    return fetchMore(grid, btn, deficit).then(function () {
      hideButtonIfComplete(grid);
    });
  }

  // Only ever runs right when the page opens - a grid with 0 cards and its
  // "Muat Lebih Banyak" button already hidden is exactly the server's
  // pre-JS-measurement placeholder (see gallery-results.php); an AJAX
  // search always asks the server for an already-measured count, so this
  // never fires mid-search.
  function syncToRow(grid) {
    var perRow = measureCardsPerRow(grid);
    var done = perRow !== null ? ensureVisible(grid, perRow) : Promise.resolve();

    done.catch(function () {
      // Even on a failed top-up, stop showing "Memuat produk..." forever -
      // whatever rendered (nothing, in this case) is what the user sees.
    }).then(function () {
      var loading = findLoadingIndicator(grid);
      if (loading) {
        loading.hidden = true;
      }
    });
  }

  function syncAllGrids() {
    document.querySelectorAll('[data-gallery-grid]').forEach(syncToRow);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncAllGrids);
  } else {
    syncAllGrids();
  }

  // Re-sync after an AJAX gallery search swaps in freshly server-rendered
  // (fixed-count) groups - see gallery-search.js.
  document.addEventListener('gallery:results-updated', syncAllGrids);

  // Delegated (not bound per-button at load) so "Muat Lebih Banyak" still
  // works after an AJAX gallery search swaps in a fresh [data-gallery-results]
  // fragment with its own buttons.
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-gallery-load-more]');
    if (!btn) {
      return;
    }

    var grid = btn.previousElementSibling;
    if (grid?.dataset.galleryGrid === undefined) {
      return;
    }

    var perRow = measureCardsPerRow(grid) || 5;
    var currentlyVisible = grid.querySelectorAll('.gallery-card:not([hidden])').length;
    var originalLabel = btn.textContent;

    btn.disabled = true;
    btn.textContent = 'Memuat...';

    ensureVisible(grid, currentlyVisible + perRow)
      .catch(function () {
        // Leave whatever was already revealed locally in place; only the
        // network top-up (if any was needed) failed.
      })
      .then(function () {
        btn.disabled = false;
        btn.textContent = originalLabel;
      });
  });
})();
