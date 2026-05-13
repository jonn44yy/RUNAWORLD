// admin-runa-preview.js — RunaWorld Admin
// Carga una única runa animada bajo demanda en un modal.
// Al cerrar, vacía el iframe para detener animaciones/canvas/timers.
(function () {
    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    ready(function () {
        var modal = document.getElementById('runa-preview-modal');
        var body = document.getElementById('runa-preview-body');
        var title = document.getElementById('runa-preview-title');
        var closeBtn = document.getElementById('runa-preview-close');

        if (!modal || !body || !title || !closeBtn) return;

        function openPreview(src, name) {
            body.innerHTML = '';
            title.textContent = name || 'Vista previa';

            var iframe = document.createElement('iframe');
            iframe.src = src;
            iframe.loading = 'eager';
            iframe.setAttribute('title', name || 'Vista previa de runa');
            iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');

            body.appendChild(iframe);
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closePreview() {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            body.innerHTML = '';
            document.body.style.overflow = '';
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-runa-preview');
            if (!btn) return;
            e.preventDefault();
            openPreview(btn.getAttribute('data-preview-src'), btn.getAttribute('data-preview-title'));
        });

        closeBtn.addEventListener('click', closePreview);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closePreview();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) closePreview();
        });
    });
})();
