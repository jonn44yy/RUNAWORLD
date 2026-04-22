// admin-mobile.js — RunaWorld Admin
// Transforma las tablas admin en cards desplegables en móvil.
// Sin JS, las tablas siguen funcionando normalmente (fallback).
(function () {
    if (window.innerWidth > 700) return;

    function transformar() {
        document.querySelectorAll('.admin-tabla').forEach(tabla => {
            // Leer los headers para usarlos como etiquetas
            const headers = Array.from(tabla.querySelectorAll('thead th'))
                .map(th => th.textContent.trim());

            tabla.querySelectorAll('tbody tr').forEach(tr => {
                tr.classList.add('mobile-card');

                // Añadir data-label a cada celda
                const tds = tr.querySelectorAll('td');
                tds.forEach((td, i) => {
                    if (headers[i]) td.setAttribute('data-label', headers[i]);
                });

                // Click → expandir/colapsar (ignora clicks en botones y enlaces)
                tr.addEventListener('click', (e) => {
                    if (e.target.closest('a, button, input, select, textarea')) return;
                    tr.classList.toggle('expandida');
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', transformar);
    } else {
        transformar();
    }
})();
