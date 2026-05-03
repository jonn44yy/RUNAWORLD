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
                $multi_coins *= pow(max(1.0, $v), $n);
                break;
            case "points_seg":
                // lineal: cada nivel suma `valor` puntos/seg
                $points_add += $v * $n;
                break;
            case "points_seg_multi":
            case "points_seg_multi_eterno":
                $multi_points *= pow(max(1.0, $v), $n);
                break;
        }
    }

    $coins_ps  = (1.0 + $coins_add) * $multi_coins;
    $points_ps = ($runas_pts_base + $points_add) * $multi_points;

    return [$coins_ps, $points_ps];
}

/**
 * Produccion configurable DESACTIVADA.
 *
 * Mantengo estas funciones por compatibilidad con endpoints antiguos, pero ya
 * no aplican coins_ps_config / points_ps_config. Todos los endpoints vuelven a
 * usar la produccion real calculada desde mejoras + runas.
 */
function aplicarConfigProduccionJugador($conexion, $jugador_id, $coins_ps_max, $points_ps_max) {
    return [max(1.0, floatval($coins_ps_max)), max(0.0, floatval($points_ps_max))];
}

function calcularStatsJugadorConConfig($conexion, $jugador_id) {
    list($coins_ps_max, $points_ps_max) = calcularStatsJugador($conexion, $jugador_id);
    return [max(1.0, floatval($coins_ps_max)), max(0.0, floatval($points_ps_max)), max(1.0, floatval($coins_ps_max)), max(0.0, floatval($points_ps_max))];
}
/**
 * Devuelve informacion completa de suerte del jugador.
 * Formula nueva:
 *   suerte_total = suerte_tienda * suerte_colecciones
 *   suerte_tienda = min(1.5, 1 + SUM(mejora_suerte.valor * nivel))
 *   suerte_colecciones = 1.5 ^ colecciones_completadas
 */
function calcularSuerteJugadorDetalle($conexion, $jugador_id) {
    $stmt = $conexion->prepare("\n        SELECT COALESCE(SUM(m.valor * jm.nivel), 0) AS suerte_bonus\n        FROM jugador_mejoras jm\n        INNER JOIN mejoras m ON m.id = jm.mejora_id\n        WHERE jm.jugador_id = ?\n          AND m.activa = 1\n          AND m.tipo IN ('suerte', 'luck', 'luck_add')\n    ");
    $suerte_bonus = 0.0;
    if ($stmt) {
        $stmt->bind_param("i", $jugador_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $suerte_bonus = floatval($row["suerte_bonus"] ?? 0);
    }

    $suerte_tienda = max(1.0, min(1.5, 1.0 + $suerte_bonus));

    $stmt = $conexion->prepare("\n        SELECT COUNT(*) AS completadas\n        FROM (\n            SELECT\n                COALESCE(r.grupo_id, 0) AS grupo_id_norm,\n                COUNT(*) AS total_runas,\n                COUNT(DISTINCT CASE WHEN COALESCE(jr.cantidad, 0) > 0 THEN r.id END) AS poseidas\n            FROM runas r\n            LEFT JOIN jugador_runas jr\n                ON jr.runa_id = r.id AND jr.jugador_id = ?\n            WHERE r.activa = 1\n            GROUP BY COALESCE(r.grupo_id, 0)\n            HAVING total_runas > 0 AND poseidas >= total_runas\n        ) completas\n    ");
    $completadas = 0;
    if ($stmt) {
        $stmt->bind_param("i", $jugador_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $completadas = (int)($row["completadas"] ?? 0);
    }

    $suerte_colecciones = pow(1.5, max(0, $completadas));
    $suerte_total = $suerte_tienda * $suerte_colecciones;

    return [
        "total" => max(1.0, $suerte_total),
        "tienda" => $suerte_tienda,
        "colecciones" => $suerte_colecciones,
        "colecciones_completadas" => $completadas,
        "bonus_por_coleccion" => 1.5,
    ];
}

function calcularMultiplicadorSuerteJugador($conexion, $jugador_id) {
    $detalle = calcularSuerteJugadorDetalle($conexion, $jugador_id);
    return floatval($detalle["total"] ?? 1.0);
}


/**
 * Devuelve true si el jugador ha completado la colección básica normal.
 * Cuenta todas las rarezas de Básicas, incluidas legendaria/mítica/divina/eterna.
 * Excluye grupos/nombres corruptos o caos para que las variantes no bloqueen
 * ni adelanten el logro base.
 */
function coleccionBasicaCompletaJugador($conexion, $jugador_id) {
    $stmt = $conexion->prepare("\n        SELECT\n          COUNT(*) AS total_basicas,\n          COUNT(DISTINCT CASE WHEN COALESCE(jr.cantidad, 0) > 0 THEN r.id END) AS poseidas\n        FROM runas r\n        LEFT JOIN grupos_runas g ON r.grupo_id = g.id\n        LEFT JOIN jugador_runas jr ON r.id = jr.runa_id AND jr.jugador_id = ?\n        WHERE r.activa = 1\n          AND LOWER(COALESCE(g.nombre, 'Runas Básicas')) NOT LIKE '%inter%'\n          AND LOWER(COALESCE(g.nombre, 'Runas Básicas')) NOT LIKE '%avanz%'\n          AND LOWER(COALESCE(g.nombre, 'Runas Básicas')) NOT LIKE '%corrupt%'\n          AND LOWER(COALESCE(g.nombre, 'Runas Básicas')) NOT LIKE '%caos%'\n          AND LOWER(r.nombre) NOT LIKE '%corrupt%'\n          AND LOWER(r.nombre) NOT LIKE '%caos%'\n    ");
    if (!$stmt) return false;
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && (int)$row["total_basicas"] > 0 && (int)$row["poseidas"] >= (int)$row["total_basicas"];
}
