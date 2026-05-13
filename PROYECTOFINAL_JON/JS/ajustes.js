// ajustes.js — runaworld
// todo lo que pasa en la pantalla de ajustes + la tienda.
// maneja: comprar mejoras, cambiar cuenta (username/email/pass), configurar
// produccion de coins y points, contactar con el admin por mensajes, y la
// ventana emergente para borrar el progreso.
//
// indice por orden de aparicion:
//   1. ventana emergente para borrar el progreso
//   2. confirmar el borrado (con reset de preferencias locales)
//   3. mensaje flotante de la tienda
//   4. comprar mejora + recalcular stats con el fix de suerte (a+b)*c*d
//   5. cambiar nombre de usuario, email, contrasena
//   6. configurar la produccion de coins/seg y points/seg
//   7. contactar al admin (desplegable + validacion + envio)
//
// lenguaje interno para los poco entendidos (va por vosotros profesores):
//   ventana emergente = lo que otros llaman modal
//   msg-tienda        = el mensajito flotante que sale cuando compras algo
//   ajustes_action.php = endpoint de PHP para cambios de cuenta (todo en uno)
//   rw_anim_boton     = clave en localStorage donde guardo si el jugador ha
//                       desactivado las particulas del boton (ajustes > rendimiento)
//
// empeze ajustes.js por el 8 de marzo, el dia que me di cuenta que no podia
// tener 15 botones sueltos sin organizar. !hi al que lea esto


// ventana emergente para borrar el progreso. pide confirmacion porque
// no quiero que nadie se cargue sus 200 horas de juego por accidente
function abrirModal() {
    document.getElementById("input-confirmacion").value = "";
    document.getElementById("msg-confirmacion").textContent = "";
    document.getElementById("modal-borrar").classList.add("visible");
}

function cerrarModal() {
    document.getElementById("modal-borrar").classList.remove("visible");
}

// confirmar el borrado: valida que el jugador escribio "estoy seguro" y
// si todo ok, borra el progreso en el server + resetea preferencias locales
function RW_borrarDatosLocalesRuneWorld() {
    try {
        localStorage.clear();
    } catch (e) {
        console.warn("[RW reset] No se pudo limpiar localStorage:", e);
    }

    try {
        sessionStorage.clear();
    } catch (e) {
        console.warn("[RW reset] No se pudo limpiar sessionStorage:", e);
    }
}

function confirmarBorrado() {
    const inputConfirmacion = document.getElementById("input-confirmacion");
    const msgConfirmacion = document.getElementById("msg-confirmacion");

    if (!inputConfirmacion || !msgConfirmacion) return;

    const textoConfirm = inputConfirmacion.value.trim().toLowerCase();

    if (textoConfirm !== "estoy seguro") {
        msgConfirmacion.textContent = "Escribe exactamente: estoy seguro";
        return;
    }

    if (window.runaSync && typeof window.runaSync.reset === "function") {
        window.runaSync.reset();
    }

    fetch("PHP/borrar_progreso.php", {
        method: "POST",
        credentials: "same-origin"
    })
    .then(function (r) {
        return r.json();
    })
    .then(function (data) {
        if (data && data.ok) {
            RW_borrarDatosLocalesRuneWorld();
            window.location.reload();
        } else {
            msgConfirmacion.textContent = (data && data.error) ? data.error : "Error al borrar.";
        }
    })
    .catch(function (err) {
        console.warn("[borrar] fallo red:", err);
        msgConfirmacion.textContent = "Error de conexión al borrar.";
    });
}


// mensajito flotante en la tienda. lo uso para "no tienes puntos", "comprado",
// etc. aparece 3s y se va solo. clearTimeout evita que se apilen si haces
// varios clicks rapido (antes se me quedaban pegados para siempre)
let msgTiendaTimer = null;

function mostrarMsgTienda(texto) {
    const el = document.getElementById("msg-tienda");
    el.textContent = texto;
    el.classList.add("visible");
    clearTimeout(msgTiendaTimer);
    msgTiendaTimer = setTimeout(() => el.classList.remove("visible"), 3000);
}

// comprar una mejora. la mas compleja del archivo porque tiene que recalcular
// TODOS los stats del jugador despues, no solo decir "ok comprado".
// el server me devuelve la lista entera de mejoras del jugador con sus niveles,
// y aqui recorro y calculo lo que suma cada tipo: coins_seg, points_seg, etc
function comprarMejora(mejora_id) {
    fetch("PHP/comprar_mejora.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            mejora_id,
            points: points || 0,
            coins: coins || 0,
            coins_ps: coins_ps || 1,
            points_ps: points_ps || 0
        })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            mostrarMsgTienda(data.error || "No tienes puntos suficientes");
            return;
        }

        // el server ya me descuenta los points y me devuelve los nuevos valores
        points = parseFloat(data.points);
        coins  = parseFloat(data.coins);

        // recalcular stats desde la lista de mejoras que me envio el server.
        // esto es importante: no puedo simplemente sumar el valor de la mejora
        // que compre, porque las mejoras tienen multiplicadores que afectan a
        // todo, asi que toca recorrer la lista entera cada vez
        // 27/04 v3: suerte_add eliminado, las mejoras de suerte ya no existen
        if (data.mejoras) {
            let coins_add    = 0, multi_coins  = 1;
            let points_add   = 0, multi_points = 1;
            let bulk_add     = 0;

            data.mejoras.forEach(mejora => {
                const valor = parseFloat(mejora.valor);
                const nivel = parseInt(mejora.cantidad) || 1;
                switch (mejora.tipo) {
                    case "coins_seg":        coins_add    += valor * nivel;       break;
                    case "coins_seg_multi":  multi_coins  *= (1 + valor * nivel); break;
                    case "points_seg":       points_add   += valor * nivel;       break;
                    case "points_seg_multi": multi_points *= (1 + valor * nivel); break;
                    case "bulk":             bulk_add     += nivel;               break;
                }
            });

            // guardo los multiplicadores como variables globales. asi cuando
            // tiren una runa no tengo que recalcular esto de cero, solo
            // multiplicar por la base que ya tengo
            _mejora_coins_ps     = (1 + coins_add) * multi_coins;
            _mejora_multi_pts    = multi_points;
            _mejora_points_add   = points_add;

            coins_ps_base = _mejora_coins_ps;
            coins_ps      = _mejora_coins_ps;

            // points/seg sale de: runas que tiene el jugador (_runas_points_ps)
            // + los points_seg de las mejoras, todo multiplicado por multi_points
            const nuevoPps = (_runas_points_ps + points_add) * multi_points;
            points_ps_base = nuevoPps;
            points_ps      = nuevoPps;

            // actualizar bulk (masa). 1 base + lo que aporten las mejoras
            bulk_runas = 1 + bulk_add;
            const bulkEl = document.getElementById("bulk-display");
            if (bulkEl) bulkEl.textContent = bulk_runas + " runa" + (bulk_runas > 1 ? "s" : "");

            if (typeof aplicarBoosts === "function") aplicarBoosts();
        }
        actualizarPantalla();

        // actualizar la tarjeta visual de la mejora: nivel, coste, boton
        const nivelEl  = document.getElementById("mejora-nivel-" + mejora_id);
        const costeEl  = document.getElementById("mejora-coste-" + mejora_id);
        const cardEl   = document.getElementById("mejora-fila-"  + mejora_id);
        const maxNivel = data.nivel_maximo;

        if (data.coste_siguiente === null) {
            // llego al nivel maximo, bloqueo la tarjeta
            nivelEl.textContent = "Nivel " + data.nivel + " / " + maxNivel;
            costeEl.className   = "mejora-card-coste max";
            costeEl.textContent = "NIVEL MAX";
            cardEl.querySelector("button").textContent = "Completado";
            cardEl.querySelector("button").disabled    = true;
        } else {
            nivelEl.textContent = "Nivel " + data.nivel + " / " + maxNivel;
            costeEl.innerHTML   = '<span class="coste-num">' + formatNum(data.coste_siguiente) + '</span> pts';
        }
    })
    .catch(() => mostrarMsgTienda("Error de conexion."));
}


// cambiar nombre de usuario. solo valida que no este vacio, el server se
// encarga de comprobar que no lo tenga ya otro usuario
function cambiarUsername() {
    const valor  = document.getElementById("nuevo-username").value.trim();
    const msgEl = document.getElementById("msg-username");
    if (!valor) {
        msgEl.textContent = "Escribe un nombre.";
        msgEl.className   = "ajuste-msg error";
        return;
    }

    fetch("PHP/ajustes_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ accion: "username", valor: valor })
    })
    .then(r => r.json())
    .then(data => {
        msgEl.textContent = data.ok ? "Nombre actualizado." : (data.error || "Error.");
        msgEl.className   = "ajuste-msg" + (data.ok ? "" : " error");
        if (data.ok) document.getElementById("nuevo-username").value = "";
    });
}

// cambiar email. el endpoint de PHP valida formato email, aqui solo no vacio
function cambiarEmail() {
    const valor  = document.getElementById("nuevo-email").value.trim();
    const msgEl = document.getElementById("msg-email");
    if (!valor) {
        msgEl.textContent = "Escribe un email.";
        msgEl.className   = "ajuste-msg error";
        return;
    }

    fetch("PHP/ajustes_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ accion: "email", valor: valor })
    })
    .then(r => r.json())
    .then(data => {
        msgEl.textContent = data.ok ? "Email actualizado." : (data.error || "Error.");
        msgEl.className   = "ajuste-msg" + (data.ok ? "" : " error");
        if (data.ok) document.getElementById("nuevo-email").value = "";
    });
}

// cambiar contrasena. minimo 6 caracteres porque con menos me parecia un chiste
// TODO: quizas pedir la contrasena actual antes de cambiarla, mas seguro
function cambiarPassword() {
    const valor  = document.getElementById("nueva-password").value;
    const msgEl = document.getElementById("msg-password");
    if (!valor || valor.length < 6) {
        msgEl.textContent = "Minimo 6 caracteres.";
        msgEl.className   = "ajuste-msg error";
        return;
    }

    fetch("PHP/ajustes_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ accion: "password", valor: valor })
    })
    .then(r => r.json())
    .then(data => {
        msgEl.textContent = data.ok ? "Contrasena actualizada." : (data.error || "Error.");
        msgEl.className   = "ajuste-msg" + (data.ok ? "" : " error");
        if (data.ok) document.getElementById("nueva-password").value = "";
    });
}


// configurar produccion de coins/seg o points/seg.
// DESACTIVADO temporalmente: estaba causando desincronizaciones con el sistema
// de packs. Se mantiene la funcion para que el HTML no rompa si queda algun
// boton viejo en cache, pero no envia nada al servidor.
function configurarProduccion(tipo) {
    const inputEl = document.getElementById("config-" + tipo + "-ps");
    const msgEl   = document.getElementById("msg-" + tipo + "-ps");
    if (msgEl) {
        msgEl.textContent = "Configuracion manual de produccion desactivada temporalmente.";
        msgEl.className   = "ajuste-msg error";
    }
    if (inputEl) inputEl.value = "";
}
// panel desplegable para contactar al admin. abre/cierra animado con CSS,
// las clases .abierto estan en style.css
function toggleContacto() {
    const btn = document.getElementById("contacto-toggle");
    const contenido = document.getElementById("contacto-contenido");
    if (!btn || !contenido) return;

    btn.classList.toggle("abierto");
    contenido.classList.toggle("abierto");
}

// contador de caracteres en vivo del textarea de contacto (max 500)
const rwMsgContenidoEl = document.getElementById("msg-contenido");
if (rwMsgContenidoEl) {
    rwMsgContenidoEl.addEventListener("input", function() {
        const contador = document.getElementById("contador-chars");
        if (contador) contador.textContent = this.value.length + " / 500";
    });
}

// cuando el jugador elige un archivo, enseño el nombre del archivo en el label
// (por defecto el input file nativo se ve feo, asi queda custom)
function actualizarNombreArchivo(input) {
    const label = document.getElementById("file-nombre-label");
    label.textContent = input.files[0] ? input.files[0].name : "Seleccionar imagen...";
}

// enviar el mensaje al admin. valida todo aqui antes de mandarlo al server
// para ahorrar peticiones por cosas obvias (vacio, tipo no elegido, etc).
// uso FormData en vez de json porque hay archivo adjunto
function enviarMensaje() {
    const tipo      = document.getElementById("msg-tipo").value;
    const asunto    = document.getElementById("msg-asunto").value.trim();
    const contenido = document.getElementById("msg-contenido").value.trim();
    const archivo   = document.getElementById("msg-archivo").files[0];
    const errorEl   = document.getElementById("msg-error");
    const exitoEl   = document.getElementById("msg-exito");

    errorEl.textContent = "";
    exitoEl.textContent = "";

    // validaciones en cascada. salgo al primer error
    if (!tipo)                  { errorEl.textContent = "Selecciona un tipo."; return; }
    if (!asunto)                { errorEl.textContent = "El asunto no puede estar vacio."; return; }
    if (!contenido)             { errorEl.textContent = "El mensaje no puede estar vacio."; return; }
    if (contenido.length > 500) { errorEl.textContent = "Max 500 caracteres."; return; }
    if (archivo) {
        if (!["image/jpeg","image/png","image/webp"].includes(archivo.type)) {
            errorEl.textContent = "Solo JPG, PNG o WEBP.";
            return;
        }
        if (archivo.size > 5*1024*1024) {
            errorEl.textContent = "Max 5MB.";
            return;
        }
    }

    const formData = new FormData();
    formData.append("tipo",      tipo);
    formData.append("asunto",    asunto);
    formData.append("contenido", contenido);
    if (archivo) formData.append("archivo", archivo);

fetch("PHP/enviar_mensaje.php", { method: "POST", body: formData })
.then(async r => {
    const text = await r.text();

    try {
        return JSON.parse(text);
    } catch (e) {
        console.error("Respuesta NO es JSON:", text);
        throw new Error("Respuesta inválida del servidor");
    }
})
.then(data => {
    if (data.ok) {
        exitoEl.textContent = "Mensaje enviado correctamente.";
        
        ["msg-tipo","msg-asunto","msg-contenido","msg-archivo"].forEach(id => {
            document.getElementById(id).value = "";
        });

        document.getElementById("contador-chars").textContent = "0 / 500";
        document.getElementById("file-nombre-label").textContent = "Seleccionar imagen...";
    } else {
        errorEl.textContent = data.error;
    }
})
.catch(err => {
    console.error(err);
    errorEl.textContent = "Error real del servidor (mira consola)";
});
}



// ─────────────────────────────────────────────────────────────
// Eliminar cuenta: modal + animación final tipo agujero negro
// ─────────────────────────────────────────────────────────────
(function () {
    "use strict";

    const CONFIRMACION_ELIMINAR = "eliminar mi cuenta";

    function qs(sel) {
        return document.querySelector(sel);
    }

    function crearEstilosEliminarCuenta() {
        if (document.getElementById("rw-delete-account-style")) return;

        const style = document.createElement("style");
        style.id = "rw-delete-account-style";
        style.textContent = `
            .rw-delete-account-zone {
                width: 100%;
                max-width: 720px;
                margin: 28px auto 0;
                padding: 26px 0 0;
                border-top: 1px solid rgba(255, 40, 80, 0.28);
                text-align: center;
            }

            .rw-delete-account-zone .ajuste-label {
                color: #ffd700;
                text-align: center;
                letter-spacing: 0.28em;
                margin-bottom: 10px;
            }

            .rw-delete-account-note {
                max-width: 620px;
                margin: 8px auto 18px;
                color: rgba(220, 224, 245, 0.68);
                font-size: 12px;
                line-height: 1.6;
                letter-spacing: 0.06em;
                text-align: center;
            }

            .rw-delete-account-btn {
                width: min(420px, 100%);
                min-height: 56px;
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
                margin: 0 auto;
                padding: 0 28px !important;

                border: 1px solid rgba(255, 40, 80, 0.88) !important;
                color: #ff315c !important;
                background: rgba(255, 40, 80, 0.035) !important;

                font-family: 'Oswald', sans-serif;
                font-size: 0.9rem;
                letter-spacing: 0.26em;
                text-transform: uppercase;

                box-shadow:
                    0 0 22px rgba(255, 40, 80, 0.16),
                    inset 0 0 18px rgba(255, 40, 80, 0.035);
            }

            .rw-delete-account-btn:hover {
                background: rgba(255, 40, 80, 0.10) !important;
                box-shadow:
                    0 0 30px rgba(255, 40, 80, 0.30),
                    inset 0 0 22px rgba(255, 40, 80, 0.055);
            }

            #modal-eliminar-cuenta {
                position: fixed;
                inset: 0;
                display: none;
                align-items: center;
                justify-content: center;
                background:
                    radial-gradient(circle at center, rgba(255, 40, 80, 0.12), rgba(0, 0, 0, 0.88) 58%),
                    rgba(0,0,0,0.82);
                z-index: 99980;
                padding: 24px;
            }

            #modal-eliminar-cuenta.visible {
                display: flex;
            }

            .rw-delete-modal-box {
                width: min(620px, 92vw);
                min-height: 420px;

                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;

                background: linear-gradient(180deg, rgba(15, 16, 26, 0.98), rgba(7, 7, 13, 0.98));
                border: 1px solid rgba(255, 40, 80, 0.72);
                border-radius: 10px;

                box-shadow:
                    0 0 42px rgba(255, 40, 80, 0.22),
                    inset 0 0 32px rgba(255, 255, 255, 0.025);

                padding: 42px 44px;
                color: white;
                text-align: center;
            }

            .rw-delete-modal-box h3 {
                margin: 0 0 24px;
                color: #ff315c;

                font-family: 'Oswald', sans-serif;
                font-size: clamp(1.45rem, 3vw, 2rem);
                font-weight: 700;
                letter-spacing: 0.28em;
                text-transform: uppercase;
                text-align: center;
            }

            .rw-delete-modal-box p {
                max-width: 500px;
                color: rgba(225, 228, 245, 0.74);
                line-height: 1.65;
                margin: 8px auto;
                text-align: center;
            }

            .rw-delete-modal-box strong {
                color: #fff;
                font-weight: 700;
            }

            #input-eliminar-cuenta {
                width: min(440px, 100%);
                margin: 22px auto 10px;
                padding: 15px 16px;

                background: rgba(0,0,0,0.62);
                border: 1px solid rgba(255, 40, 80, 0.72);
                color: #fff;
                outline: none;

                font-family: 'Oswald', sans-serif;
                font-size: 0.9rem;
                letter-spacing: 0.12em;
                text-align: center;
            }

            #input-eliminar-cuenta:focus {
                border-color: rgba(255, 40, 80, 1);
                box-shadow: 0 0 18px rgba(255, 40, 80, 0.22);
            }

            #msg-eliminar-cuenta {
                min-height: 20px;
                margin: 4px 0 0;
                color: #ff6685;
                font-size: 12px;
                letter-spacing: 0.08em;
                text-align: center;
            }

            .rw-delete-modal-actions {
                width: min(440px, 100%);
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 14px;
                margin-top: 28px;
            }

            .rw-delete-modal-actions button {
                min-height: 50px;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                justify-content: center;

                font-family: 'Oswald', sans-serif;
                font-size: 0.78rem;
                letter-spacing: 0.22em;
                text-transform: uppercase;
            }

            .rw-delete-modal-actions .btn-cancelar {
                border: 1px solid rgba(255, 215, 0, 0.34);
                color: rgba(220, 224, 245, 0.72);
                background: rgba(255, 255, 255, 0.025);
            }

            .rw-delete-modal-actions .btn-confirmar-borrar {
                border: 1px solid rgba(255, 40, 80, 0.88);
                color: #ff315c;
                background: rgba(255, 40, 80, 0.045);
                box-shadow: 0 0 18px rgba(255, 40, 80, 0.14);
            }

            @media (max-width: 768px) {
                .rw-delete-account-zone {
                    max-width: calc(100vw - 28px);
                    margin-top: 24px;
                    padding-top: 22px;
                }

                .rw-delete-account-btn {
                    width: min(100%, 360px);
                    min-height: 54px;
                    font-size: 0.78rem;
                    letter-spacing: 0.22em;
                }

                #modal-eliminar-cuenta {
                    padding: 18px;
                }

                .rw-delete-modal-box {
                    width: min(520px, 94vw);
                    min-height: 390px;
                    padding: 34px 22px;
                    border-radius: 8px;
                }

                .rw-delete-modal-box h3 {
                    font-size: 1.35rem;
                    letter-spacing: 0.24em;
                    margin-bottom: 20px;
                }

                .rw-delete-modal-box p {
                    font-size: 0.9rem;
                    line-height: 1.55;
                }

                #input-eliminar-cuenta {
                    width: 100%;
                    padding: 14px 12px;
                    font-size: 0.82rem;
                }

                .rw-delete-modal-actions {
                    width: 100%;
                    grid-template-columns: 1fr;
                    gap: 12px;
                    margin-top: 24px;
                }

                .rw-delete-modal-actions button {
                    width: 100%;
                    min-height: 48px;
                }
            }
        `;
        document.head.appendChild(style);
    }

    function crearBotonEliminarCuenta() {
        const seccion = document.getElementById("seccion-ajustes");
        if (!seccion || document.getElementById("rw-delete-account-zone")) return;

        const zone = document.createElement("div");
        zone.id = "rw-delete-account-zone";
        zone.className = "rw-delete-account-zone";
        zone.innerHTML = `
            <div class="ajuste-label">Eliminar cuenta</div>
            <p class="rw-delete-account-note">
                Elimina tu usuario y todo su progreso de forma permanente. Esta acción no se puede deshacer.
            </p>
            <button type="button" class="ajuste-btn danger rw-delete-account-btn" onclick="abrirModalEliminarCuenta()">
                ✕ Eliminar cuenta
            </button>
        `;

        const borrarBtn = seccion.querySelector("button[onclick='abrirModal()']");
        if (borrarBtn && borrarBtn.parentNode === seccion) {
            seccion.insertBefore(zone, borrarBtn.nextSibling);
        } else {
            seccion.appendChild(zone);
        }
    }

    function crearModalEliminarCuenta() {
        if (document.getElementById("modal-eliminar-cuenta")) return;

        const modal = document.createElement("div");
        modal.id = "modal-eliminar-cuenta";
        modal.innerHTML = `
            <div class="rw-delete-modal-box">
                <h3>Eliminar cuenta</h3>
                <p>
                    Esta acción eliminará tu cuenta, tu jugador, tus runas, tus mejoras y tus estadísticas.
                    No podrás recuperar estos datos.
                </p>
                <p>
                    Escribe <strong>${CONFIRMACION_ELIMINAR}</strong> para confirmar:
                </p>
                <input type="text" id="input-eliminar-cuenta" placeholder="${CONFIRMACION_ELIMINAR}" autocomplete="off">
                <p id="msg-eliminar-cuenta"></p>
                <div class="rw-delete-modal-actions">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalEliminarCuenta()">Cancelar</button>
                    <button type="button" class="btn-confirmar-borrar" onclick="confirmarEliminarCuenta()">Eliminar cuenta</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    window.abrirModalEliminarCuenta = function () {
        const modal = document.getElementById("modal-eliminar-cuenta");
        const input = document.getElementById("input-eliminar-cuenta");
        const msg = document.getElementById("msg-eliminar-cuenta");

        if (input) input.value = "";
        if (msg) msg.textContent = "";
        if (modal) modal.classList.add("visible");
    };

    window.cerrarModalEliminarCuenta = function () {
        const modal = document.getElementById("modal-eliminar-cuenta");
        if (modal) modal.classList.remove("visible");
    };

    window.confirmarEliminarCuenta = function () {
        const input = document.getElementById("input-eliminar-cuenta");
        const msg = document.getElementById("msg-eliminar-cuenta");

        if (!input || !msg) return;

        const texto = input.value.trim().toLowerCase();

        if (texto !== CONFIRMACION_ELIMINAR) {
            msg.textContent = "Escribe exactamente: " + CONFIRMACION_ELIMINAR;
            return;
        }

        msg.textContent = "Eliminando cuenta...";

        if (window.runaSync && typeof window.runaSync.reset === "function") {
            window.runaSync.reset();
        }

        fetch("PHP/eliminar_cuenta.php", {
            method: "POST",
            credentials: "same-origin"
        })
        .then(function (r) {
            return r.json();
        })
        .then(function (data) {
            if (!data || !data.ok) {
                msg.textContent = (data && data.error) ? data.error : "Error al eliminar la cuenta.";
                return;
            }

            RW_borrarDatosLocalesRuneWorld();
            cerrarModalEliminarCuenta();

            if (typeof window.RW_iniciarAnimacionEliminarCuenta === "function") {
                window.RW_iniciarAnimacionEliminarCuenta({
                    next: "../index.php",
                    warpUrl: "ANIMACIONES_HTML/borrado_cuenta_animacion.html",
                    phaseDuration: 5600
                });
            } else {
                window.location.href = "ANIMACIONES_HTML/borrado_cuenta_animacion.html?next=" + encodeURIComponent("../index.php");
            }
        })
        .catch(function (err) {
            console.warn("[eliminar cuenta] fallo:", err);
            msg.textContent = "Error de conexión al eliminar la cuenta.";
        });
    };

    document.addEventListener("DOMContentLoaded", function () {
        crearEstilosEliminarCuenta();
        crearBotonEliminarCuenta();
        crearModalEliminarCuenta();
    });

    if (document.readyState !== "loading") {
        crearEstilosEliminarCuenta();
        crearBotonEliminarCuenta();
        crearModalEliminarCuenta();
    }
})();
