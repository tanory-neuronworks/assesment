(function () {
  var modal = document.querySelector('[data-image-modal]');
  if (!modal) {
    return;
  }

  var image = modal.querySelector('[data-image-modal-img]');
  var placeholder = modal.querySelector('[data-image-modal-placeholder]');
  var errorEl = modal.querySelector('[data-image-modal-error]');
  var form = modal.querySelector('[data-image-modal-form]');
  var fileInput = modal.querySelector('[data-image-modal-file]');
  var changeBtn = modal.querySelector('[data-image-modal-change]');
  var lastTrigger = null;

  function showError(message) {
    errorEl.textContent = message;
    errorEl.hidden = false;
  }

  function clearError() {
    errorEl.textContent = '';
    errorEl.hidden = true;
  }

  function open(trigger) {
    var src = trigger.dataset.imageSrc || '';
    var uploadUrl = trigger.dataset.uploadUrl;

    // Nothing to preview and nothing the viewer can do about it - the
    // trigger shouldn't even be a button in that case, but bail just in case.
    if (!src && !uploadUrl) {
      return;
    }

    lastTrigger = trigger;
    clearError();
    fileInput.value = '';

    if (src) {
      image.src = src;
      image.alt = trigger.dataset.imageAlt || '';
      image.hidden = false;
      placeholder.hidden = true;
    } else {
      image.src = '';
      image.hidden = true;
      placeholder.hidden = false;
    }

    if (uploadUrl) {
      form.setAttribute('action', uploadUrl);
      changeBtn.hidden = false;
    } else {
      changeBtn.hidden = true;
    }

    modal.hidden = false;
  }

  function close() {
    modal.hidden = true;
    image.src = '';
    clearError();
    if (lastTrigger) {
      lastTrigger.focus();
      lastTrigger = null;
    }
  }

  function upload() {
    var url = form.getAttribute('action');
    if (!url || !fileInput.files[0]) {
      return;
    }

    clearError();
    changeBtn.disabled = true;

    fetch(url, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { ok: res.ok, data: data };
        });
      })
      .then(function (result) {
        changeBtn.disabled = false;
        if (!result.ok) {
          showError(result.data.error || 'Gagal mengunggah gambar.');
          return;
        }

        image.src = result.data.image;
        image.hidden = false;
        placeholder.hidden = true;

        if (lastTrigger) {
          lastTrigger.dataset.imageSrc = result.data.image;

          // The trigger's own thumbnail may currently be showing the empty
          // placeholder icon (no <img> to just update the src of), so swap
          // its whole contents for a fresh <img> either way.
          var imgClass = lastTrigger.dataset.imgClass || '';
          var alt = lastTrigger.dataset.imageAlt || '';
          lastTrigger.innerHTML = '';
          var newImg = document.createElement('img');
          newImg.src = result.data.image;
          newImg.alt = alt;
          newImg.className = imgClass;
          lastTrigger.appendChild(newImg);
        }
      })
      .catch(function () {
        changeBtn.disabled = false;
        showError('Gagal mengunggah gambar. Periksa koneksi Anda.');
      });
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-image-preview]');
    if (trigger) {
      open(trigger);
      return;
    }

    if (modal.hidden) {
      return;
    }

    if (event.target.closest('[data-image-modal-close]')) {
      close();
      return;
    }

    if (event.target.closest('[data-image-modal-change]')) {
      fileInput.click();
    }
  });

  fileInput.addEventListener('change', function () {
    if (fileInput.files[0]) {
      upload();
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !modal.hidden) {
      close();
    }
  });
})();
