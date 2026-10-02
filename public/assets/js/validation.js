(function () {
  document.querySelectorAll('form[data-validate]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      let invalid = null;

      form.querySelectorAll('[required]').forEach(function (field) {
        if (invalid) {
          return;
        }
        if (!String(field.value || '').trim()) {
          invalid = field;
        }
      });

      form.querySelectorAll('input[type="number"]').forEach(function (field) {
        if (invalid) {
          return;
        }
        if (field.value !== '' && Number(field.value) < 0) {
          invalid = field;
        }
      });

      if (invalid) {
        event.preventDefault();
        invalid.focus();
        invalid.reportValidity();
      }
    });
  });
})();
