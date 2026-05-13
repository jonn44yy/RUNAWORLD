// admin-cards.js — RunaWorld Admin
// Convierte tablas admin en cards desplegables.
// Se usa en usuarios.php, mensajes.php, etc.
// NO hace falta cargarlo en runas.php.

(function () {
    function transformarTablas() {
        document.querySelectorAll('.admin-tabla').forEach(function (tabla) {
            const headers = Array.from(tabla.querySelectorAll('thead th'))
                .map(th => th.textContent.trim());

            tabla.querySelectorAll('tbody tr').forEach(function (tr) {
                if (tr.classList.contains('admin-card')) return;

                tr.classList.add('admin-card');

                const tds = tr.querySelectorAll('td');

                tds.forEach(function (td, i) {
                    if (headers[i]) {
                        td.setAttribute('data-label', headers[i]);
                    }
                });

                tr.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, input, select, textarea, label')) return;
                    tr.classList.toggle('expandida');
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', transformarTablas);
    } else {
        transformarTablas();
    }
})();