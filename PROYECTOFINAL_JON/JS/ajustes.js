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
function confirmarBorrado() {
    const textoConfirm = document.getElementById("input-confirmacion").value.trim().toLowerCase();
    if (textoConfirm !== "estoy seguro") {
        document.getElementById("msg-confirmacion").textContent = "Escribe exactamente: estoy seguro";
        return;
    }
    fetch("PHP/borrar_progreso.php", { method: "POST" })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            // reseteo las preferencias del navegador tambien. ahora mismo la
            // unica es rw_anim_boton (particulas on/off). si anado mas toggles
            // en el futuro, aqui es donde tengo que limpiarlos todos.
            // idea: mover a una funcion resetearPreferencias() si crece mucho
            localStorage.removeItem("rw_anim_boton");
            location.reload();
        } else {
            document.getElementById("msg-confirmacion").textContent = "Error al borrar.";
        }
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
        if (data.mejoras) {
            let coins_add    = 0, multi_coins  = 1;
            let points_add   = 0, multi_points = 1;
            let suerte_add   = 0;
            let bulk_add     = 0;

            data.mejoras.forEach(mejora => {
                const valor = parseFloat(mejora.valor);
                const nivel = parseInt(mejora.cantidad) || 1;
                switch (mejora.tipo) {
                    case "coins_seg":        coins_add    += valor * nivel;       break;
                    case "coins_seg_multi":  multi_coins  *= (1 + valor * nivel); break;
                    case "points_seg":       points_add   += valor * nivel;       break;
                    case "points_seg_multi": multi_points *= (1 + valor * nivel); break;
                    case "suerte":           suerte_add   += valor * nivel;       break;
                    case "bulk":             bulk_add     += nivel;               break;
                }
            });

            // guardo los multiplicadores como variables globales. asi cuando
            // tiren una runa no tengo que recalcular esto de cero, solo
            // multiplicar por la base que ya tengo
            _mejora_coins_ps     = (1 + coins_add) * multi_coins;
            _mejora_multi_pts    = multi_points;
            _mejora_points_add   = points_add;
            _mejora_suerte_multi = (1 + suerte_add);

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

            // formula suerte (a+b)*c*d: aqui solo actualizo la "b" (mejoras de
            // tienda). la "d" (bonus de grupo, coleccion) NO se toca, y la "c"
            // (boosts activos) la aplica aplicarBoosts() recomponiendo todo.
            // MUY IMPORTANTE no hacer suerte_base_val = algo aqui directo,
            // porque se pisa la formula y el display se pone en x1.00 (bug
            // que arrastre una semana entera, ya sabes)
            suerte_shop_add = suerte_add;

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


// configurar produccion de coins/seg o points/seg. el jugador puede poner
// un valor menor al maximo que le dan sus mejoras si quiere grindear mas
// despacio (o por estetica). 0 = restablecer al maximo.
// anti-trampas: si alguien edita el HTML y manda un valor mayor al maximo,
// se lo pongo en 1 como castigo. disfruta de tu cheateo
function configurarProduccion(tipo) {
    const inputEl = document.getElementById("config-" + tipo + "-ps");
    const msgEl   = document.getElementById("msg-" + tipo + "-ps");
    const maxVal  = tipo === "coins" ? coins_ps_max : points_ps_max;

    let valor = parseFloat(inputEl.value);

    // 0 o nada = que te lo restablezca al maximo
    if (isNaN(valor) || valor === 0) {
        valor = maxVal;
    }

    // si intenta pasarse de listo, se queda en 1 y le aviso
    if (valor > maxVal) {
        valor = 1;
        msgEl.textContent = "Valor superior a tu maximo. Se ha puesto en 1.";
        msgEl.className   = "ajuste-msg error";
    } else {
        msgEl.textContent = "";
        msgEl.className   = "ajuste-msg";
    }

    // minimo 1 si es que tiene algun maximo (para que no se ponga a 0)
    if (valor < 1 && maxVal > 0) valor = 1;

    fetch("PHP/ajustes_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ accion: "produccion_" + tipo, valor: valor })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            if (tipo === "coins") coins_ps  = valor;
            else                  points_ps = valor;
            actualizarPantalla();
            // solo pongo el mensaje de exito si no habia uno de error antes
            if (!msgEl.textContent) {
                msgEl.textContent = "Produccion actualizada a " + formatNum(valor) + "/seg.";
                msgEl.className   = "ajuste-msg";
            }
            inputEl.value = "";
        } else {
            msgEl.textContent = data.error || "Error.";
            msgEl.className   = "ajuste-msg error";
        }
    });
}


// panel desplegable para contactar al admin. abre/cierra animado con CSS,
// las clases .abierto estan en style.css
function toggleContacto() {
    const btn       = document.getElementById("contacto-toggle");
    const contenido = document.getElementById("contacto-contenido");
    btn.classList.toggle("abierto");
    contenido.classList.toggle("abierto");
}

// contador de caracteres en vivo del textarea de contacto (max 500)
document.getElementById("msg-contenido").addEventListener("input", function() {
    document.getElementById("contador-chars").textContent = this.value.length + " / 500";
});

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
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            exitoEl.textContent = "Mensaje enviado correctamente.";
            // limpiar todo el formulario despues de mandarlo
            ["msg-tipo","msg-asunto","msg-contenido","msg-archivo"].forEach(id => {
                document.getElementById(id).value = "";
            });
            document.getElementById("contador-chars").textContent = "0 / 500";
            document.getElementById("file-nombre-label").textContent = "Seleccionar imagen...";
        } else {
            errorEl.textContent = data.error;
        }
    })
    .catch(() => { errorEl.textContent = "Error de conexion."; });
}


// ideas futuras / TODO de este archivo:
//   - pedir contrasena actual antes de cambiar la nueva (seguridad)
//   - boton de "exportar mis datos" (tipo RGPD, por si algun profe lo pide)
//   - boton de "cerrar sesion en todos los dispositivos"
//   - mas toggles de rendimiento en ajustes, cada uno con su localStorage key
//     (desactivar neon, desactivar intro, modo compacto, etc). si son muchos
//     meterlos en una funcion resetearPreferencias() para limpiar todo de golpe
//     al borrar progreso, en vez de ir removiendo clave a clave
//   - previsualizar imagen antes de enviar en contactar admin