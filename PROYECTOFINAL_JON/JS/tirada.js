window.RW_TIRADA_VERSION = '8.0-fix-runas-visual-v112';
// tirada.js — runaworld (v2: batch sync)
// version 2: adaptado al sistema de lotes (runa-sync.js). antes cada click
// disparaba un fetch. ahora tirarRuna() solo cuenta clicks en un buffer y
// runa-sync.js se encarga de mandarlos al server en lotes (cada 10 clicks,
// cada 30s, o al cerrar la pestana). la respuesta del server llega como un
// CustomEvent "runas:sync" y procesarRespuestaSync() hace lo mismo que
// hacia el .then() del fetch antiguo
//
// indice:
//   1. tirarRuna()              — registra click, NO fetch
//   2. procesarRespuestaSync()  — todo lo que hacia el .then() viejo
//   3. listeners de runas:sync y runas:recorte
//   4. actualizarPanelRunas()   — sin cambios, update in-place
//   5. guardarProgreso()        — auto-save cada 30s (solo idle)
//
// lenguaje interno:
//   tiradaBloqueada  = flag global (en ui.js) que bloquea clicks durante
//                      animaciones especiales (eterna ~20s, divina ~13s)
//   syncSafetyTimer  = timeout de 25s por si tiradaBloqueada se queda
//                      atascada en true. antes era por fetch, ahora es por
//                      animacion de especial
//   points_ps        = (base_runas + points_add) * multi_pts. base_runas
//                      viene del server, los otros son mejoras + boosts
//   suerte formula   = (1 + b) * c * d. el server manda "d" (suerte_grupo)
//   especiales       = eterna/divina/mitica/legendaria con animacion propia.
//                      en un lote de 10 runas puede haber varias especiales;
//                      solo se anima la mas rara, las demas van al panel
//
// hecho entre marzo y abril, reescrito 23/04 para batching. !hi

var syncSafetyTimer = null;


// FIX V110 — helpers de runas corruptas y actualización visual por delta.
function _rwNormTxt(v) { return String(v || '').toLowerCase(); }
function _rwParseCantidad(v) {
    if (v === undefined || v === null) return 0;
    var txt = String(v).replace(/<[^>]*>/g, '').replace(/[^0-9.,-]/g, '');
    if (!txt) return 0;
    // En la UI usas formato x1,005. parseInt('1,005') devuelve 1; esto lo corrige.
    txt = txt.replace(/[.,]/g, '');
    var n = parseInt(txt, 10);
    return isNaN(n) ? 0 : n;
}
function _rwCantidadDOM(el) {
    if (!el) return 0;
    var n = _rwParseCantidad(el.dataset ? el.dataset.cantidad : 0);
    var spans = el.querySelectorAll ? el.querySelectorAll('.runa-card-cantidad, .col-runa-cantidad') : [];
    spans.forEach(function (sp) { n = Math.max(n, _rwParseCantidad(sp.textContent)); });
    return n;
}
function _rwCantidadVisualActual(id) {
    id = String(id);
    if (!window.RW_RUNAS_VISUAL) window.RW_RUNAS_VISUAL = {};
    var n = _rwParseCantidad(window.RW_RUNAS_VISUAL[id]);
    ['.runa-card-btn[data-id="' + id + '"]', '.col-runa-btn[data-id="' + id + '"]', '.col-runa-comun[data-id="' + id + '"]'].forEach(function (sel) {
        document.querySelectorAll(sel).forEach(function (el) { n = Math.max(n, _rwCantidadDOM(el)); });
    });
    window.RW_RUNAS_VISUAL[id] = n;
    return n;
}
function _rwPintarCantidadRuna(id, cantidad, rareza) {
    id = String(id);
    cantidad = _rwParseCantidad(cantidad);
    if (!window.RW_RUNAS_VISUAL) window.RW_RUNAS_VISUAL = {};
    window.RW_RUNAS_VISUAL[id] = Math.max(_rwParseCantidad(window.RW_RUNAS_VISUAL[id]), cantidad);
    cantidad = window.RW_RUNAS_VISUAL[id];

    ['.runa-card-btn[data-id="' + id + '"]', '.col-runa-btn[data-id="' + id + '"]', '.col-runa-comun[data-id="' + id + '"]'].forEach(function (sel) {
        document.querySelectorAll(sel).forEach(function (el) {
            el.dataset.cantidad = String(cantidad);
            if (cantidad > 0) {
                el.classList.remove('runa-bloqueada', 'bloqueada');
                el.classList.add('desbloqueada');
                if (rareza) el.classList.add(rareza);
                if (!el.getAttribute('onclick') && (el.classList.contains('col-runa-btn') || el.classList.contains('col-runa-comun'))) {
                    el.setAttribute('onclick', 'seleccionarRunaCol(this)');
                }
            }
            var spans = el.querySelectorAll('.runa-card-cantidad, .col-runa-cantidad');
            if (spans.length) {
                spans.forEach(function (s) { s.textContent = 'x' + Number(cantidad).toLocaleString(); });
            } else if (cantidad > 0) {
                var candado = el.querySelector('.col-candado');
                var right = candado ? candado.closest('.col-runa-right') : el.querySelector('.runa-card-right, .col-runa-right');
                if (right) right.innerHTML = '<span class="col-runa-cantidad">x' + Number(cantidad).toLocaleString() + '</span><span class="runa-card-flecha">▾</span>';
            }
        });
    });
    if (typeof window.RW_actualizarVisibilidadRunasLaterales === 'function') {
        window.RW_actualizarVisibilidadRunasLaterales();
    }
}
function _rwTextoRuna(r) {
    if (!r) return '';
    return [r.nombre, r.imagen, r.slug, r.runa_file, r.runaFile, r.animacion_slug, r.rareza_animacion, r.variante].map(_rwNormTxt).join(' | ');
}
function _rwEsAnimCorrupta(r, key) {
    var txt = _rwTextoRuna(r);
    if (txt.indexOf(key) !== -1) return true;
    if (key === 'mitica_corrupta' && (txt.indexOf('mítica corrupta') !== -1 || txt.indexOf('mitica corrupta') !== -1)) return true;
    if (key === 'legendaria_corrupta' && txt.indexOf('legendaria corrupta') !== -1) return true;
    if (r && r.variante === 'corrupta') {
        if (key === 'mitica_corrupta' && r.rareza === 'mitica') return true;
        if (key === 'legendaria_corrupta' && r.rareza === 'legendaria') return true;
    }
    return false;
}
function _rwAplicarDeltaVisualRunas(runasGanadas) {
    if (!Array.isArray(runasGanadas) || runasGanadas.length === 0) return;
    var map = {};
    runasGanadas.forEach(function (r) {
        if (!r || r.id === undefined || r.id === null) return;
        var id = String(r.id);
        if (!map[id]) map[id] = { id: r.id, rareza: r.rareza, delta: 0 };
        map[id].delta += _rwParseCantidad(r.cantidad || 1) || 1;
    });
    Object.keys(map).forEach(function (id) {
        var d = map[id];
        var actual = _rwCantidadVisualActual(id);
        var nuevo = actual + d.delta;
        _rwPintarCantidadRuna(id, nuevo, d.rareza);
    });
}


// ── 1. tirarRuna

// ── 1. tirarRuna ─────────────────────────────────────────────
// se llama al pulsar el boton. ya NO hace fetch; solo cuenta el click
// en el buffer de runa-sync.js. el feedback visual es instantaneo
// (particulas + descuento de moneda) aunque el server procese despues
function tirarRuna() {
    if (tiradaBloqueada) return;
    if (coins < 1) return;

    resetIdleTimer();
    lanzarParticulasBoton();
    // esto es TODO el cambio gordo. antes aqui habia un fetch de 50 lineas.
    // ahora solo cuento. runa-sync.js manda el lote cuando toca
    window.runaSync.registrarClic(1);
}

// ── 2. procesarRespuestaSync ─────────────────────────────────
// se llama desde el listener de "runas:sync". recibe el data que manda
// el server tras procesar un lote. es basicamente el .then() del fetch
// viejo pero con una diferencia clave: ahora coins y points vienen del
// server como fuente de verdad (antes los ignoraba y confiaba en el JS,
// ahora el server aplica los pasivos y manda el total correcto)
function procesarRespuestaSync(data) {

    // ── coins: fuente de verdad = servidor ────────────────────
    // coins SI se gastan en cada tirada, asi que el server manda el valor
    // correcto despues de descontar. el cliente lo adopta
    if (!data.local_visual && data.coins !== undefined) {
        var serverCoins = parseFloat(data.coins) || 0;
    
        var hayPendienteCoins = false;
        if (
            window.runaSync &&
            typeof window.runaSync.getVisualState === "function"
        ) {
            hayPendienteCoins = !!window.runaSync.getVisualState().hasPendingVisual;
        }
    
        if (!hayPendienteCoins) {
            coins = Math.max(coins, serverCoins);
        }
    }

    // ── points: el MAYOR entre cliente y servidor ─────────────
    // el server calcula points con points_por_seg base (solo runas). pero
    // el cliente usa la version boosteada (runas + mejoras de tienda +
    // boosts activos). como los boosts son client-side, el server no los
    // conoce y manda un valor mas bajo. si pisara el cliente con ese valor
    // los points "caen" de golpe (ej: de 1B a 800M). usando Math.max:
    //   - si el server manda mas (raro pero posible tras reconexion) → sube
    //   - si el server manda menos (lo normal con boosts) → el cliente mantiene
    // points solo bajan al comprar mejoras (comprar_mejora.php valida en
    // server), no en tiradas, asi que el max es seguro aqui
    if (!data.local_visual && data.points !== undefined) {
        var serverPoints = parseFloat(data.points) || 0;
        points = Math.max(points, serverPoints);
    }

    // points_por_seg viene del server como valor FINAL ya calculado
    // (runas + mejoras). no lo metas en _runas_points_ps ni vuelvas a
    // aplicar _mejora_points_add/_mejora_multi_pts, porque duplicaria mejoras.
    if (data.points_por_seg !== undefined && !isNaN(parseFloat(data.points_por_seg))) {
        var serverPpsFinal = parseFloat(data.points_por_seg) || 0;

        points_ps_base = serverPpsFinal;
        points_ps = serverPpsFinal;
    
        if (typeof aplicarBoosts === "function") aplicarBoosts();
    }
    
    // Estoy hasta los huevos de hacer copias de seguridad y probando se me olviden lineas de codigo pensando que eran inutiles
    // Si lees esto eres una persona exitosa
    if (data.points_por_seg === undefined && data.local_visual && Array.isArray(data.runas_ganadas) && data.runas_ganadas.length > 0) {
    var deltaPpsRunas = data.runas_ganadas.reduce(function (s, r) {
        return s + (parseFloat(r.multiplicador) || 0);
    }, 0);

    if (deltaPpsRunas > 0) {
        _runas_points_ps = (parseFloat(_runas_points_ps) || 0) + deltaPpsRunas;

        var nuevoPpsVisual = (_runas_points_ps + _mejora_points_add) * _mejora_multi_pts;
        points_ps_base = Math.max(points_ps_base || 0, nuevoPpsVisual);
        points_ps = Math.max(points_ps || 0, nuevoPpsVisual);

        if (typeof aplicarBoosts === "function") aplicarBoosts();
        }
    }

    // 27/04 v3: bloque de actualizacion de suerte_grupo eliminado.
    // el server ya no manda .suerte ni .suerte_grupo, las probabilidades son fijas.

    actualizarPantalla();

    // 27/04 v3: bloque de bonus_conseguidos eliminado, los bonus de grupo
    // (que daban suerte) fueron retirados con el sistema de suerte.

    // ── Runas ganadas en el lote ──────────────────────────────
    // si clicks_validos = 0 (sin coins o recortado), no hay runas
    if (!data.runas_ganadas || data.runas_ganadas.length === 0) {
        // nada que animar, pero coins/points ya se actualizaron arriba
        if (data.runas) actualizarPanelRunas(data.runas);
        return;
    }

    // si el server no manda data.runas completo, sumo las cantidades por delta en el DOM.
    if (data.runas) {
        actualizarPanelRunas(data.runas);
    } else {
        _rwAplicarDeltaVisualRunas(data.runas_ganadas);
    }

    // separar especiales de normales
    var especiales = data.runas_ganadas.filter(function (r) {
        return r.rareza === "eterna" || r.rareza === "divina" ||
               r.rareza === "mitica" || r.rareza === "legendaria";
    });
    var normales = data.runas_ganadas.filter(function (r) {
        return r.rareza !== "eterna" && r.rareza !== "divina" &&
               r.rareza !== "mitica" && r.rareza !== "legendaria";
    });

    // cartitas normales, sin drama
    normales.forEach(function (r) { mostrarResultado(r); });

    // agrupar especiales por rareza. solo se anima la mas rara;
    // las demas van al panel sin cartita. si te salen 2 eternas y
    // 3 miticas, se anima eterna y las miticas solo suman
    var eternas     = especiales.filter(function (r) { return r.rareza === "eterna"; });
    var divinas     = especiales.filter(function (r) { return r.rareza === "divina"; });
    var miticas     = especiales.filter(function (r) { return r.rareza === "mitica"; });
    var legendarias = especiales.filter(function (r) { return r.rareza === "legendaria"; });

    // safety net: si la animacion especial se queda colgada 25s, desbloqueo
    // el boton por la fuerza. solo se activa si hay especial
    var hayEspecial = (eternas.length + divinas.length + miticas.length + legendarias.length) > 0;
    if (hayEspecial) {
        clearTimeout(syncSafetyTimer);
        syncSafetyTimer = setTimeout(function () {
            tiradaBloqueada = false;
        }, 25000);
    }

    if (eternas.length > 0) {
        var totalMulti = eternas.reduce(function (s, r) { return s + parseFloat(r.multiplicador); }, 0);
        var runaE = {
            nombre: eternas.length > 1 ? "¡" + eternas.length + " Eternas!" : eternas[0].nombre,
            rareza: "eterna",
            multiplicador: totalMulti.toFixed(2)
        };
        if (getAnimActiva("eterna")) {
            lanzarEterna(runaE);
        } else {
            mostrarResultado(runaE);
        }
    } else if (divinas.length > 0) {
        var totalMulti = divinas.reduce(function (s, r) { return s + parseFloat(r.multiplicador); }, 0);
        var runaD = {
            nombre: divinas.length > 1 ? "¡" + divinas.length + " Divinas!" : divinas[0].nombre,
            rareza: "divina",
            multiplicador: totalMulti.toFixed(2)
        };
        if (getAnimActiva("divina")) {
            lanzarDivina(runaD);
        } else {
            mostrarResultado(runaD);
        }
    } else if (miticas.length > 0) {
        var totalMulti = miticas.reduce(function (s, r) { return s + parseFloat(r.multiplicador); }, 0);
        var hayMiticaCorrupta = miticas.some(function(r){ return _rwEsAnimCorrupta(r, "mitica_corrupta"); });
        var animKeyMitica = hayMiticaCorrupta ? "mitica_corrupta" : "mitica";
        var runaMBase = miticas.find(function(r){ return hayMiticaCorrupta ? _rwEsAnimCorrupta(r, "mitica_corrupta") : true; }) || miticas[0];
        var runaM = {
            id:            runaMBase.id,
            nombre:        miticas.length > 1 ? (hayMiticaCorrupta ? "¡" + miticas.length + " Míticas Corruptas!" : "¡" + miticas.length + " Míticas!") : runaMBase.nombre,
            rareza:        "mitica",
            imagen:        runaMBase.imagen,
            runa_file:     runaMBase.runa_file,
            animacion_slug: animKeyMitica,
            rareza_animacion: animKeyMitica,
            variante:      hayMiticaCorrupta ? "corrupta" : "normal",
            multiplicador: totalMulti.toFixed(2),
            cantidad:      miticas.length
        };
        if (typeof getAnimActiva === "function" && getAnimActiva(animKeyMitica)) {
            if (hayMiticaCorrupta && typeof window.RW_lanzarAnimacionCorruptaEspecial === "function") window.RW_lanzarAnimacionCorruptaEspecial(runaM, "mitica_corrupta");
            else lanzarMitica(runaM);
        } else {
            mostrarCardEn("resultado-tirada", runaM);
        }
    } else if (legendarias.length > 0) {
        var totalMulti = legendarias.reduce(function (s, r) { return s + parseFloat(r.multiplicador); }, 0);
        var hayLegendariaCorrupta = legendarias.some(function(r){ return _rwEsAnimCorrupta(r, "legendaria_corrupta"); });
        var animKeyLegendaria = hayLegendariaCorrupta ? "legendaria_corrupta" : "legendaria";
        var runaLBase = legendarias.find(function(r){ return hayLegendariaCorrupta ? _rwEsAnimCorrupta(r, "legendaria_corrupta") : true; }) || legendarias[0];
        var runaL = {
            id:            runaLBase.id,
            nombre:        legendarias.length > 1 ? (hayLegendariaCorrupta ? "¡" + legendarias.length + " Legendarias Corruptas!" : "¡" + legendarias.length + " Legendarias!") : runaLBase.nombre,
            rareza:        "legendaria",
            imagen:        runaLBase.imagen,
            runa_file:     runaLBase.runa_file,
            animacion_slug: animKeyLegendaria,
            rareza_animacion: animKeyLegendaria,
            variante:      hayLegendariaCorrupta ? "corrupta" : "normal",
            multiplicador: totalMulti.toFixed(2),
            cantidad:      legendarias.length
        };
        if (typeof getAnimActiva === "function" && getAnimActiva(animKeyLegendaria)) {
            if (hayLegendariaCorrupta && typeof window.RW_lanzarAnimacionCorruptaEspecial === "function") window.RW_lanzarAnimacionCorruptaEspecial(runaL, "legendaria_corrupta");
            else mostrarResultado(runaL);
        } else {
            mostrarCardEn("resultado-tirada", runaL);
            if (typeof desactivarNeon === "function") setTimeout(desactivarNeon, 100);
        }
    }

    // refrescar cantidades en panel lateral y coleccion
    if (data.runas) actualizarPanelRunas(data.runas);
}

// ── 3. Listeners ─────────────────────────────────────────────
// runas:sync lo dispara runa-sync.js cada vez que el server responde OK
document.addEventListener("runas:sync", function (e) {
    procesarRespuestaSync(e.detail);
});

// runas:recorte se dispara cuando el server recorto clicks
// (sin coins o fuera del rango anti-cheat). informativo, sin drama.
// si quieres avisar al jugador en pantalla, hazlo aqui
document.addEventListener("runas:recorte", function (e) {
    var d = e.detail;
    console.log("[tirada] recorte:", d.enviados, "->", d.validos, "motivo:", d.motivo);
});

// runas:error para errores reales del server (sesion caducada etc)
document.addEventListener("runas:error", function (e) {
    console.warn("[tirada] error del server:", e.detail.error);
});

// ── 4. actualizarPanelRunas ──────────────────────────────────
// sin cambios respecto a la v1: update in-place del sidebar derecho y
// de los botones de coleccion. no reconstruyo html para no perder scroll
function actualizarPanelRunas(runas) {
    if (!runas || runas.length === 0) return;

    // panel derecho (sidebar "mis runas")
    runas.forEach(function (r) {
        var cantidadServer = _rwParseCantidad(r.cantidad);
        var cantidadFinal = Math.max(_rwCantidadVisualActual(r.id), cantidadServer);
        r.cantidad = cantidadFinal;
        _rwPintarCantidadRuna(r.id, cantidadFinal, r.rareza);
        var card = document.querySelector('.runa-card-btn[data-id="' + r.id + '"]');
        if (!card) return;
        if (card) {
            card.dataset.cantidad = r.cantidad;
            var cantEl = card.querySelector(".runa-card-cantidad");
            var right  = card.querySelector(".runa-card-right");

            if (r.cantidad > 0 && card.classList.contains("runa-bloqueada")) {
                card.classList.remove("runa-bloqueada");
                if (right && !cantEl) {
                    right.innerHTML = '<span class="runa-card-cantidad">x' +
                        Number(r.cantidad).toLocaleString() +
                        '</span><span class="runa-card-flecha">▾</span>';
                }
            } else if (cantEl) {
                cantEl.textContent = "x" + Number(r.cantidad).toLocaleString();
            }
        }
    });

    if (typeof window.RW_actualizarVisibilidadRunasLaterales === 'function') {
        window.RW_actualizarVisibilidadRunasLaterales();
    }

    // botones de coleccion — especiales (con canvas neon)
    runas.forEach(function (r) {
        var btnEsp = document.querySelector('.col-runa-btn[data-id="' + r.id + '"]');
        if (btnEsp) {
            var cantAnterior = _rwParseCantidad(btnEsp.dataset.cantidad);
            btnEsp.dataset.cantidad = r.cantidad;

            var cantSpan = btnEsp.querySelector(".col-runa-cantidad");
            if (cantSpan) {
                cantSpan.textContent = "x" + Number(r.cantidad).toLocaleString();
            }

            if (cantAnterior === 0 && r.cantidad > 0) {
                btnEsp.classList.remove("bloqueada");
                btnEsp.classList.add("desbloqueada", r.rareza);
                btnEsp.setAttribute("onclick", "seleccionarRunaCol(this)");

                if (!btnEsp.querySelector(".col-btn-neon")) {
                    var c = document.createElement("canvas");
                    c.className = "col-btn-neon";
                    c.dataset.rareza = r.rareza;
                    btnEsp.insertBefore(c, btnEsp.firstChild);
                }

                var candado = btnEsp.querySelector(".col-candado");
                if (candado) {
                    var right = candado.closest(".col-runa-right");
                    if (right) right.innerHTML = '<span class="col-runa-cantidad">x' + r.cantidad + '</span>';
                }

                setTimeout(iniciarNeonBotones, 60);
            }
        }

        // comunes (con mini canvas)
        var btnCom = document.querySelector('.col-runa-comun[data-id="' + r.id + '"]');
        if (btnCom) {
            var cantAnterior = _rwParseCantidad(btnCom.dataset.cantidad);
            btnCom.dataset.cantidad = r.cantidad;

            var cantSpan = btnCom.querySelector(".col-runa-cantidad");
            if (cantSpan) {
                cantSpan.textContent = "x" + Number(r.cantidad).toLocaleString();
            }

            if (cantAnterior === 0 && r.cantidad > 0) {
                btnCom.classList.remove("bloqueada");
                btnCom.classList.add("desbloqueada", r.rareza);
                btnCom.setAttribute("onclick", "seleccionarRunaCol(this)");

                var miniCanvas = btnCom.querySelector(".col-comun-canvas");
                if (miniCanvas) miniCanvas.dataset.activa = "1";

                var candado = btnCom.querySelector(".col-candado");
                if (candado) {
                    var right = candado.closest(".col-runa-right");
                    if (right) right.innerHTML = '<span class="col-runa-cantidad">x' + r.cantidad + '</span>';
                }

                setTimeout(iniciarMiniCanvas, 60);
            }
        }
    });

    // contador de coleccion "4 / 20"
    var totalEl = document.querySelector(".col-contador-num");
    if (totalEl) {
        var totalSpan = totalEl.querySelector(".col-contador-sep");
        if (totalSpan) {
            var desbloqueadas = document.querySelectorAll(
                ".col-runa-btn.desbloqueada, .col-runa-comun.desbloqueada"
            ).length;
            var numNode = totalEl.firstChild;
            if (numNode && numNode.nodeType === Node.TEXT_NODE) {
                numNode.textContent = desbloqueadas;
            }
        }
    }
}

// ── 5. guardarProgreso ───────────────────────────────────────
// v2 (importante): el server es autoritativo. ya NO mandamos coins/points,
// el server calcula los valores reales (mejoras + runas + elapsed) y nos
// devuelve el resultado. nosotros solo actualizamos el display con lo que
// el server diga. esto cierra el exploit gordo de "mando 9999B y se
// guarda" y tambien sincroniza el rate (coins/seg, points/seg) al BD para
// que tirar_runa.php y comprar_mejora.php usen valores correctos
function guardarProgreso() {
    fetch("PHP/guardar_progreso.php", {
        method: "POST",
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        var msg = document.getElementById("msg-guardado");
        if (msg) {
            msg.textContent = data.ok ? "Partida guardada correctamente." : "Error al guardar.";
            setTimeout(function () { msg.textContent = ""; }, 3000);
        }

        if (data.ok && typeof window !== "undefined") {

            // coins: servidor manda valor correcto
            // coins: no pisar coins visuales mientras haya packs/clicks pendientes
            if (typeof coins !== "undefined" && data.coins !== undefined) {
                var serverCoins = parseFloat(data.coins) || 0;
            
                var hayPendiente = false;
                if (
                    window.runaSync &&
                    typeof window.runaSync.getVisualState === "function"
                ) {
                    hayPendiente = !!window.runaSync.getVisualState().hasPendingVisual;
                }
            
                if (!hayPendiente) {
                    coins = Math.max(coins, serverCoins);
                }
            }

            // points: nunca bajar por respuesta vieja
        if (typeof points !== "undefined" && data.points !== undefined) {
            var serverPoints = parseFloat(data.points) || 0;
        
            if (window.RW_COMPRA_RECIENTE_HASTA && Date.now() < window.RW_COMPRA_RECIENTE_HASTA) {
                // ignorar points de autosaves viejos justo después de comprar
            } else {
                points = Math.max(points, serverPoints);
            }
        }

            // coins_ps: evitar bajadas por desync
            if (typeof coins_ps !== "undefined" && data.coins_por_seg !== undefined) {
                var serverCps = parseFloat(data.coins_por_seg) || 0;
                coins_ps = Math.max(coins_ps, serverCps);
            }

            // points_ps: CLAVE del bug
            if (typeof points_ps !== "undefined" && data.points_por_seg !== undefined) {
                var serverPps = parseFloat(data.points_por_seg) || 0;

                // valor final autoritativo del servidor; no es raw de runas.
                points_ps = serverPps;
                points_ps_base = serverPps;
            }

            actualizarPantalla();
        }
    })
    .catch(function () {
        // red caída, se reintentará en el siguiente ciclo
    });
}

// auto-save cada 60s para cubrir periodos idle
setInterval(guardarProgreso, 60000);


// ideas futuras / TODO:
//   - convertir guardarProgreso a server-side (que el server calcule los
//     pasivos, no confiar en el cliente) para eliminar el riesgo de doble-
//     conteo. requiere tocar guardar_progreso.php
//   - anadir guardar al pasar a background (visibilitychange) para idle
//   - notificacion del navegador al sacar eterna en background
//   - quitar el fallback suerte_grupo vs suerte cuando todo este estable
//   - animacion combinada si salen eterna + mitica en el mismo lote
