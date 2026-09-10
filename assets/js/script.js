document.addEventListener('DOMContentLoaded', function () {
  // --- Sidebar toggle (mobile) ---
  var toggleBtns = document.querySelectorAll('.sidebar-toggle-btn');
  var sidebar = document.querySelector('.app-sidebar');
  var backdrop = document.querySelector('.sidebar-backdrop');

  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('show');
    if (backdrop) backdrop.classList.remove('show');
  }

  toggleBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (sidebar) sidebar.classList.toggle('show');
      if (backdrop) backdrop.classList.toggle('show');
    });
  });
  if (backdrop) backdrop.addEventListener('click', closeSidebar);

  // --- Confirmation avant action destructrice ---
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
  });

  // --- Curseur de progression : affichage de la valeur en direct ---
  document.querySelectorAll('input[type="range"][data-progress-output]').forEach(function (input) {
    var output = document.getElementById(input.getAttribute('data-progress-output'));
    var update = function () { if (output) output.textContent = input.value + ' %'; };
    input.addEventListener('input', update);
    update();
  });

  // --- Auto-dismiss des alertes flash ---
  document.querySelectorAll('.alert[data-autohide]').forEach(function (alertEl) {
    setTimeout(function () {
      var bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
      bsAlert.close();
    }, 6000);
  });

  // --- Marquer une notification comme lue au clic (AJAX léger) ---
  document.querySelectorAll('.notif-item[data-notif-id]').forEach(function (item) {
    item.addEventListener('click', function () {
      var id = item.getAttribute('data-notif-id');
      var url = item.getAttribute('href');
      var params = new URLSearchParams();
      params.append('id', id);
      navigator.sendBeacon
        ? navigator.sendBeacon((window.BASE_URL || '') + '/notifications.php?ajax=mark_read', params)
        : fetch((window.BASE_URL || '') + '/notifications.php?ajax=mark_read', { method: 'POST', body: params });
    });
  });

  // --- Filtrage instantané des tableaux via un champ de recherche ---
  document.querySelectorAll('[data-table-search]').forEach(function (input) {
    var table = document.querySelector(input.getAttribute('data-table-search'));
    if (!table) return;
    input.addEventListener('keyup', function () {
      var term = input.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().indexOf(term) > -1 ? '' : 'none';
      });
    });
  });
});
