// runa-sync.js — Runaworld v6.5: packs grandes + visual unitario estable
//
// Objetivo:
//   - El servidor genera y reserva/cobra packs de hasta 25 tiradas.
//   - El jugador ve consumo de 1 en 1: click -> -1 coin -> runa; el inventario se confirma por lotes.
//   - Cuando quedan pocas unidades del pack, se pide el siguiente pack.
//   - Las coins visuales se calculan como: coins_servidor + unidades_prepagadas - clicks_en_espera.

(function () {
    'use strict';

    var ENDPOINT_PACK    = 'PHP/crear_pack_tiradas.php';
    var ENDPOINT_CONFIRM = 'PHP/confirmar_pack_tiradas.php';

    var PACK_SIZE        = 50;
    var PREFETCH_AT      = 10;
    var CONFIRM_INTERVAL = 30000;
    var HARD_QUEUE_CAP   = 120;
    var PACK_DEBOUNCE_MS = 150;

    var cola = [];
    var solicitandoPack = false;
    var packDebounceTimer = null;
    var retryPackTimer = null;
    var halted = false;
    var epoch = 0;
    var waitingClicks = 0;

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
        return hex[0]+hex[1]+hex[2]+hex[3]+'-'+hex[4]+hex[5]+'-'+hex[6]+hex[7]+'-'+hex[8]+hex[9]+'-'+hex[10]+hex[11]+hex[12]+hex[13]+hex[14]+hex[15];
    }

    function visualCoinsEnteras() {
        if (typeof coins === 'undefined') return 0;
        return Math.max(0, Math.floor(parseFloat(coins) || 0));
    }

    function aplicarDeltaCoins(delta) {
        if (!delta || typeof coins === 'undefined') return;
        coins = Math.max(0, (parseFloat(coins) || 0) + delta);
        if (typeof actualizarPantalla === 'function') actualizarPantalla();
    }

    function estimarCoinsServidorDisponibles() {
        return Math.max(0, Math.floor(visualCoinsEnteras() - waitingClicks));
    }

    function recalcularCoinsVisualDesdeServidor(serverCoins) {
        if (typeof coins === 'undefined') return;
        var base = parseFloat(serverCoins);
        if (isNaN(base)) return;
        if (cola.length > 0 || waitingClicks > 0 || solicitandoPack) return;
        coins = Math.min(parseFloat(coins) || 0, Math.max(0, Math.floor(base)));
        if (typeof actualizarPantalla === 'function') actualizarPantalla();
    }

    function sincronizarCoinsServidor(serverCoins) {
        if (typeof coins === 'undefined') return;
        var base = parseFloat(serverCoins);
        if (isNaN(base)) return;
        coins = Math.max(0, Math.floor(base));
        if (typeof actualizarPantalla === 'function') actualizarPantalla();
    }

    function sumarMultiplicadoresUnidad(unidad) {
        var total = 0;
        var arr = Array.isArray(unidad && unidad.runas_ganadas) ? unidad.runas_ganadas : [];
        arr.forEach(function (r) { total += parseFloat(r.multiplicador) || 0; });
        return total;
    }

    function getVisualState() {
        var pendingRawPps = 0;
        cola.forEach(function (u) { pendingRawPps += sumarMultiplicadoresUnidad(u); });
        return {
            prepaidCoins: cola.length,
            waitingClicks: waitingClicks,
            coinAdjustment: cola.length - waitingClicks,
            pendingUnits: cola.length + waitingClicks + (solicitandoPack ? 1 : 0),
            pendingRawPps: pendingRawPps,
            hasPendingVisual: cola.length > 0 || waitingClicks > 0 || solicitandoPack
        };
    }

    function tieneCola() { return cola.length > 0; }

    function puedeIntentarTirada() {
        if (halted) return false;
        return visualCoinsEnteras() > 0 || cola.length > 0;
    }

    function cantidadPackSolicitada() {
        var pendientesNetos = Math.max(0, waitingClicks - cola.length);
        if (pendientesNetos <= 0) return 0;
        var objetivo = Math.max(pendientesNetos, 10);
        var capacidadVisual = Math.max(0, visualCoinsEnteras() + waitingClicks);
        var espacioCola = Math.max(0, HARD_QUEUE_CAP - cola.length);
        if (espacioCola <= 0) return 0;
        return Math.max(1, Math.min(PACK_SIZE, objetivo, capacidadVisual, espacioCola));
    }

    function debePedirPackPorEspera() {
        return !halted && !solicitandoPack && waitingClicks > 0 && cola.length < waitingClicks;
    }

    function debePrefetch() {
        return false;
    }

    function programarRetryPack(ms) {
        if (retryPackTimer || halted) return;
        retryPackTimer = setTimeout(function () {
            retryPackTimer = null;
            if (debePedirPackPorEspera() || debePrefetch()) pedirPack();
        }, Math.max(250, ms || 800));
    }

    function programarPedirPack() {
        if (halted || solicitandoPack) return;
        if (waitingClicks >= PACK_SIZE) {
            if (packDebounceTimer) {
                clearTimeout(packDebounceTimer);
                packDebounceTimer = null;
            }
            pedirPack();
            return;
        }
        if (packDebounceTimer) return;
        packDebounceTimer = setTimeout(function () {
            packDebounceTimer = null;
            if (debePedirPackPorEspera()) pedirPack();
        }, PACK_DEBOUNCE_MS);
    }

    function reembolsarWaiting(cantidad) {
        cantidad = Math.max(0, Math.min(waitingClicks, parseInt(cantidad, 10) || 0));
        if (cantidad <= 0) return;
        waitingClicks -= cantidad;
        aplicarDeltaCoins(cantidad);
    }

    function pedirPack() {
        if (halted || solicitandoPack) return Promise.resolve(null);
        if (Date.now() < serverErrorCooldownUntil) return Promise.resolve(null);
        if (packDebounceTimer) {
            clearTimeout(packDebounceTimer);
            packDebounceTimer = null;
        }

        var cantidadPedida = cantidadPackSolicitada();
        if (cantidadPedida <= 0) {
            if (waitingClicks > 0 && cola.length === 0) reembolsarWaiting(waitingClicks);
            return Promise.resolve(null);
        }

        solicitandoPack = true;
        var miEpoch = epoch;
        var packId = nuevoId();

        return fetch(ENDPOINT_PACK, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cantidad: cantidadPedida, pack_id: packId, debug: window.RW_DEBUG_ECONOMIA ? 1 : 0 })
        })
        .then(function (res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function (data) {
            if (miEpoch !== epoch || halted) return null;

            if (!data.ok) {
                var retry = parseInt(data.retry_ms, 10) || 1000;
                serverErrorCooldownUntil = Date.now() + retry;

                // Si el servidor falla, NO dejamos deuda de clicks. Se devuelve
                // la coin visual reservada para que cada click futuro sea limpio.
                if (waitingClicks > 0 && cola.length === 0) reembolsarWaiting(waitingClicks);

                if (data.retry_ms !== undefined) {
                    programarRetryPack(retry);
                } else {
                    document.dispatchEvent(new CustomEvent('runas:error', { detail: data }));
                }
                return data;
            }

            if (data.luck_multiplier !== undefined && typeof window.setLuck === 'function') {
                window.setLuck(data.luck_multiplier);
            }

            var unidades = Array.isArray(data.unidades) ? data.unidades : [];
            unidades.forEach(function (u) {
                u._pack_meta = {
                    coins: data.coins,
                    points: data.points,
                    coins_por_seg: data.coins_por_seg,
                    points_por_seg: data.points_por_seg,
                    luck_multiplier: data.luck_multiplier,
                    total_clicks: data.clicks_validos || unidades.length
                };
                cola.push(u);
            });

            if (data.coins !== undefined) {
                var calculoServidor = Math.max(0, (parseFloat(data.coins) || 0) + cola.length - waitingClicks);
            
                if (calculoServidor > visualCoinsEnteras()) {
                    recalcularCoinsVisualDesdeServidor(data.coins);
                }
            }

            var autorizadas = (data.clicks_validos !== undefined) ? (parseInt(data.clicks_validos, 10) || 0) : unidades.length;
            if (autorizadas <= 0 || unidades.length === 0) {
                waitingClicks = 0;
                if (data.coins !== undefined) sincronizarCoinsServidor(data.coins);
                serverErrorCooldownUntil = Date.now() + 500;
            } else if (waitingClicks > cola.length) {
                reembolsarWaiting(waitingClicks - cola.length);
                if (data.coins !== undefined) {
                    var maxVisual = Math.max(0, Math.floor((parseFloat(data.coins) || 0) + cola.length - waitingClicks));
                    if (visualCoinsEnteras() > maxVisual) sincronizarCoinsServidor(maxVisual);
                }
            }

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
            if (waitingClicks > 0 && cola.length === 0) reembolsarWaiting(waitingClicks);
            programarRetryPack(1000);
        })
        .then(function (data) {
            solicitandoPack = false;
            if (Date.now() >= serverErrorCooldownUntil && (debePedirPackPorEspera() || debePrefetch())) programarRetryPack(250);
            return data;
        });
    }

    function registrarClic(cantidad) {
        if (halted) return false;
        cantidad = cantidad || 1;
        var hizoAlgo = false;

        for (var i = 0; i < cantidad; i++) {
            if (visualCoinsEnteras() <= 0) break;

            aplicarDeltaCoins(-1);
            hizoAlgo = true;

            if (cola.length > 0) {
                var unidad = cola.shift();
                emitirUnidad(unidad);
                marcarConsumida(unidad);
                if (debePrefetch()) pedirPack();
            } else {
                if (waitingClicks >= HARD_QUEUE_CAP) {
                    aplicarDeltaCoins(1);
                    break;
                }
                waitingClicks++;
                if (debePedirPackPorEspera()) programarPedirPack();
            }
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
        if (debePedirPackPorEspera()) programarPedirPack();
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
            coins_delta: 0,
    
            // NO mandamos coins aquí.
            // meta.coins es el valor del servidor al crear el pack,
            // no el valor real después de cada unidad consumida.
    
            runas_ganadas: unidad.runas_ganadas || [],
            luck_multiplier: meta.luck_multiplier
        };
    
        document.dispatchEvent(new CustomEvent('runas:sync', { detail: data }));
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

    function confirmarConsumos() {
        if (halted) return Promise.resolve(null);
        if (confirmInFlight) {
            programarConfirmacion();
            return confirmPromise || Promise.resolve(null);
        }

        var packIds = Object.keys(consumidasPorPack);
        if (packIds.length === 0) return Promise.resolve(null);

        var snapshot = {};
        packIds.forEach(function (packId) { snapshot[packId] = consumidasPorPack[packId]; });
        confirmInFlight = true;

        confirmPromise = Promise.all(packIds.map(function (packId) {
            return fetch(ENDPOINT_CONFIRM, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pack_id: packId, consumidas: snapshot[packId], debug: window.RW_DEBUG_ECONOMIA ? 1 : 0 })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok && consumidasPorPack[packId] === snapshot[packId]) {
                    delete consumidasPorPack[packId];
                }
                return data;
            })
            .catch(function () { return null; });
        }))
        .then(function (results) {
            confirmInFlight = false;
            confirmPromise = null;
            // Si el servidor devuelve inventario confirmado, lo reenviamos al mismo listener
            // que usa tirada.js. Con el fix v112, esta actualización nunca puede bajar
            // cantidades visuales por una respuesta vieja; solo consolida/sube.
            (results || []).forEach(function (data) {
                if (data && data.ok && Array.isArray(data.runas)) {
                    document.dispatchEvent(new CustomEvent('runas:sync', { detail: data }));
                }
            });
            if (Object.keys(consumidasPorPack).length > 0) programarConfirmacion();
            return results;
        });
        return confirmPromise;
    }

    function confirmarBeacon() {
        Object.keys(consumidasPorPack).forEach(function (packId) {
            var body = JSON.stringify({ pack_id: packId, consumidas: consumidasPorPack[packId] });
            try {
                navigator.sendBeacon(ENDPOINT_CONFIRM, new Blob([body], { type: 'application/json' }));
                delete consumidasPorPack[packId];
            } catch (e) { /* no critico */ }
        });
    }

    window.addEventListener('pagehide', confirmarBeacon);
    window.addEventListener('beforeunload', confirmarBeacon);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') confirmarBeacon();
    });

    function flushSync() { return confirmarConsumos(); }
    function prefetch() { if (debePrefetch()) return pedirPack(); return Promise.resolve(null); }

    function reset() {
        epoch++;
        halted = true;
        cola = [];
        waitingClicks = 0;
        solicitandoPack = false;
        consumidasPorPack = {};
        confirmInFlight = false;
        confirmPromise = null;
        if (confirmTimer) { clearTimeout(confirmTimer); confirmTimer = null; }
        if (packDebounceTimer) { clearTimeout(packDebounceTimer); packDebounceTimer = null; }
        if (retryPackTimer) { clearTimeout(retryPackTimer); retryPackTimer = null; }
    }

    window.runaSync = {
        version: '6.8-fix-confirm-dispatch',
        registrarClic: registrarClic,
        flushSync: flushSync,
        reset: reset,
        prefetch: prefetch,
        tieneCola: tieneCola,
        puedeIntentarTirada: puedeIntentarTirada,
        getVisualState: getVisualState,
        _debugState: function () { return { cola: cola.length, waitingClicks: waitingClicks, solicitandoPack: solicitandoPack, visualCoins: visualCoinsEnteras(), capacidadServidor: estimarCoinsServidorDisponibles(), cooldownMs: Math.max(0, serverErrorCooldownUntil - Date.now()) }; }
    };
})();
