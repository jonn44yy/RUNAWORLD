// tienda.js v3 — !hi
// rediseno horizontal de la tienda. cada mejora es .mejora-fila con:
//   icono (44px) | barra segmentada de niveles | info derecha
//
// cambios v2 -> v3:
//   * las mejoras BLOQUEADAS NO SE PINTAN. el jugador ni siquiera ve un
//     "?" hasta que se desbloquea, momento en el que aparece con animacion
//     "NUEVA" y el boton de nav "Tienda" tiene glow (juego.php + nav-btn-nueva
//     en CSS). esto da satisfaccion de descubrir contenido en vez de
//     ver una lista llena de candados desde el principio
//   * uso window.fmt() para los costes (50000000000 -> "50B" en vez de
//     un texto monstruoso). fmt aguanta hasta Dc, ver formato.js
//   * expongo window.marcarMejorasComoVistas() para que el onclick del
//     nav lo llame y registre las mejoras vistas en el server
//
// estructura HTML que produzco (que casa con tienda.css existente):
//   .mejora-grupo[data-tier="morada|dorada|normal"]
//     .mejora-grupo-titulo
//     .mejora-fila[data-tier="..."]
//       .mejora-icono [data-fallback="..."]
//       .mejora-centro
//         .mejora-info-top
//           .mejora-nombre / .mejora-nivel
//         .mejora-barra (.mejora-bloque .lleno?)
//         .mejora-info-bot
//           .mejora-valor / .mejora-siguiente / .mejora-coste

(function () {
    'use strict';

    // ----------------------------------------------------------------
    // helpers
    // ----------------------------------------------------------------

    function formatear(n) {
        if (typeof window.fmt === 'function') return window.fmt(n);
        return Math.round(n).toLocaleString('es-ES');
    }

    // espejo de las formulas del server (juego.php / comprar_mejora.php).
    // si cambias la formula en uno, cambiala en ambos
    function textoValorActual(m) {
        var n = m.nivel_actual, v = m.valor;
        switch (m.tipo) {
            case "coins_seg":
                return "+" + formatear(n * (n + 1) / 2 * v) + " coins/seg";
            case "coins_seg_multi":
            case "coins_seg_multi_eterno":
                return n > 0 ? "x" + Math.pow(2, n) + " coins" : "x1 coins";
            case "points_seg":
                // 28/04 v3.1: lineal (v * n) en vez de geometrica (v * 10^(n-1))
                return n > 0 ? "+" + formatear(v * n) + " pts/seg" : "+0 pts/seg";
            case "points_seg_multi":
            case "points_seg_multi_eterno":
                return n > 0 ? "x" + Math.pow(2, n) + " pts" : "x1 pts";
            case "bulk":
            case "bulk_normal":
                return (1 + n) + " runas/tirada";
            case "bulk_extra":
                return n >= 1 ? "+" + v + " runas/tirada" : "Inactivo";
            case "desbloquear_boost_leg":
                return n >= 1 ? "Legendarias activas" : "Bloqueadas";
            case "desbloquear_boost_div":
                return n >= 1 ? "Divinas activas" : "Bloqueadas";
            default:
                return "Nv " + n;
        }
    }

    function textoSiguiente(m) {
        var nProx = m.nivel_actual + 1, v = m.valor;
        switch (m.tipo) {
            case "coins_seg":
                return "+" + formatear(nProx * (nProx + 1) / 2 * v) + " coins/seg";
            case "coins_seg_multi":
            case "coins_seg_multi_eterno":
                return "x" + Math.pow(2, nProx) + " coins";
            case "points_seg":
                // 28/04 v3.1: lineal (v * nProx) en vez de geometrica
                return "+" + formatear(v * nProx) + " pts/seg";
            case "points_seg_multi":
            case "points_seg_multi_eterno":
                return "x" + Math.pow(2, nProx) + " pts";
            case "bulk":
            case "bulk_normal":
                return (1 + nProx) + " runas/tirada";
            case "bulk_extra":
                return "+" + v + " runas/tirada";
            case "desbloquear_boost_leg":
                return "Activa boosts legendarios";
            case "desbloquear_boost_div":
                return "Activa boosts divinos";
            default:
                return "Nv " + nProx;
        }
    }

    function costeSiguiente(m) {
        return Math.floor(m.coste_base * Math.pow(m.coste_escala, m.nivel_actual));
    }

    // tier visual segun el tipo. mantengo los nombres "morada/dorada/normal"
    // que usa el CSS, no "eterna/especial"
    // 27/04 v3: tipos de suerte eliminados del switch
    function tierDeMejora(m) {
        switch (m.tipo) {
            case "bulk":
            case "coins_seg_multi_eterno":
            case "points_seg_multi_eterno":
                return "morada";
            case "coins_seg_multi":
            case "points_seg_multi":
            case "bulk_extra":
                return "dorada";
            default:
                return "normal";
        }
    }

    // ----------------------------------------------------------------
    // render de UNA fila
    // ----------------------------------------------------------------

    function renderMejora(m) {
        var tier         = tierDeMejora(m);
        // GUARDA: solo es MAX si has comprado al menos 1 nivel Y has llegado
        // al maximo. sin esta guarda, datos corruptos (nivel_maximo=0 o bug
        // en SELECT que no devuelve nivel_actual) hacian que TODAS las
        // mejoras saliesen como MAX al cumplirse "0 >= 0". por eso un
        // jugador recien reseteado podia ver toda la tienda como comprada
        var nMax         = (m.nivel_maximo > 0) ? m.nivel_maximo : 1;
        var nAct         = m.nivel_actual || 0;
        var maxAlcanzado = nAct > 0 && nAct >= nMax;
        var coste        = maxAlcanzado ? 0 : costeSiguiente(m);

        // 28/04 v3.1: eliminada la clase 'no-fondos' que atenuaba las mejoras
        // cuando el jugador no tenia saldo. ahora todas se ven brillantes
        // siempre. si no hay saldo y se intenta comprar, el server responde
        // con error y se muestra el mensaje en #msg-tienda.

        var fila = document.createElement('div');
        fila.className = 'mejora-fila' +
            (maxAlcanzado ? ' completada' : '') +
            (m.es_nueva   ? ' nueva'      : '');
        fila.setAttribute('data-tier', tier);
        fila.dataset.id = m.id;

        // icono. si la BD tiene un campo `imagen` (no esta hoy en el schema
        // pero no rompe si no esta), uso esa ruta. fallback: primera letra
        // del nombre. el CSS oculta el ::after si tiene la clase tiene-img
        var icono = document.createElement('div');
        icono.className = 'mejora-icono';
        icono.setAttribute('data-fallback', (m.nombre || '?').charAt(0).toUpperCase());
        // por ahora sin imagen real. cuando metas PNGs en /IMG, descomenta:
        // if (m.imagen) {
        //     var img = document.createElement('img');
        //     img.src = 'IMG/' + m.imagen;
        //     icono.appendChild(img);
        //     icono.classList.add('tiene-img');
        // }
        fila.appendChild(icono);

        // centro: info-top (nombre + nivel), barra, info-bot (valor + coste)
        var centro = document.createElement('div');
        centro.className = 'mejora-centro';

        var infoTop = document.createElement('div');
        infoTop.className = 'mejora-info-top';
        var nombre = document.createElement('span');
        nombre.className = 'mejora-nombre';
        nombre.textContent = m.nombre;
        var nivel = document.createElement('span');
        nivel.className = 'mejora-nivel';
        nivel.textContent = nAct + '/' + nMax;
        infoTop.appendChild(nombre);
        infoTop.appendChild(nivel);
        centro.appendChild(infoTop);

        // barra segmentada: un .mejora-bloque por nivel maximo, .lleno
        // los que ya tiene. uso flexBasis = 100/N% para que ocupen todo el
        // ancho aunque haya 20 niveles
        var barra = document.createElement('div');
        barra.className = 'mejora-barra';
        for (var i = 0; i < nMax; i++) {
            var bloque = document.createElement('div');
            bloque.className = 'mejora-bloque' + (i < nAct ? ' lleno' : '');
            bloque.style.flexBasis = (100 / nMax) + '%';
            barra.appendChild(bloque);
        }
        centro.appendChild(barra);

        // info-bot: "x1.00 suerte -> x1.10 suerte" + "500 pts"
        var infoBot = document.createElement('div');
        infoBot.className = 'mejora-info-bot';
        var izq = document.createElement('span');
        var valor = document.createElement('span');
        valor.className = 'mejora-valor';
        valor.textContent = textoValorActual(m);
        izq.appendChild(valor);
        if (!maxAlcanzado) {
            var siguiente = document.createElement('span');
            siguiente.className = 'mejora-siguiente';
            siguiente.textContent = '→ ' + textoSiguiente(m);
            izq.appendChild(document.createTextNode(' '));
            izq.appendChild(siguiente);
        }
        var coste_el = document.createElement('span');
        coste_el.className = 'mejora-coste' + (maxAlcanzado ? ' max' : '');
        coste_el.textContent = maxAlcanzado ? 'MAX' : (formatear(coste) + ' pts');
        infoBot.appendChild(izq);
        infoBot.appendChild(coste_el);
        centro.appendChild(infoBot);

        fila.appendChild(centro);

        // click = comprar (si no esta MAX)
        if (!maxAlcanzado) {
            fila.addEventListener('click', function () {
                comprarMejora(m.id, fila);
            });
        }

        return fila;
    }

    // ----------------------------------------------------------------
    // render de la tienda completa
    // ----------------------------------------------------------------

    function renderTienda() {
        var cont = document.getElementById('seccion-tienda');
        if (!cont) return;

        // limpio grupos viejos sin tocar el titulo de la seccion ni el msg
        cont.querySelectorAll('.mejora-grupo').forEach(function (n) { n.remove(); });

        var mejoras = (window.RW_INIT && window.RW_INIT.mejoras_completas) || [];

        // FILTRO CLAVE v3: las bloqueadas no se pintan
        var visibles = mejoras.filter(function (m) { return !m.bloqueada; });

        // ordeno por el campo `orden` de la BD
        visibles.sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); });

        // agrupo por tier
        var grupos = { morada: [], dorada: [], normal: [] };
        visibles.forEach(function (m) { grupos[tierDeMejora(m)].push(m); });

        // pinto cada grupo en el orden eternas -> especiales -> normales
        ['morada', 'dorada', 'normal'].forEach(function (tier) {
            if (grupos[tier].length === 0) return;
            var grupo = document.createElement('div');
            grupo.className = 'mejora-grupo';
            grupo.setAttribute('data-tier', tier);

            var titulo = document.createElement('div');
            titulo.className = 'mejora-grupo-titulo';
            titulo.textContent = ({
                morada: 'Eternas',
                dorada: 'Especiales',
                normal: 'Normales'
            })[tier];
            grupo.appendChild(titulo);

            grupos[tier].forEach(function (m) {
                grupo.appendChild(renderMejora(m));
            });
            cont.appendChild(grupo);
        });
    }

    // ----------------------------------------------------------------
    // accion: comprar
    // ----------------------------------------------------------------

    function comprarMejora(id, fila) {
        // flush de runa-sync para que los pts del server reflejen los
        // clicks pendientes (si compras justo despues de tirar mucho)
        var p = (window.runaSync && typeof window.runaSync.flushSync === 'function')
            ? window.runaSync.flushSync('pre-compra')
            : Promise.resolve(null);

        p.then(function () {
            return fetch('PHP/comprar_mejora.php', {
                method:      'POST',
                credentials: 'same-origin',
                headers:     { 'Content-Type': 'application/json' },
                body:        JSON.stringify({ mejora_id: id })
            });
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var msgEl = document.getElementById('msg-tienda');
            if (!data.ok) {
                if (msgEl) {
                    msgEl.textContent = data.error || 'Error al comprar';
                    msgEl.classList.add('visible');
                    setTimeout(function () {
                        if (msgEl) {
                            msgEl.textContent = '';
                            msgEl.classList.remove('visible');
                        }
                    }, 2500);
                }
                return;
            }
            // pulso visual de confirmacion (anim de 350ms en CSS)
            fila.classList.add('comprado');
            setTimeout(function () { fila.classList.remove('comprado'); }, 400);

            // sincronizar coins y points reales del server (puede haber
            // diferencia con el display por pasivos acumulados durante
            // el flush). RW_PLAYER es la fuente de verdad para tienda.js
            if (!window.RW_PLAYER) window.RW_PLAYER = {};
            if (data.coins  !== undefined) window.RW_PLAYER.coins  = parseFloat(data.coins);
            if (data.points !== undefined) window.RW_PLAYER.points = parseFloat(data.points);
            // 28/04 v3.1: usar setters de ui.js para que actualice la variable
            // INTERNA de scope (let coins/points), no solo window. sin esto,
            // el sidebar seguia con el saldo viejo tras comprar una mejora
            if (data.coins !== undefined && typeof window.setCoins === "function") {
                window.setCoins(data.coins);
            }
            if (data.points !== undefined && typeof window.setPoints === "function") {
                window.setPoints(data.points);
            }

            // sincronizar niveles de TODAS las mejoras desde mejoras_jugador.
            // el server devuelve solo las que tienen nivel >= 1 (las
            // compradas). las que no estan en la lista se mantienen a 0.
            // sin esto, el cache local se quedaba con niveles desactualizados
            // y la tienda pintaba "0/5" en mejoras que ya tenias compradas
            if (Array.isArray(data.mejoras_jugador)) {
                var nivelPorId = {};
                data.mejoras_jugador.forEach(function (mj) {
                    nivelPorId[parseInt(mj.id)] = parseInt(mj.nivel) || 0;
                });
                (window.RW_INIT.mejoras_completas || []).forEach(function (m) {
                    m.nivel_actual = nivelPorId[m.id] || 0;
                });
            } else if (data.nivel_actual !== undefined) {
                // fallback: solo subo el nivel de la comprada
                var mc = window.RW_INIT.mejoras_completas.find(function (x) { return x.id === id; });
                if (mc) mc.nivel_actual = parseInt(data.nivel_actual);
            }

            // procesar mejoras recien desbloqueadas (los ids que llegan
            // en data.nuevas_desbloqueadas). se marcan como bloqueada=false
            // y es_nueva=true para que aparezcan con el badge "NUEVA" y
            // el glow del nav se encienda automaticamente sin recargar
            if (Array.isArray(data.nuevas_desbloqueadas) && data.nuevas_desbloqueadas.length > 0) {
                data.nuevas_desbloqueadas.forEach(function (nid) {
                    var mu = window.RW_INIT.mejoras_completas.find(function (x) { return x.id === parseInt(nid); });
                    if (mu) {
                        mu.bloqueada       = false;
                        mu.condicion_texto = '';
                        mu.es_nueva        = true;
                    }
                });
            }

            // recalcular stats del sidebar usando ui.js (sigue las mismas
            // formulas que el server). asi al comprar Molino, el +X/seg
            // del panel sube YA, sin esperar al guardar_progreso de los 30s
            if (typeof window.recalcularStatsDesdeMejoras === 'function'
                && Array.isArray(data.mejoras_jugador)) {
                window.recalcularStatsDesdeMejoras(data.mejoras_jugador);
            }

            actualizarGlowNav();
            renderTienda();
        })
        .catch(function (err) { console.warn('[tienda] error compra', err); });
    }

    // ----------------------------------------------------------------
    // mejoras nuevas: marcado y glow del nav
    // ----------------------------------------------------------------

    // se llama desde el onclick del nav "Tienda" (ver juego.php)
    function marcarMejorasComoVistas() {
        if (!window.RW_INIT || !window.RW_INIT.hay_mejoras_nuevas) return;

        var ids = (window.RW_INIT.mejoras_completas || [])
            .filter(function (m) { return !m.bloqueada; })
            .map(function (m) { return m.id; });

        fetch('PHP/marcar_vistas.php', {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ ids: ids })
        }).catch(function () { /* si falla no es critico */ });

        // optimista: limpio flags ya, sin esperar al server
        (window.RW_INIT.mejoras_completas || []).forEach(function (m) {
            if (!m.bloqueada) m.es_nueva = false;
        });
        window.RW_INIT.hay_mejoras_nuevas = false;
        actualizarGlowNav();
        renderTienda();
    }

    function actualizarGlowNav() {
        var btn = document.getElementById('nav-btn-tienda');
        if (!btn) return;
        var hay = (window.RW_INIT.mejoras_completas || [])
            .some(function (m) { return !m.bloqueada && m.es_nueva; });
        btn.classList.toggle('nav-btn-nueva', hay);
        window.RW_INIT.hay_mejoras_nuevas = hay;
    }

    window.marcarMejorasComoVistas = marcarMejorasComoVistas;
    window.renderTienda            = renderTienda;
    window.actualizarGlowNav       = actualizarGlowNav;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderTienda);
    } else {
        renderTienda();
    }
})();
