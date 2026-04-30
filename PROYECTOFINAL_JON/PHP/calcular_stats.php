<?php
// ============================================================
// calcular_stats.php — funciones compartidas para recalcular
// los stats del jugador (points_por_seg y coins_por_seg) desde
// la verdad: runas + mejoras.
//
// se incluye desde:
//   - comprar_mejora.php  (al comprar)
//   - tirar_runa.php      (al tirar)
//   - guardar_progreso.php  (al sync)
//   - cualquier endpoint que persista jugadores.points_por_seg
//
// formulas (espejo de juego.php / ui.js):
//   coins_seg                    -> triangular: (n*(n+1)/2) * v
//   coins_seg_multi[_eterno]     -> *= 2^n
//   points_seg                   -> lineal: v * n  (28/04 v3.1)
//   points_seg_multi[_eterno]    -> *= 2^n
//
// 28/04 v3.1: creado para arreglar el bug del points_por_seg
// corrupto en BD. comprar_mejora.php no escribia este campo, y
// tirar_runa.php lo escribia mirando solo runas (ignorando mejoras).
// el resultado: el numero acumulaba errores y explotaba a billones.
// ============================================================

/**
 * Devuelve [coins_por_seg, points_por_seg] reales del jugador
 * basandose en sus runas y mejoras.
 *
 * @param mysqli $conexion   Conexion activa
 * @param int    $jugador_id ID del jugador en tabla jugadores
 * @return array [coins_por_seg, points_por_seg]
 */
function calcularStatsJugador($conexion, $jugador_id) {
    // base: runas dan points/seg (multiplicador * cantidad)
    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(r.multiplicador * jr.cantidad), 0) AS total
        FROM jugador_runas jr
        INNER JOIN runas r ON jr.runa_id = r.id
        WHERE jr.jugador_id = ?
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $runas_pts_base = floatval($stmt->get_result()->fetch_assoc()["total"] ?? 0);
    $stmt->close();

    // mejoras del jugador
    $stmt = $conexion->prepare("
        SELECT m.tipo, m.valor, jm.nivel
        FROM jugador_mejoras jm
        INNER JOIN mejoras m ON m.id = jm.mejora_id
        WHERE jm.jugador_id = ? AND m.activa = 1
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $mejoras_jug = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $coins_add    = 0.0;
    $multi_coins  = 1.0;
    $points_add   = 0.0;
    $multi_points = 1.0;

    foreach ($mejoras_jug as $m) {
        $v = floatval($m["valor"]);
        $n = (int)$m["nivel"];
        if ($n <= 0) continue;
        switch ($m["tipo"]) {
            case "coins_seg":
                $coins_add += ($n * ($n + 1) / 2) * $v;
                break;
            case "coins_seg_multi":
            case "coins_seg_multi_eterno":
                $multi_coins *= pow(2, $n);
                break;
            case "points_seg":
                // lineal: cada nivel suma `valor` puntos/seg
                $points_add += $v * $n;
                break;
            case "points_seg_multi":
            case "points_seg_multi_eterno":
                $multi_points *= pow(2, $n);
                break;
        }
    }

    $coins_ps  = (1.0 + $coins_add) * $multi_coins;
    $points_ps = ($runas_pts_base + $points_add) * $multi_points;

    return [$coins_ps, $points_ps];
}
