<?php
// guardar_progreso.php v3 — runaworld
//
// 28/04 v3.1: refactor para usar calcular_stats.php (helper compartido)
// en lugar de duplicar la formula. ANTES tenia su propia copia con la
// formula geometrica vieja (pow(10, n-1)) que causaba la corrupcion
// de points_por_seg en BD: cada autosave de 30s recalculaba con la
// formula explosiva y escribia billones en la columna.
//
// CAMBIO CLAVE respecto a v1: ya NO se aceptan coins/points del cliente.
// la version anterior cogia los valores que mandaba JS y los guardaba a
// pelo en la BD. eso permitia un exploit gordo: el cliente acumula
// localmente coins/points segun su display (que SI usa formulas correctas
// con mejoras), llega a billones, y guardar_progreso le decia al server
// "guarda esto" sin validar. resultado: 6.5B puntos para alguien que
// realmente solo deberia tener miles. con autoclicker, se aceleraba.
//
// la nueva version es autoritativa: el server lee el estado actual,
// calcula los pasivos REALES (coins/seg + points/seg con mejoras y runas
// aplicadas via calcular_stats.php), aplica el tiempo transcurrido desde
// la ultima accion, y persiste el resultado. nada de lo que mande el
// cliente se usa.
//
// !hi

session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";
require_once "calcular_stats.php";  // 28/04 v3.1: helper compartido

$id_usuario = $_SESSION["idUsuario"];

$conexion->begin_transaction();

try {
    // ── lock + estado actual del jugador ─────────────────────
    $stmt = $conexion->prepare("
        SELECT id, coins, points, coins_ps_max, points_ps_max,
               UNIX_TIMESTAMP(ultima_actualizacion) AS ult_ts
        FROM jugadores WHERE usuario_id = ? FOR UPDATE
    ");
    $stmt->bind_param("i", $id_usuario);
    $stmt->execute();
    $j = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$j) {
        $conexion->rollback();
        echo json_encode(["ok" => false, "error" => "Jugador no encontrado"]);
        exit;
    }

    $jugador_id     = (int)$j["id"];
    $coins_bd       = floatval($j["coins"]);
    $points_bd      = floatval($j["points"]);
    $coins_ps_max   = floatval($j["coins_ps_max"]  ?? 1);
    $points_ps_max  = floatval($j["points_ps_max"] ?? 0);
    $ult_ts         = (int)$j["ult_ts"];

    // ── recalcular tasas pasivas reales ──────────────────────
    // 28/04 v3.1: usa calcular_stats.php, que aplica las formulas
    // correctas (lineales) y combina runas + mejoras. fuente unica
    // de verdad para coins_ps y points_ps en TODO el server.
    list($coins_ps_real, $points_ps_real) = calcularStatsJugador($conexion, $jugador_id);

    // ── aplicar pasivos del periodo idle ─────────────────────
    // capamos elapsed a 1 hora para evitar que el jugador se pase 3 dias
    // ausente y vuelva con cantidades absurdas. si el jugador estaba
    // generando 500M/s y vuelve tras 24h, capado a 1h ya gana 1.8 trillon
    // (mas que suficiente). si quieres premiar idle largo, sube el cap
    $ahora   = time();
    $elapsed = max(0, min($ahora - $ult_ts, 3600));

    $coins_final  = $coins_bd  + ($coins_ps_real  * $elapsed);
    $points_final = $points_bd + ($points_ps_real * $elapsed);

    // tracking de maximo historico (lo lee juego.php para mostrar
    // "tu maximo: X/seg" en ajustes)
    if ($coins_ps_real  > $coins_ps_max)  $coins_ps_max  = $coins_ps_real;
    if ($points_ps_real > $points_ps_max) $points_ps_max = $points_ps_real;

    // ── persistir TODO (incluido el rate) ─────────────────────
    $stmt = $conexion->prepare("
        UPDATE jugadores SET
            coins                = ?,
            points               = ?,
            coins_por_seg        = ?,
            points_por_seg       = ?,
            coins_ps_max         = ?,
            points_ps_max        = ?,
            ultima_actualizacion = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ddddddi",
        $coins_final, $points_final,
        $coins_ps_real, $points_ps_real,
        $coins_ps_max, $points_ps_max,
        $jugador_id);
    $stmt->execute();
    $stmt->close();

    $conexion->commit();

    // devuelvo el estado real para que el cliente lo refleje. asi se
    // arregla auto-magicamente cualquier display inflado del cliente
    echo json_encode([
        "ok"             => true,
        "coins"          => $coins_final,
        "points"         => $points_final,
        "coins_por_seg"  => $coins_ps_real,
        "points_por_seg" => $points_ps_real
    ]);

} catch (Exception $e) {
    @$conexion->rollback();
    error_log("guardar_progreso: " . $e->getMessage());
    echo json_encode(["ok" => false, "error" => "Error interno"]);
}
