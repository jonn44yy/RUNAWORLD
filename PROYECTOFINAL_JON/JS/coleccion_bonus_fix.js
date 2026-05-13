// coleccion_bonus_fix.js — RuneWorld
// Muestra un único bonus de colección: normal O corrupta, nunca los dos a la vez.
// También oculta el título suelto "Colección" dentro de la sección.

(function () {
    'use strict';

    window.RW_COLECCION_BONUS_FIX_VERSION = '1.0-one-visible-bonus-no-title';

    const ROOT_SELECTOR = '#seccion-coleccion';
    const BONUS_SELECTORS = [
        '.coleccion-bonus-suerte-v74',
        '[data-bonus-variante]',
        '[data-bonus-variant]',
        '.coleccion-bonus-card',
        '.coleccion-bonus-item',
        '.coleccion-bonus-mobile > *',
        '.coleccion-bonus-desktop > *'
    ].join(',');

    function normalizar(valor) {
        return String(valor || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function textoDe(el) {
        return normalizar(el ? el.textContent : '');
    }

    function datosDe(el) {
        if (!el) return '';
        const ds = el.dataset || {};
        return normalizar([
            el.className,
            ds.variante,
            ds.variant,
            ds.tipo,
            ds.bonusVariante,
            ds.bonusVariant
        ].join(' '));
    }

    function varianteActiva(root) {
        const active = root.querySelector(
            '.coleccion-variante-pill.active, ' +
            '.coleccion-variante-pill[aria-current="true"], ' +
            '[data-variante].active, ' +
            '[data-variant].active'
        );

        const texto = textoDe(active);
        const datos = datosDe(active);
        const locked = !active ||
            active.disabled ||
            active.getAttribute('aria-disabled') === 'true' ||
            texto.includes('bloquead') ||
            datos.includes('bloquead') ||
            datos.includes('locked') ||
            active.querySelector('[data-locked], .locked, .bloqueado, .bloqueada');

        const corrupta = Boolean(active) && (
            texto.includes('corrupt') ||
            datos.includes('corrupt')
        );

        return {
            esCorruptaVisible: corrupta && !locked
        };
    }

    function clasificarBonus(el) {
        const contenido = `${datosDe(el)} ${textoDe(el)}`;
        if (contenido.includes('corrupt')) return 'corrupta';
        if (contenido.includes('normal')) return 'normal';
        return 'desconocido';
    }

    function setOculto(el, oculto) {
        if (!el) return;
        if (oculto) {
            el.setAttribute('data-rw-bonus-hidden', '1');
            el.classList.add('rw-bonus-hidden');
        } else {
            el.removeAttribute('data-rw-bonus-hidden');
            el.classList.remove('rw-bonus-hidden');
        }
    }

    function ocultarTituloColeccion(root) {
        const posiblesTitulos = root.querySelectorAll(
            ':scope > h1, :scope > h2, :scope > h3, ' +
            ':scope > .seccion-titulo, :scope > .titulo-seccion, ' +
            ':scope > .coleccion-titulo, :scope > .panel-titulo, ' +
            '.coleccion-title, .coleccion-titulo'
        );

        posiblesTitulos.forEach((el) => {
            const txt = textoDe(el);
            if (txt === 'coleccion' || txt === 'collection') {
                el.hidden = true;
                el.setAttribute('data-rw-title-hidden', '1');
            }
        });
    }

    function aplicarFixColeccion() {
        const root = document.querySelector(ROOT_SELECTOR);
        if (!root) return;

        ocultarTituloColeccion(root);

        const { esCorruptaVisible } = varianteActiva(root);
        const bonus = Array.from(root.querySelectorAll(BONUS_SELECTORS))
            .filter((el) => el && el !== root && textoDe(el).length > 0);

        bonus.forEach((el) => {
            const tipo = clasificarBonus(el);

            if (tipo === 'corrupta') {
                setOculto(el, !esCorruptaVisible);
                return;
            }

            if (tipo === 'normal') {
                setOculto(el, esCorruptaVisible);
            }
        });
    }

    function iniciar() {
        aplicarFixColeccion();

        const root = document.querySelector(ROOT_SELECTOR);
        if (!root) return;

        root.addEventListener('click', () => {
            window.requestAnimationFrame(aplicarFixColeccion);
        }, true);

        const observer = new MutationObserver(() => {
            window.requestAnimationFrame(aplicarFixColeccion);
        });

        observer.observe(root, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'hidden', 'aria-disabled', 'aria-current', 'data-variante', 'data-variant', 'data-bonus-variante', 'data-bonus-variant']
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar, { once: true });
    } else {
        iniciar();
    }
})();
