<?php
// 23/04 v2: validacion server-side de las condiciones de desbloqueo.
// nuevas columnas en `mejoras`: condicion_tipo, condicion_valor.
// condiciones soportadas:
//   ninguna             -> visible siempre
//   coleccion_basica    -> el jugador tiene >=1 de cada runa NO especial
//                          (no eterna/divina/mitica/legendaria)
//   tirar_runa_x        -> total_tiradas >= condicion_valor
//   tirar_rareza        -> total_<rareza>s >= 1 (eternas/divinas/miticas/legendarias en jugador_stats)
//   comprar_mejora_id   -> el jugador tiene nivel >= 1 en la mejora con ese id
//   clickar_boost_x     -> boosts_clickados >= condicion_valor (28/04 v3.1)
//
// si se cumplen condiciones nuevas tras esta compra, las anuncio en el JSON
// con `nuevas_desbloqueadas: [id, id, ...]` y el cliente las muestra con badge "NUEVA"
// !hi

session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]); exit;
}

require_once "conexion.php";
require_once "calcular_stats.php";  // 28/04 v3.1: helper compartido

$datos      = json_decode(file_get_contents("php://input"), true);
$mejora_id  = (int)($datos["mejora_id"] ?? 0);
$id_usuario = $_SESSION["idUsuario"];

if ($mejora_id <= 0) {
    echo json_encode(["ok" => false, "error" => "Mejora inválida"]); exit;
}


// ── Helper: comprueba si una mejora esta desbloqueada para el jugador ──
// devuelve true si la condicion se cumple. para "ninguna" siempre true
function estaDesbloqueada($conexion, $jugador_id, $mejora) {
    $tipo  = $mejora["condicion_tipo"]  ?? "ninguna";
    $valor = $mejora["condicion_valor"] ?? null;
    if ($tipo === "ninguna" || $tipo === null || $tipo === "") return true;

    switch ($tipo) {
        case "coleccion_basica":
            // tener >=1 de cada runa NO especial. especiales = eterna/divina/mitica/legendaria
            $sql = "
                SELECT
                    (SELECT COUNT(*) FROM runas WHERE activa = 1
                     AND rareza NOT IN ('eterna','divina','mitica','legendaria')) AS total_basicas,
                    (SELECT COUNT(DISTINCT jr.runa_id) FROM jugador_runas jr
                     INNER JOIN runas r ON r.id = jr.runa_id
                     WHERE jr.jugador_id = ? AND jr.cantidad > 0
                       AND r.rareza NOT IN ('eterna','divina','mitica','legendaria')
                       AND r.activa = 1) AS poseidas
            ";
            $stmt = $conexion->prepare($sql);
            $stmt->bind_param("i", $jugador_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $r && (int)$r["total_basicas"] > 0
                && (int)$r["poseidas"] >= (int)$r["total_basicas"];

        case "tirar_runa_x":
            // total de tiradas >= valor. sale de jugador_stats.total_tiradas
            $minimo = (int)$valor;
            $stmt = $conexion->prepare("
                SELECT total_tiradas FROM jugador_stats WHERE jugador_id = ?
            ");
            $stmt->bind_param("i", $jugador_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $r && (int)$r["total_tiradas"] >= $minimo;

        case "tirar_rareza":
            // ha sacado al menos 1 runa de la rareza indicada. para "divina" es total_divinas etc
            $col = "total_" . preg_replace('/[^a-z]/', '', strtolower($valor)) . "s";
            // sanitize: solo permitimos columnas existentes
            $cols_validas = ["total_eternas","total_divinas","total_miticas","total_legendarias"];
            if (!in_array($col, $cols_validas, true)) return false;
            $sql = "SELECT $col AS c FROM jugador_stats WHERE jugador_id = ?";
            $stmt = $conexion->prepare($sql);
            $stmt->bind_param("i", $jugador_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $r && (int)$r["c"] >= 1;

        case "comprar_mejora_id":
            $req_id = (int)$valor;
            $stmt = $conexion->prepare("
                SELECT nivel FROM jugador_mejoras
                WHERE jugador_id = ? AND mejora_id = ?
            ");
            $stmt->bind_param("ii", $jugador_id, $req_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $r && (int)$r["nivel"] >= 1;

        // 28/04 v3.1: nueva condicion clickar_boost_x
        // se desbloquea cuando el jugador ha clickado >= condicion_valor boosts.
        // el contador vive en jugador_stats.boosts_clickados, lo incrementa
        // PHP/click_boost.php cada vez que el jugador clica un boost flotante
        case "clickar_boost_x":
            $minimo = (int)$valor;
            $stmt = $conexion->prepare("
                SELECT boosts_clickados FROM jugador_stats WHERE jugador_id = ?
            ");
            $stmt->bind_param("i", $jugador_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $r && (int)$r["boosts_clickados"] >= $minimo;

        default:
            return false;
    }
}


$conexion->begin_transaction();

try {
    // ── Jugador con FOR UPDATE (evita doble compra por hold) ────
    $stmt = $conexion->prepare("
        SELECT id, coins, points, coins_por_seg, points_por_seg,
               UNIX_TIMESTAMP(ultima_actualizacion) AS ult_ts
        FROM jugadores WHERE usuario_id = ? FOR UPDATE
    ");
    $stmt->bind_param("i", $id_usuario);
    $stmt->execute();
    $jugador = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$jugador) {
        $conexion->rollback();
        echo json_encode(["ok" => false, "error" => "Jugador no encontrado"]); exit;
    }

    $jugador_id     = (int)$jugador["id"];
    $coins_bd       = floatval($jugador["coins"]);
    $points_bd      = floatval($jugador["points"]);
    $coins_por_seg  = floatval($jugador["coins_por_seg"]);
    $points_por_seg = floatval($jugador["points_por_seg"]);
    $ult_ts         = (int)($jugador["ult_ts"] ?? 0);

    $ahora        = time();
    $elapsed      = max(0, min($ahora - $ult_ts, 600));
    $coins_reales = $coins_bd  + ($coins_por_seg  * $elapsed);
    $points_reales= $points_bd + ($points_por_seg * $elapsed);

    // ── Mejora ──────────────────────────────────────────────────
    $stmt = $conexion->prepare("
        SELECT id, nombre, tipo, valor, coste_base, coste_escala, nivel_maximo,
               condicion_tipo, condicion_valor
        FROM mejoras WHERE id = ? AND activa = 1
    ");
    $stmt->bind_param("i", $mejora_id);
    $stmt->execute();
    $mejora = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$mejora) {
        $conexion->rollback();
        echo json_encode(["ok" => false, "error" => "Mejora no encontrada"]); exit;
    }

    // ── Validar desbloqueo ────────────────────────────────────
    if (!estaDesbloqueada($conexion, $jugador_id, $mejora)) {
        $conexion->rollback();
        echo json_encode([
            "ok"    => false,
            "error" => "Esta mejora aún no está desbloqueada"
        ]); exit;
    }

    // ── Nivel actual ───────────────────────────────────────────
    $stmt = $conexion->prepare("
        SELECT id, nivel, cantidad FROM jugador_mejoras
        WHERE jugador_id = ? AND mejora_id = ?
    ");
    $stmt->bind_param("ii", $jugador_id, $mejora_id);
    $stmt->execute();
    $jm = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $nivel_actual    = (int)($jm["nivel"]    ?? 0);
    $cantidad_actual = (int)($jm["cantidad"] ?? 0);
    $nivel_maximo    = (int)$mejora["nivel_maximo"];

    if ($nivel_actual >= $nivel_maximo) {
        $conexion->rollback();
        echo json_encode(["ok" => false, "error" => "Nivel máximo alcanzado"]); exit;
    }

    $coste = floatval($mejora["coste_base"]) * pow(floatval($mejora["coste_escala"]), $nivel_actual);
    if ($points_reales < $coste) {
        $conexion->rollback();
        echo json_encode([
            "ok"     => false,
            "error"  => "No tienes suficientes points",
            "points" => $points_reales,
            "coste"  => $coste
        ]); exit;
    }

    // ── Subir nivel ────────────────────────────────────────────
    $nivel_nuevo    = $nivel_actual + 1;
    $cantidad_nueva = $cantidad_actual + 1;

    if ($jm) {
        $stmt = $conexion->prepare("
            UPDATE jugador_mejoras SET nivel = ?, cantidad = ?
            WHERE jugador_id = ? AND mejora_id = ?
        ");
        $stmt->bind_param("iiii", $nivel_nuevo, $cantidad_nueva, $jugador_id, $mejora_id);
    } else {
        $stmt = $conexion->prepare("
            INSERT INTO jugador_mejoras (jugador_id, mejora_id, nivel, cantidad)
            VALUES (?, ?, 1, 1)
        ");
        $stmt->bind_param("ii", $jugador_id, $mejora_id);
    }
    $stmt->execute();
    $stmt->close();

    // ── Persistir coins/points + stats correctos + reset timestamp ─
    // 28/04 v3.1: ahora tambien persistimos coins_por_seg y points_por_seg
    // recalculados desde runas + mejoras. antes no se actualizaban aqui y
    // la BD se llenaba de valores corruptos (5T pts/seg) por la formula
    // geometrica vieja que los demas endpoints heredaban
    $points_final = $points_reales - $coste;
    $coins_final  = $coins_reales;

    list($coins_ps_real, $points_ps_real) = calcularStatsJugador($conexion, $jugador_id);

    $stmt = $conexion->prepare("
        UPDATE jugadores
        SET coins = ?, points = ?,
            coins_por_seg = ?, points_por_seg = ?,
            ultima_actualizacion = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ddddi", $coins_final, $points_final, $coins_ps_real, $points_ps_real, $jugador_id);
    $stmt->execute();
    $stmt->close();

    $coste_siguiente = $nivel_nuevo < $nivel_maximo
        ? floatval($mejora["coste_base"]) * pow(floatval($mejora["coste_escala"]), $nivel_nuevo)
        : null;

    // ── Comprobar si esta compra desbloqueo otras mejoras ─────
    // (caso tipico: comprar legendario con condicion comprar_mejora_id=14
    //  desbloquea el divino con condicion_valor=14)
    $stmt = $conexion->prepare("
        SELECT m.id, m.condicion_tipo, m.condicion_valor
        FROM mejoras m
        WHERE m.activa = 1 AND m.condicion_tipo != 'ninguna'
          AND m.id NOT IN (
              SELECT mejora_id FROM jugador_mejoras WHERE jugador_id = ? AND nivel >= 1
          )
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $candidatas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $nuevas_desbloqueadas = [];
    foreach ($candidatas as $c) {
        if (estaDesbloqueada($conexion, $jugador_id, $c)) {
            $nuevas_desbloqueadas[] = (int)$c["id"];
        }
    }

    // ── Mejoras del jugador (para que ui.js recalcule stats) ──
    $stmt = $conexion->prepare("
        SELECT m.id, m.tipo, m.valor, jm.nivel, jm.cantidad
        FROM jugador_mejoras jm
        INNER JOIN mejoras m ON m.id = jm.mejora_id
        WHERE jm.jugador_id = ? AND m.activa = 1
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $todas_mejoras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $conexion->commit();

    echo json_encode([
        "ok"                   => true,
        "mejora_id"            => $mejora_id,
        "tipo"                 => $mejora["tipo"],
        "valor"                => floatval($mejora["valor"]),
        "nivel_actual"         => $nivel_nuevo,
        "nivel_maximo"         => $nivel_maximo,
        "coste_siguiente"      => $coste_siguiente,
        "mejoras_jugador"      => $todas_mejoras,
        "points"               => $points_final,
        "coins"                => $coins_final,
        "nuevas_desbloqueadas" => $nuevas_desbloqueadas
    ]);

} catch (Exception $e) {
    @$conexion->rollback();
    error_log("comprar_mejora: " . $e->getMessage());
    echo json_encode(["ok" => false, "error" => "Error interno"]);
}

$conexion->close();
