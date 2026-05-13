// runa-sync.js — Runaworld v7.1
// Fix principal:
// - No reembolsa clicks nuevos hechos mientras un pack está en vuelo.
// - Si un pack falla, solo reembolsa los clicks de ESE pack.
// - Las confirmaciones ya no pisan las coins visuales.
// - Cada click válido resta 1 coin visual y genera 1 unidad de pack.

(function () {
    'use strict';

    var ENDPOINT_PACK    = '/PHP/crear_pack_tiradas.php';
    var ENDPOINT_CONFIRM = '/PHP/confirmar_pack_tiradas.php';

    var PACK_SIZE        = 50;
    var CONFIRM_INTERVAL = 30000;
    var HARD_QUEUE_CAP   = 120;
    var PACK_DEBOUNCE_MS = 120;
    var RW_CLICK_MIN_MS  = 70;

    var rwUltimoClickMs = 0;

    var cola = [];
    var waitingClicks = 0;
    var solicitandoPack = false;
    var halted = false;
    var epoch = 0;

    var packDebounceTimer = null;
    var retryPackTimer = null;

    var consumidasPorPack = {};
    var confirmTimer = null;
    var confirmInFlight = false;
    var confirmPromise = null;

    var serverErrorCooldownUntil = 0;

    function nuevoId() {
        var bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);

        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;

        var hex = [];

        for (var i = 0; i < 16; i++) {
            var h = bytes[i].toString(16);
            hex.push(h.length === 1 ? '0' + h : h);
        }

        return (
            hex[0] + hex[1] + hex[2] + hex[3] + '-' +
            hex[4] + hex[5] + '-' +
            hex[6] + hex[7] + '-' +
            hex[8] + hex[9] + '-' +
            hex[10] + hex[11] + hex[12] + hex[13] + hex[14] + hex[15]
        );
    }

    function actualizarUI() {
        if (typeof actualizarPantalla === 'function') {
            actualizarPantalla();
        }
    }

    function visualCoinsEnteras() {
        if (typeof coins === 'undefined') return 0;
        return Math.max(0, Math.floor(parseFloat(coins) || 0));
    }

    function aplicarDeltaCoins(delta) {
        if (!delta || typeof coins === 'undefined') return;

        coins = Math.max(0, (parseFloat(coins) || 0) + delta);
        actualizarUI();
    }

    function sumarMultiplicadoresUnidad(unidad) {
        var total = 0;
        var arr = Array.isArray(unidad && unidad.runas_ganadas) ? unidad.runas_ganadas : [];

        arr.forEach(function (r) {
            total += parseFloat(r.multiplicador) || 0;
        });

        return total;
    }

    function getVisualState() {
        var pendingRawPps = 0;

        cola.forEach(function (u) {
            pendingRawPps += sumarMultiplicadoresUnidad(u);
        });

        return {
            prepaidCoins: cola.length,
            waitingClicks: waitingClicks,
            coinAdjustment: cola.length - waitingClicks,
            pendingUnits: cola.length + waitingClicks + (solicitandoPack ? 1 : 0),
            pendingRawPps: pendingRawPps,
            hasPendingVisual: cola.length > 0 || waitingClicks > 0 || solicitandoPack
        };
    }

    function tieneCola() {
        return cola.length > 0;
    }

    function puedeIntentarTirada() {
        if (halted) return false;
        return visualCoinsEnteras() > 0 || cola.length > 0;
    }

    function cantidadPackSolicitada() {
        if (waitingClicks <= 0) return 0;

        var espacioCola = Math.max(0, HARD_QUEUE_CAP - cola.length);

        if (espacioCola <= 0) return 0;

        return Math.max(1, Math.min(
            PACK_SIZE,
            waitingClicks,
            espacioCola
        ));
    }

    function debePedirPack() {
        return !halted &&
               !solicitandoPack &&
               waitingClicks > 0 &&
               cola.length < waitingClicks &&
               Date.now() >= serverErrorCooldownUntil;
    }

    function programarPedirPack() {
        if (!debePedirPack()) return;

        if (packDebounceTimer) return;

        packDebounceTimer = setTimeout(function () {
            packDebounceTimer = null;

            if (debePedirPack()) {
                pedirPack();
            }
        }, PACK_DEBOUNCE_MS);
    }

    function programarRetryPack(ms) {
        if (retryPackTimer || halted) return;

        retryPackTimer = setTimeout(function () {
            retryPackTimer = null;

            if (debePedirPack()) {
                pedirPack();
            }
        }, Math.max(250, ms || 800));
    }

    function reembolsarClicks(cantidad) {
        cantidad = Math.max(0, parseInt(cantidad, 10) || 0);

        if (cantidad <= 0) return;

        var reembolsoReal = Math.min(waitingClicks, cantidad);

        if (reembolsoReal <= 0) return;

        waitingClicks -= reembolsoReal;
        aplicarDeltaCoins(reembolsoReal);
    }

    function pedirPack() {
        if (!debePedirPack()) return Promise.resolve(null);

        if (packDebounceTimer) {
            clearTimeout(packDebounceTimer);
            packDebounceTimer = null;
        }

        var cantidadPedida = cantidadPackSolicitada();

        if (cantidadPedida <= 0) {
            return Promise.resolve(null);
        }

        solicitandoPack = true;

        var miEpoch = epoch;
        var packId = nuevoId();

        return fetch(ENDPOINT_PACK, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                cantidad: cantidadPedida,
                pack_id: packId,
                debug: window.RW_DEBUG_ECONOMIA ? 1 : 0
            })
        })
        .then(function (res) {
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }

            return res.json();
        })
        .then(function (data) {
            if (miEpoch !== epoch || halted) return null;

            if (!data || !data.ok) {
                var retry = parseInt(data && data.retry_ms, 10) || 1000;

                serverErrorCooldownUntil = Date.now() + retry;

                // FIX:
                // Si falla este pack, solo devolvemos los clicks pedidos por ESTE pack.
                // No se devuelven clicks nuevos hechos mientras la petición estaba en vuelo.
                reembolsarClicks(cantidadPedida);

                if (data && data.retry_ms !== undefined) {
                    programarRetryPack(retry);
                } else {
                    document.dispatchEvent(new CustomEvent('runas:error', {
                        detail: data || { ok: false, error: 'pack_failed' }
                    }));
                }

                return data;
            }

            if (data.luck_multiplier !== undefined && typeof window.setLuck === 'function') {
                window.setLuck(data.luck_multiplier);
            }

            var unidades = Array.isArray(data.unidades) ? data.unidades : [];

            var autorizadas = data.clicks_validos !== undefined
                ? (parseInt(data.clicks_validos, 10) || 0)
                : unidades.length;

            if (autorizadas < cantidadPedida) {
                // Solo reembolsa la parte de ESTE pack que el servidor no autorizó.
                reembolsarClicks(cantidadPedida - autorizadas);
            }

            unidades.forEach(function (u) {
                u._pack_meta = {
                    points: data.points,
                    coins_por_seg: data.coins_por_seg,
                    points_por_seg: data.points_por_seg,
                    luck_multiplier: data.luck_multiplier,
                    bulk: data.bulk,
                    total_clicks: data.clicks_validos || unidades.length
                };

                cola.push(u);
            });

            if (data.clicks_validos !== undefined && data.clicks_validos < cantidadPedida) {
                document.dispatchEvent(new CustomEvent('runas:recorte', {
                    detail: {
                        enviados: cantidadPedida,
                        validos: data.clicks_validos,
                        motivo: data.motivo_corte || 'sin_coins'
                    }
                }));
            }

            consumirPendientes();

            return data;
        })
        .catch(function (err) {
            console.warn('[runa-sync] fallo creando pack', err);

            serverErrorCooldownUntil = Date.now() + 1000;

            // FIX:
            // Si la request falla, solo reembolsamos los clicks de esa request.
            reembolsarClicks(cantidadPedida);

            programarRetryPack(1000);

            return null;
        })
        .then(function (data) {
            solicitandoPack = false;

            if (debePedirPack()) {
                programarPedirPack();
            }

            return data;
        });
    }

    function registrarClic(cantidad) {
        if (halted) return false;

        var ahora = Date.now();

        if (ahora - rwUltimoClickMs < RW_CLICK_MIN_MS) {
            return false;
        }

        rwUltimoClickMs = ahora;

        cantidad = cantidad || 1;

        var hizoAlgo = false;

        for (var i = 0; i < cantidad; i++) {
            if (cola.length > 0) {
                var unidad = cola.shift();

                emitirUnidad(unidad);
                marcarConsumida(unidad);

                hizoAlgo = true;
                continue;
            }

            if (visualCoinsEnteras() <= 0) {
                break;
            }

            if (waitingClicks >= HARD_QUEUE_CAP) {
                break;
            }

            aplicarDeltaCoins(-1);
            waitingClicks++;
            hizoAlgo = true;
        }

        if (hizoAlgo) {
            programarPedirPack();
        }

        return hizoAlgo;
    }

    function consumirPendientes() {
        while (!halted && waitingClicks > 0 && cola.length > 0) {
            waitingClicks--;

            var unidad = cola.shift();

            emitirUnidad(unidad);
            marcarConsumida(unidad);
        }

        if (debePedirPack()) {
            programarPedirPack();
        }
    }

    function emitirUnidad(unidad) {
        var meta = unidad._pack_meta || {};

        var data = {
            ok: true,
            mode: 'pack_unit',
            local_visual: true,
            pack_id: unidad.pack_id,
            seq: unidad.seq,
            clicks_enviados: 1,
            clicks_validos: 1,

            // No enviamos coins aquí.
            // El click ya descontó 1 coin visualmente.
            coins_delta: 0,

            runas_ganadas: unidad.runas_ganadas || [],
            coins_por_seg: meta.coins_por_seg,

            // IMPORTANTE:
            // En una unidad visual local NO enviamos points_por_seg.
            // Si lo enviamos, tirada.js no aplica el delta local de la runa,
            // y points/s solo se actualiza al guardar/confirmar.
            // La confirmación o guardado siguen reconciliando el valor real del servidor.
            bulk: meta.bulk,
            luck_multiplier: meta.luck_multiplier
        };

        document.dispatchEvent(new CustomEvent('runas:sync', {
            detail: data
        }));
    }

    function marcarConsumida(unidad) {
        if (!unidad || !unidad.pack_id) return;

        var packId = unidad.pack_id;
        var seq = parseInt(unidad.seq, 10) || 0;

        if (!consumidasPorPack[packId] || seq > consumidasPorPack[packId]) {
            consumidasPorPack[packId] = seq;
        }

        programarConfirmacion();
    }

    function programarConfirmacion() {
        if (confirmTimer || halted) return;

        confirmTimer = setTimeout(function () {
            confirmTimer = null;
            confirmarConsumos();
        }, CONFIRM_INTERVAL);
    }

    function limpiarCoinsDeRespuesta(data) {
        if (!data || typeof data !== 'object') return data;

        // Evita que tirada.js pise las coins visuales con pasivos acumulados
        // durante confirmaciones de packs.
        if (Object.prototype.hasOwnProperty.call(data, 'coins')) {
            delete data.coins;
        }

        return data;
    }

    function confirmarConsumos() {
        if (halted) return Promise.resolve(null);

        if (confirmInFlight) {
            programarConfirmacion();
            return confirmPromise || Promise.resolve(null);
        }

        var packIds = Object.keys(consumidasPorPack);

        if (packIds.length === 0) {
            return Promise.resolve(null);
        }

        var snapshot = {};

        packIds.forEach(function (packId) {
            snapshot[packId] = consumidasPorPack[packId];
        });

        confirmInFlight = true;

        confirmPromise = Promise.all(packIds.map(function (packId) {
            return fetch(ENDPOINT_CONFIRM, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    pack_id: packId,
                    consumidas: snapshot[packId],
                    debug: window.RW_DEBUG_ECONOMIA ? 1 : 0
                })
            })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (data && data.ok && consumidasPorPack[packId] === snapshot[packId]) {
                    delete consumidasPorPack[packId];
                }

                return limpiarCoinsDeRespuesta(data);
            })
            .catch(function () {
                return null;
            });
        }))
        .then(function (results) {
            confirmInFlight = false;
            confirmPromise = null;

            (results || []).forEach(function (data) {
                if (data && data.ok && Array.isArray(data.runas)) {
                    document.dispatchEvent(new CustomEvent('runas:sync', {
                        detail: data
                    }));
                }
            });

            if (Object.keys(consumidasPorPack).length > 0) {
                programarConfirmacion();
            }

            return results;
        });

        return confirmPromise;
    }

    function confirmarBeacon() {
        Object.keys(consumidasPorPack).forEach(function (packId) {
            var body = JSON.stringify({
                pack_id: packId,
                consumidas: consumidasPorPack[packId]
            });

            try {
                navigator.sendBeacon(
                    ENDPOINT_CONFIRM,
                    new Blob([body], {
                        type: 'application/json'
                    })
                );

                delete consumidasPorPack[packId];
            } catch (e) {
                // No crítico.
            }
        });
    }

    window.addEventListener('pagehide', confirmarBeacon);
    window.addEventListener('beforeunload', confirmarBeacon);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            confirmarBeacon();
        }
    });

    function flushSync() {
        return confirmarConsumos();
    }

    function prefetch() {
        if (debePedirPack()) {
            return pedirPack();
        }

        return Promise.resolve(null);
    }

    function reset() {
        epoch++;
        halted = true;

        cola = [];
        waitingClicks = 0;
        solicitandoPack = false;
        consumidasPorPack = {};
        confirmInFlight = false;
        confirmPromise = null;

        if (confirmTimer) {
            clearTimeout(confirmTimer);
            confirmTimer = null;
        }

        if (packDebounceTimer) {
            clearTimeout(packDebounceTimer);
            packDebounceTimer = null;
        }

        if (retryPackTimer) {
            clearTimeout(retryPackTimer);
            retryPackTimer = null;
        }
    }

    window.runaSync = {
        version: '7.1-no-local-pps-meta',
        registrarClic: registrarClic,
        flushSync: flushSync,
        reset: reset,
        prefetch: prefetch,
        tieneCola: tieneCola,
        puedeIntentarTirada: puedeIntentarTirada,
        getVisualState: getVisualState,
        _debugState: function () {
            return {
                version: '7.1-no-local-pps-meta',
                cola: cola.length,
                waitingClicks: waitingClicks,
                solicitandoPack: solicitandoPack,
                visualCoins: visualCoinsEnteras(),
                cooldownMs: Math.max(0, serverErrorCooldownUntil - Date.now())
            };
        }
    };
})();