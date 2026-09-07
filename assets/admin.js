/* Control panel behaviour: sidebar, delete confirmations, image fields,
   colour inputs and an unsaved-changes guard. */
(function () {
  'use strict';

  /* ------------------------------------------------------------ sidebar */
  var side = document.getElementById('aSide');
  var burger = document.querySelector('.a-burger');

  if (side && burger) {
    burger.addEventListener('click', function () {
      var open = side.classList.toggle('open');
      burger.setAttribute('aria-expanded', String(open));
    });

    document.addEventListener('click', function (event) {
      if (!side.classList.contains('open')) { return; }
      if (side.contains(event.target) || burger.contains(event.target)) { return; }
      side.classList.remove('open');
      burger.setAttribute('aria-expanded', 'false');
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && side.classList.contains('open')) {
        side.classList.remove('open');
        burger.setAttribute('aria-expanded', 'false');
        burger.focus();
      }
    });
  }

  /* ------------------------------------------ confirm before destroying */
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-confirm]');
    if (trigger && !window.confirm(trigger.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });

  /* --------------------------------------------------------- image field */
  Array.prototype.forEach.call(document.querySelectorAll('[data-image-field]'), function (field) {
    var path = field.querySelector('[data-path]');
    var picker = field.querySelector('[data-picker]');
    var upload = field.querySelector('[data-upload]');
    var preview = field.querySelector('[data-preview]');

    function show(src) {
      if (!preview) { return; }
      if (src) {
        preview.src = src;
        preview.hidden = false;
      } else {
        preview.removeAttribute('src');
        preview.hidden = true;
      }
    }

    // Encode each path segment so filenames containing spaces still load.
    function encodePath(value) {
      if (/^(https?:)?\/\//i.test(value)) { return value; }
      return value.split('/').map(encodeURIComponent).join('/');
    }

    if (picker && path) {
      picker.addEventListener('change', function () {
        if (!picker.value) { return; }
        path.value = picker.value;
        show(encodePath(picker.value));
      });
    }

    if (path) {
      path.addEventListener('change', function () {
        show(path.value ? encodePath(path.value) : '');
      });
    }

    // Preview the file the user just picked, before it is uploaded.
    if (upload) {
      upload.addEventListener('change', function () {
        var file = upload.files && upload.files[0];
        if (!file || !/^image\//.test(file.type)) { return; }
        var reader = new FileReader();
        reader.onload = function () { show(String(reader.result)); };
        reader.readAsDataURL(file);
      });
    }
  });

  /* ------------------------------------------------------ colour fields */
  Array.prototype.forEach.call(document.querySelectorAll('.a-colour'), function (wrap) {
    var swatch = wrap.querySelector('input[type="color"]');
    var text = wrap.querySelector('.a-colour-text');
    if (!swatch || !text) { return; }

    swatch.addEventListener('input', function () { text.value = swatch.value; });
    text.addEventListener('change', function () {
      var value = text.value.trim();
      if (/^#[0-9a-f]{6}$/i.test(value)) { swatch.value = value; }
      else { text.value = swatch.value; }
    });
  });

  /* ------------------------------------------- unsaved-changes reminder */
  var form = document.querySelector('.a-content form:not([action*="action=delete"])');
  if (form && form.querySelector('.a-form-actions')) {
    var dirty = false;
    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });

    window.addEventListener('beforeunload', function (event) {
      if (!dirty) { return; }
      event.preventDefault();
      event.returnValue = '';
    });

    // Leaving through a link the user clicked on purpose should still warn once.
    document.addEventListener('click', function (event) {
      var link = event.target.closest('a[href]');
      if (!link || !dirty || link.hasAttribute('data-confirm')) { return; }
      if (link.target === '_blank') { return; }
      if (!window.confirm('You have unsaved changes. Leave without saving?')) {
        event.preventDefault();
      } else {
        dirty = false;
      }
    });
  }
}());
