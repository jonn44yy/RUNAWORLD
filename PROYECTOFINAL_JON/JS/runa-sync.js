// 24/04 v3: anadido runaSync.reset() para usarlo desde el flujo de
// "borrar progreso". sin eso, los clicks que estuvieran buffereados o
// en vuelo cuando el jugador reseteaba se aplicaban sobre la cuenta
// recien borrada (por eso le "volvian" coins y stats viejas).
//
// reset() vacia el buffer, marca un epoch nuevo para invalidar respuestas
// de batches en vuelo, y para el latido base. el llamador (ajustes.js)
// debe forzar un location.reload() despues de la respuesta de
// borrar_progreso.php para tener el RW_INIT fresco.
//
// !hi

(function () {
    var ENDPOINT    = "PHP/tirar_runa.php";
    var INTERVAL_MS = 30000;   // latido base
    var BURST_SIZE  = 3;       // flush cada N clicks acumulados
    var HARD_CAP    = 2000;    // tope por seguridad

    var buffer    = 0;
    var inFlight  = false;
    var pending   = false;
    var epoch     = 0;        // se incrementa en reset(): si una request en
                              //   vuelo termina con epoch viejo, se ignora
    var halted    = false;    // si true, dejamos de aceptar clicks (post-reset
                              //   hasta que la pagina recargue)
    var heartbeat = null;     // ref del setInterval para poder pararlo

    // uuid v4 para idempotencia
    function nuevoBatchId() {
        var bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = [];
        for (var i = 0; i < 16; i++) {
            var h = bytes[i].toString(16);
            hex.push(h.length === 1 ? "0" + h : h);
        }
        return hex[0]+hex[1]+hex[2]+hex[3]+"-"+hex[4]+hex[5]+"-"+hex[6]+hex[7]+
               "-"+hex[8]+hex[9]+"-"+hex[10]+hex[11]+hex[12]+hex[13]+hex[14]+hex[15];
    }

    // se llama desde el onclick del boton. aqui NO hay sorteo, solo cuento
    function registrarClic(cantidad) {
        if (halted) return;          // tras reset(), ignoramos hasta el reload
        if (!cantidad) cantidad = 1;
        buffer += cantidad;

        if (buffer >= BURST_SIZE) {
            flush("burst");
            return;
        }
        if (buffer >= HARD_CAP) {
            flush("burst");
        }
    }

    // latido base
    heartbeat = setInterval(function () {
        if (halted) return;
        if (buffer > 0) flush("interval");
    }, INTERVAL_MS);

    // sendBeacon al cerrar pestana
    function flushBeacon() {
        if (halted) return;          // sin esto, un beacon residual aplicaria batch viejo
        if (buffer <= 0) return;
        var body = JSON.stringify({
            clicks:   buffer,
            batch_id: nuevoBatchId(),
            reason:   "unload"
        });
        try {
            var blob = new Blob([body], { type: "application/json" });
            navigator.sendBeacon(ENDPOINT, blob);
        } catch (e) { /* nada que hacer si falla al cerrar */ }
        buffer = 0;
    }
    window.addEventListener("pagehide",     flushBeacon);
    window.addEventListener("beforeunload", flushBeacon);
    document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "hidden") flushBeacon();
    });

    function flushSync(reason) {
        return flush(reason || "critical");
    }

    function flush(reason) {
        if (halted)        return Promise.resolve(null);
        if (buffer <= 0)   return Promise.resolve(null);
        if (inFlight)    { pending = true; return Promise.resolve(null); }

        var enviados = buffer;
        var miEpoch  = epoch;        // congelar el epoch al lanzar
        buffer   = 0;
        inFlight = true;

        var payload = {
            clicks:   enviados,
            batch_id: nuevoBatchId(),
            reason:   reason
        };

        return fetch(ENDPOINT, {
            method:      "POST",
            credentials: "same-origin",
            headers:     { "Content-Type": "application/json" },
            body:        JSON.stringify(payload)
        })
        .then(function (res) {
            if (!res.ok) throw new Error("HTTP " + res.status);
            return res.json();
        })
        .then(function (data) {
            // si entre el envio y la respuesta hicimos reset(), tiramos los datos
            if (miEpoch !== epoch) {
                console.info("[runa-sync] descartando respuesta de batch pre-reset");
                return null;
            }
            if (!data.ok) {
                console.warn("[runa-sync]", data.error);
                document.dispatchEvent(new CustomEvent("runas:error", { detail: data }));
                return data;
            }
            document.dispatchEvent(new CustomEvent("runas:sync", { detail: data }));
            if (data.clicks_validos < data.clicks_enviados) {
                document.dispatchEvent(new CustomEvent("runas:recorte", {
                    detail: {
                        enviados: data.clicks_enviados,
                        validos:  data.clicks_validos,
                        motivo:   data.motivo_corte || "coins_o_tiempo"
                    }
                }));
            }
            return data;
        })
        .catch(function (err) {
            console.warn("[runa-sync] fallo red, re-encolando", err);
            // si reset paso entre medias, NO reencolar (era pre-reset)
            if (miEpoch === epoch && !halted) {
                buffer += enviados;
            }
        })
        .then(function (result) {
            inFlight = false;
            if (pending && !halted) {
                pending = false;
                if (buffer > 0) flush("interval");
            }
            return result;
        });
    }

    // reset(): limpia buffer y para el sistema. se llama ANTES de hacer
    // fetch a borrar_progreso.php. el llamador hara location.reload() tras
    // recibir la respuesta del server, asi entra a la pagina con RW_INIT
    // fresco y un runa-sync nuevo
    function reset() {
        epoch++;                     // invalida respuestas de fetch en vuelo
        buffer  = 0;
        pending = false;
        halted  = true;              // ignora clics y latidos hasta el reload
        if (heartbeat) {
            clearInterval(heartbeat);
            heartbeat = null;
        }
    }

    window.runaSync = {
        registrarClic: registrarClic,
        flushSync:     flushSync,
        reset:         reset
    };
})();
