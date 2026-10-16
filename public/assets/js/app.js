/* MedCore front-end behaviour — dependency-free vanilla JS. */
(function () {
  'use strict';

  /* ------------------------- mobile sidebar ------------------------- */
  var toggle = document.getElementById('sidebarToggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      document.body.classList.toggle('nav-open');
    });
    document.addEventListener('click', function (e) {
      if (document.body.classList.contains('nav-open') &&
          !e.target.closest('.sidebar') && !e.target.closest('#sidebarToggle')) {
        document.body.classList.remove('nav-open');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') document.body.classList.remove('nav-open');
    });
  }

  /* ------------------------ flash auto-dismiss ---------------------- */
  document.querySelectorAll('[data-dismiss]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var alert = btn.closest('.alert');
      if (alert) alert.remove();
    });
  });
  window.setTimeout(function () {
    document.querySelectorAll('.flash-stack .alert').forEach(function (el) {
      el.style.transition = 'opacity .4s';
      el.style.opacity = '0';
      window.setTimeout(function () { el.remove(); }, 450);
    });
  }, 8000);

  /* --------------------- confirm-before-submit forms ---------------- */
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  /* ------------------------ fetch helper (JSON API) ----------------- */
  var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  function apiFetch(url, options) {
    options = options || {};
    options.headers = Object.assign({
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrfToken,
      'Accept': 'application/json'
    }, options.headers || {});
    if (options.body && typeof options.body === 'object') {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    return fetch(url, options).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok) {
          throw new Error(json.error || ('Request failed with status ' + res.status));
        }
        return json;
      });
    });
  }

  /* ------------------- dashboard chart (GET /api/v1/summary) -------- */
  var chart = document.getElementById('apptChart');
  if (chart) {
    apiFetch('/api/v1/summary').then(function (payload) {
      var series = payload.data.appointments || [];
      var labels = document.createElement('div');
      labels.className = 'bar-chart-labels';
      var max = Math.max.apply(null, series.map(function (p) { return p.count; }).concat([1]));
      chart.innerHTML = '';
      series.forEach(function (point) {
        var bar = document.createElement('div');
        bar.className = 'bar';
        bar.style.height = Math.max(3, Math.round((point.count / max) * 100)) + '%';
        bar.setAttribute('data-tip', point.day + ' — ' + point.count + ' appointment' + (point.count === 1 ? '' : 's'));
        chart.appendChild(bar);
        var lab = document.createElement('span');
        lab.textContent = point.label;
        labels.appendChild(lab);
      });
      chart.parentNode.appendChild(labels);
    }).catch(function () {
      chart.parentNode.innerHTML = '<p class="muted">Chart data unavailable.</p>';
    });
  }

  /* ---------------- role permission matrix (POST JSON API) ---------- */
  var matrix = document.getElementById('permMatrix');
  if (matrix && matrix.dataset.editable === '1') {
    var status = document.getElementById('matrixStatus');
    matrix.addEventListener('submit', function (e) {
      e.preventDefault();
      var ids = Array.prototype.slice
        .call(matrix.querySelectorAll('input[type="checkbox"]:checked'))
        .map(function (cb) { return parseInt(cb.value, 10); });
      status.textContent = 'Saving…';
      apiFetch('/admin/roles/' + matrix.dataset.roleId + '/permissions', {
        method: 'POST',
        body: { permission_ids: ids }
      }).then(function (payload) {
        status.textContent = 'Saved — ' + payload.permission_count + ' permissions granted. Audit event recorded.';
      }).catch(function (err) {
        status.textContent = 'Save failed: ' + err.message;
      });
    });
  }

  /* -------------- client-side password-length hint (progressive) ---- */
  document.querySelectorAll('input[type="password"][data-min]').forEach(function (input) {
    input.addEventListener('input', function () {
      var min = parseInt(input.getAttribute('data-min'), 10);
      input.setCustomValidity(
        input.value.length > 0 && input.value.length < min
          ? 'Use at least ' + min + ' characters.'
          : ''
      );
    });
  });
})();
