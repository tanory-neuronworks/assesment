(function () {
  var activeIndex = new WeakMap();

  // Same lazy-portal pattern as async-dropdown.js: move the panel to <body>
  // on first use and remember the link both ways, so no ancestor overflow
  // can clip it and repeated opens don't re-query a now-detached subtree.
  function getPanel(wrapper) {
    if (!wrapper.enumPanel) {
      var panel = wrapper.querySelector('[data-enum-panel]');
      document.body.appendChild(panel);
      panel.enumWrapper = wrapper;
      wrapper.enumPanel = panel;
    }
    return wrapper.enumPanel;
  }

  function closeAllPanels(except) {
    document.querySelectorAll('[data-enum-dropdown]').forEach(function (wrapper) {
      var panel = wrapper.enumPanel;
      if (panel && panel !== except) {
        panel.hidden = true;
        var trigger = wrapper.querySelector('[data-enum-trigger]');
        if (trigger) {
          trigger.setAttribute('aria-expanded', 'false');
        }
      }
    });
  }

  function positionPanel(wrapper, panel) {
    var trigger = wrapper.querySelector('[data-enum-trigger]');
    var rect = trigger.getBoundingClientRect();
    var panelWidth = Math.max(panel.offsetWidth || 0, rect.width);
    var left = rect.left;
    if (left + panelWidth > window.innerWidth - 8) {
      left = Math.max(8, window.innerWidth - panelWidth - 8);
    }
    panel.style.top = (rect.bottom + 6) + 'px';
    panel.style.left = left + 'px';
    panel.style.minWidth = rect.width + 'px';
  }

  function openPanel(wrapper) {
    var panel = getPanel(wrapper);
    var trigger = wrapper.querySelector('[data-enum-trigger]');
    closeAllPanels(panel);
    positionPanel(wrapper, panel);
    panel.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    activeIndex.set(wrapper, -1);
  }

  // Marks `item` selected and updates the trigger's own label - shared by
  // both modes since 'navigate' (e.g. the "Show N entries" picker) lives
  // outside the [data-ajax-table] region that actually gets swapped, so
  // nothing else refreshes its label after an in-place AJAX navigation.
  function markSelected(wrapper, item) {
    var label = wrapper.querySelector('[data-enum-label]');
    label.textContent = item.textContent.trim();
    wrapper.querySelectorAll('[data-enum-item]').forEach(function (i) {
      i.classList.remove('is-selected');
    });
    item.classList.add('is-selected');
    getPanel(wrapper).hidden = true;
    wrapper.querySelector('[data-enum-trigger]').setAttribute('aria-expanded', 'false');
  }

  function selectItem(wrapper, item) {
    var mode = wrapper.dataset.enumMode;

    if (mode === 'navigate') {
      var href = item.dataset.href;
      if (href) {
        var handled = window.AjaxTable?.tryNavigate(href);
        if (handled) {
          markSelected(wrapper, item);
        } else {
          window.location.href = href;
        }
      }
      return;
    }

    var valueInput = wrapper.querySelector('[data-enum-value]');
    valueInput.value = item.dataset.value ?? '';
    markSelected(wrapper, item);
    valueInput.dispatchEvent(new Event('change', { bubbles: true }));
  }

  document.addEventListener('click', function (event) {
    var item = event.target.closest('[data-enum-item]');
    if (item) {
      var panel = item.closest('[data-enum-panel]');
      selectItem(panel.enumWrapper, item);
      return;
    }

    var trigger = event.target.closest('[data-enum-trigger]');
    if (trigger) {
      var wrapper = trigger.closest('[data-enum-dropdown]');
      var willOpen = getPanel(wrapper).hidden;
      if (willOpen) {
        openPanel(wrapper);
      } else {
        closeAllPanels(null);
      }
      return;
    }

    if (!event.target.closest('[data-enum-panel]')) {
      closeAllPanels(null);
    }
  });

  document.addEventListener('keydown', function (event) {
    var trigger = event.target.closest('[data-enum-trigger]');
    if (!trigger) {
      return;
    }
    var wrapper = trigger.closest('[data-enum-dropdown]');
    var panel = wrapper.enumPanel;

    if ((event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') && (!panel || panel.hidden)) {
      event.preventDefault();
      openPanel(wrapper);
      return;
    }

    if (!panel || panel.hidden) {
      return;
    }

    var items = panel.querySelectorAll('[data-enum-item]');
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
      trigger.setAttribute('aria-expanded', 'false');
      return;
    } else {
      return;
    }

    items.forEach(function (item, i) {
      item.classList.toggle('is-active', i === idx);
    });
    activeIndex.set(wrapper, idx);
  });

  window.addEventListener(
    'scroll',
    function (event) {
      // Scrolling *inside* a long option list (it has its own overflow-y)
      // must not close it - only close on page/ancestor scroll.
      if (event.target?.closest?.('[data-enum-panel]')) {
        return;
      }
      closeAllPanels(null);
    },
    true
  );
})();
