<?php
// 24/04 v2: el reset estaba incompleto. dejaba bulk_total a 2 (porque no
// lo tocaba), no reseteaba ultima_actualizacion (asi al siguiente sync
// el server sumaba "pasivos" pensando que habian pasado segundos),
// no borraba jugador_stats ni jugador_bonus, y no invalidaba la sesion
// de batches del runa-sync (asi un batch en vuelo podia aplicarse sobre
// la cuenta recien reseteada).
//
// ahora hace todo en una transaccion para que sea atomico, devuelve
// reset_token para que el cliente sepa que algo fresco paso, y resetea
// el indice de batches procesados de la sesion para evitar replays
// !hi

session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";

$id_usuario = $_SESSION["idUsuario"];

$conexion->begin_transaction();

try {
    // ── id del jugador ────────────────────────────────────────
    $stmt = $conexion->prepare("SELECT id FROM jugadores WHERE usuario_id = ?");
    $stmt->bind_param("i", $id_usuario);
    $stmt->execute();
    $jugador = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$jugador) {
        $conexion->rollback();
        echo json_encode(["ok" => false, "error" => "Jugador no encontrado"]);
        exit;
    }
    $jugador_id = (int)$jugador["id"];

    // ── borrar runas ──────────────────────────────────────────
    $stmt = $conexion->prepare("DELETE FROM jugador_runas WHERE jugador_id = ?");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $stmt->close();

    // ── borrar mejoras compradas ──────────────────────────────
    $stmt = $conexion->prepare("DELETE FROM jugador_mejoras WHERE jugador_id = ?");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $stmt->close();

    // 27/04 v3: bloque de jugador_bonus eliminado, tabla dropeada en Fase 1.

    // ── borrar stats acumulados ──────────────────────────────
    // si no se borran, las condiciones de desbloqueo (tirar 5000 runas,
    // sacar una divina) siguen cumplidas y aparecen mejoras desbloqueadas
    // sin que el jugador se las haya ganado en la nueva partida
    $stmt = $conexion->prepare("DELETE FROM jugador_stats WHERE jugador_id = ?");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $stmt->close();

    // ── resetear progreso del jugador a estado inicial ───────
    // CLAVE: ultima_actualizacion = NOW(). si la dejamos al valor viejo,
    // el siguiente sync del cliente calcula pasivos como si hubieran
    // pasado los segundos desde la ultima accion antes del reset, y
    // "revive" los puntos. todos los _ps tambien a su valor base
    // 27/04 v3: suerte eliminada del UPDATE, columna dropeada en Fase 1
    $stmt = $conexion->prepare("
        UPDATE jugadores SET
            coins                = 10,
            points               = 0,
            coins_por_seg        = 1,
            coins_ps_max         = 1,
            points_por_seg       = 0,
            points_ps_max        = 0,
            bulk_total           = 1,
            ultima_actualizacion = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $stmt->close();

    // ── invalidar batches en vuelo del runa-sync ─────────────
    // si el cliente tenia clicks pendientes de enviar (en buffer o
    // en una request en curso), su batch_id ya no debe aplicarse.
    // limpiamos el indice de "batches procesados" de la sesion: cualquier
    // batch posterior se acepta como nuevo, pero solo despues del reload
    // que hace el cliente
    $_SESSION["batches_procesados"] = [];
    $_SESSION["reset_token"]        = uniqid("rst_", true);

    $conexion->commit();

    echo json_encode([
        "ok"          => true,
        "reset_token" => $_SESSION["reset_token"]
    ]);

} catch (Exception $e) {
    @$conexion->rollback();
    error_log("borrar_progreso: " . $e->getMessage());
    echo json_encode(["ok" => false, "error" => "Error interno"]);
}

$conexion->close();
