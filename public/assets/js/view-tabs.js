(function () {
  // No "remember last choice" persistence here on purpose - SO/PO always
  // open on Board and Products always opens on Galeri, every visit,
  // regardless of whatever tab was active last time.
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-view-tab]');
    if (!btn) {
      return;
    }

    var group = btn.closest('[data-view-tabs]');
    if (!group) {
      return;
    }

    var target = btn.dataset.viewTab;

    group.querySelectorAll('[data-view-tab]').forEach(function (b) {
      var active = b === btn;
      b.classList.toggle('is-active', active);
      b.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    document.querySelectorAll('[data-view-panel]').forEach(function (panel) {
      panel.hidden = panel.dataset.viewPanel !== target;
    });
  });
})();
