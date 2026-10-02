(function () {
  var DEBOUNCE_MS = 300;
  var timers = new WeakMap();
  var activeIndex = new WeakMap();

  // Panels are portaled to <body> (position:fixed, computed on open) so no
  // ancestor (table scroll container, card, sticky column, ...) can ever
  // clip or miscompute them. Portaling detaches the panel from its wrapper,
  // so we cache the link both ways the first time a wrapper is used -
  // wrapper.querySelector('[data-async-panel]') would return null on every
  // call after the first once the node has moved.
  function getPanel(wrapper) {
    if (!wrapper.asyncPanel) {
      var panel = wrapper.querySelector('[data-async-panel]');
      document.body.appendChild(panel);
      panel.asyncWrapper = wrapper;
      wrapper.asyncPanel = panel;
    }
    return wrapper.asyncPanel;
  }

  function closeAllPanels(except) {
    document.querySelectorAll('[data-async-dropdown]').forEach(function (wrapper) {
      if (wrapper.asyncPanel && wrapper.asyncPanel !== except) {
        wrapper.asyncPanel.hidden = true;
      }
    });
  }

  function positionPanel(wrapper, panel) {
    var input = wrapper.querySelector('[data-async-input]');
    var rect = input.getBoundingClientRect();
    panel.style.top = (rect.bottom + 6) + 'px';
    panel.style.left = rect.left + 'px';
    panel.style.width = rect.width + 'px';
  }

  // Fills (or clears, when the product selection is cleared/changed) the
  // sibling sell/cost price input in the same line-item row. Always
  // overwrites - a stale price left over from a *different* product is
  // worse than losing a manual edit, and re-selecting a product is exactly
  // when the user wants its catalog price back anyway.
  function applyPriceHints(wrapper, meta) {
    var row = wrapper.closest('li,tr') || document;
    var sellInput = row.querySelector('[data-sell-price-input]');
    if (sellInput) {
      sellInput.value = meta?.sellPrice !== undefined ? meta.sellPrice : '';
    }
    var costInput = row.querySelector('[data-cost-price-input]');
    if (costInput) {
      costInput.value = meta?.costPrice !== undefined ? meta.costPrice : '';
    }
  }

  function selectResult(wrapper, result) {
    wrapper.querySelector('[data-async-value]').value = result.id;
    var input = wrapper.querySelector('[data-async-input]');
    input.value = result.label;
    applyPriceHints(wrapper, result.meta);
    // Stashed on the wrapper (not read here) so a caller that needs the
    // picked product's thumbnail - e.g. the SO/PO "add to cart" item picker
    // building its own card - can read it back after selection instead of
    // re-fetching.
    wrapper.dataset.selectedImage = result.image || '';
    getPanel(wrapper).hidden = true;
  }

  function renderResults(wrapper, results) {
    var panel = getPanel(wrapper);
    panel.innerHTML = '';
    activeIndex.set(wrapper, -1);

    if (results.length === 0) {
      var empty = document.createElement('li');
      empty.className = 'dropdown-empty';
      empty.textContent = 'Tidak ditemukan.';
      panel.appendChild(empty);
      panel.hidden = false;
      return;
    }

    results.forEach(function (result) {
      var li = document.createElement('li');
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'dropdown-item';

      if (result.image) {
        var thumb = document.createElement('img');
        thumb.src = result.image;
        thumb.alt = '';
        thumb.className = 'dropdown-item__thumb';
        item.appendChild(thumb);
      }

      var label = document.createElement('span');
      label.textContent = result.label;
      item.appendChild(label);

      item.addEventListener('click', function () {
        selectResult(wrapper, result);
      });
      li.appendChild(item);
      panel.appendChild(li);
    });

    panel.hidden = false;
  }

  // A product shouldn't show up as a search result if it's already spoken
  // for elsewhere on the same form - either another "products" dropdown
  // (the old multi-row PO/SO item list) or an already-added SO/PO cart card
  // (any element anywhere carrying data-cart-product-id, e.g. the hidden
  // product_id input inside each cart card - see order-items.js). The
  // dropdown's own current selection is excluded from the first check so
  // reopening it to change the selection still shows its own product.
  function selectedProductIds(excludeWrapper) {
    var ids = [];
    document.querySelectorAll('[data-async-dropdown="products"]').forEach(function (w) {
      if (w === excludeWrapper) {
        return;
      }
      var valueInput = w.querySelector('[data-async-value]');
      if (valueInput?.value) {
        ids.push(valueInput.value);
      }
    });
    document.querySelectorAll('[data-cart-product-id]').forEach(function (el) {
      ids.push(el.dataset.cartProductId);
    });
    return ids;
  }

  function search(wrapper, query) {
    var type = wrapper.dataset.asyncDropdown;
    var panel = getPanel(wrapper);
    panel.innerHTML = '<li class="dropdown-loading">Mencari...</li>';
    positionPanel(wrapper, panel);
    panel.hidden = false;

    fetch('/api/lookup/' + encodeURIComponent(type) + '?q=' + encodeURIComponent(query))
      .then(function (res) {
        return res.ok ? res.json() : { results: [] };
      })
      .then(function (data) {
        var results = data.results || [];
        if (type === 'products') {
          var taken = selectedProductIds(wrapper);
          results = results.filter(function (result) {
            return !taken.includes(String(result.id));
          });
        }
        renderResults(wrapper, results);
        positionPanel(wrapper, panel);
      })
      .catch(function () {
        panel.innerHTML = '<li class="dropdown-empty">Gagal memuat data.</li>';
      });
  }

  document.addEventListener('input', function (event) {
    var input = event.target.closest('[data-async-input]');
    if (!input) {
      return;
    }
    var wrapper = input.closest('[data-async-dropdown]');
    wrapper.querySelector('[data-async-value]').value = '';
    applyPriceHints(wrapper, null);

    clearTimeout(timers.get(wrapper));
    var query = input.value.trim();
    var timer = setTimeout(function () {
      search(wrapper, query);
    }, DEBOUNCE_MS);
    timers.set(wrapper, timer);
  });

  document.addEventListener(
    'focus',
    function (event) {
      var input = event.target.closest('[data-async-input]');
      if (!input) {
        return;
      }
      var wrapper = input.closest('[data-async-dropdown]');
      closeAllPanels(getPanel(wrapper));
      search(wrapper, input.value.trim());
    },
    true
  );

  document.addEventListener('keydown', function (event) {
    var input = event.target.closest('[data-async-input]');
    if (!input) {
      return;
    }
    var wrapper = input.closest('[data-async-dropdown]');
    var panel = getPanel(wrapper);
    if (panel.hidden) {
      return;
    }
    var items = panel.querySelectorAll('.dropdown-item');
    if (items.length === 0) {
      return;
    }
    var idx = activeIndex.get(wrapper);
    idx = idx === undefined ? -1 : idx;

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      idx = Math.min(idx + 1, items.length - 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      idx = Math.max(idx - 1, 0);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      if (idx >= 0) {
        items[idx].click();
      }
      return;
    } else if (event.key === 'Escape') {
      panel.hidden = true;
      return;
    } else {
      return;
    }

    items.forEach(function (item, i) {
      item.classList.toggle('is-active', i === idx);
    });
    activeIndex.set(wrapper, idx);
  });

  document.addEventListener('click', function (event) {
    if (event.target.closest('[data-async-dropdown]')) {
      return;
    }
    var panel = event.target.closest('[data-async-panel]');
    if (!panel) {
      closeAllPanels(null);
    }
  });

  window.addEventListener(
    'scroll',
    function (event) {
      // Scrolling *inside* a result panel (it has its own overflow-y) must
      // not close it - only close on page/ancestor scroll.
      if (event.target?.closest?.('[data-async-panel]')) {
        return;
      }
      closeAllPanels(null);
    },
    true
  );
})();
