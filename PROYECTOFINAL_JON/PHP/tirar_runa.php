<?php
// 27/04 v3: sistema de cascada por rareza, sin suerte.
// reemplaza el sistema de pesos+campana por cascada simple:
// para cada tirada se rolea de la rareza mas rara a la mas comun,
// la primera que acierta gana. comun es siempre el fallback final.
// dentro de la misma rareza todas las runas tienen igual probabilidad.
// !hi al que este leyendo esto

session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]); exit;
}

require_once "conexion.php";
require_once "calcular_stats.php";  // 28/04 v3.1

// ── Config anti-cheat ────────────────────────────────────────
define("COSTE_POR_TIRADA", 1);
define("MAX_CPS_HUMANO",   25);
define("GRACIA_SEGUNDOS",  3);
define("MAX_BATCH_CLICKS", 50000);
define("ELAPSED_CAP",      600);

// ── Payload del cliente ──────────────────────────────────────
$datos       = json_decode(file_get_contents("php://input"), true);
$id_usuario  = $_SESSION["idUsuario"];
$clicks_envs = (int)    ($datos["clicks"]   ?? 0);
$batch_id    = (string) ($datos["batch_id"] ?? "");
$motivo      = (string) ($datos["reason"]   ?? "interval");

if ($clicks_envs <= 0) {
    echo json_encode(["ok" => false, "error" => "sin clicks"]); exit;
}
if ($clicks_envs > MAX_BATCH_CLICKS) {
    echo json_encode(["ok" => false, "error" => "lote demasiado grande"]); exit;
}
if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $batch_id)) {
    echo json_encode(["ok" => false, "error" => "batch_id invalido"]); exit;
}
if (!in_array($motivo, ["interval","burst","unload","critical"], true)) {
    $motivo = "interval";
}

// idempotencia por sesion
if (!isset($_SESSION["batches_procesados"])) $_SESSION["batches_procesados"] = [];
if (in_array($batch_id, $_SESSION["batches_procesados"], true)) {
    echo json_encode(["ok" => true, "duplicado" => true, "clicks_validos" => 0]);
    exit;
}

$conexion->begin_transaction();

try {
    // ── Lock del jugador (sin la columna suerte que ya no existe) ──
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

    $jugador_id     = (int)   $jugador["id"];
    $coins          = floatval($jugador["coins"]);
    $points         = floatval($jugador["points"]);
    $coins_por_seg  = floatval($jugador["coins_por_seg"]);
    $points_por_seg = floatval($jugador["points_por_seg"]);
    $ult_ts         = (int)   ($jugador["ult_ts"] ?? 0);
    $ahora          = time();
    $elapsed        = max(0, $ahora - $ult_ts);
    $elapsed_efec   = min($elapsed, ELAPSED_CAP);

    // ── Ganancia pasiva ───────────────────────────────────────
    $coins_ganados  = $coins_por_seg  * $elapsed_efec;
    $points_ganados = $points_por_seg * $elapsed_efec;
    $coins_reales   = $coins  + $coins_ganados;
    $points_reales  = $points + $points_ganados;

    // ── Recortes anti-cheat ───────────────────────────────────
    $clicks_max_tiempo = (int) (($elapsed_efec + GRACIA_SEGUNDOS) * MAX_CPS_HUMANO);
    $clicks_max_coins  = (int) floor($coins_reales / COSTE_POR_TIRADA);
    $clicks_validos    = min($clicks_envs, $clicks_max_tiempo, $clicks_max_coins);

    if ($clicks_envs > $clicks_max_tiempo) {
        error_log(sprintf(
            "[anti-cheat] user=%d clicks=%d cap_tiempo=%d cap_coins=%d elapsed=%ds reason=%s",
            $id_usuario, $clicks_envs, $clicks_max_tiempo, $clicks_max_coins, $elapsed, $motivo
        ));
    }

    // ── Sin clicks validos: solo guardamos pasivos ────────────
    if ($clicks_validos <= 0) {
        $stmt = $conexion->prepare("
            UPDATE jugadores
            SET coins = ?, points = ?, ultima_actualizacion = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("ddi", $coins_reales, $points_reales, $jugador_id);
        $stmt->execute();
        $stmt->close();

        $conexion->commit();
        $_SESSION["batches_procesados"][] = $batch_id;

        echo json_encode([
            "ok"             => true,
            "clicks_enviados"=> $clicks_envs,
            "clicks_validos" => 0,
            "motivo_corte"   => $clicks_max_coins <= 0 ? "sin_coins" : "fuera_de_tiempo",
            "coins"          => $coins_reales,
            "points"         => $points_reales,
            "points_por_seg" => $points_por_seg
        ]);
        exit;
    }

    // ── Bulk desde BD ─────────────────────────────────────────
    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(m.valor * jm.cantidad), 0) as total_bulk
        FROM jugador_mejoras jm
        INNER JOIN mejoras m ON jm.mejora_id = m.id
        WHERE jm.jugador_id = ? AND m.tipo = 'bulk' AND m.activa = 1
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $bulk_row      = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cantidad_bulk = 1 + (int)($bulk_row["total_bulk"] ?? 0);

    $total_sorteos = $clicks_validos * $cantidad_bulk;

    // ── Cargar rarezas ordenadas: rara a comun ────────────────
    // ordenamos por denominador DESC: la mas rara primero.
    // las que tengan denominador 1 (comun) van como fallback.
    $stmt = $conexion->prepare("
        SELECT slug, denominador FROM rarezas
        WHERE activa = 1
        ORDER BY denominador DESC
    ");
    $stmt->execute();
    $rarezas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rarezas)) {
        $conexion->rollback();
        echo json_encode(["ok" => false, "error" => "No hay rarezas"]); exit;
    }

    // separar la rareza fallback (denominador <= 1) del resto del cascade
    $rarezas_cascade = [];
    $rareza_fallback = null;
    foreach ($rarezas as $r) {
        if ((int)$r["denominador"] <= 1) {
            $rareza_fallback = $r["slug"];
        } else {
            $rarezas_cascade[] = $r;
        }
    }
    if ($rareza_fallback === null) {
        // nadie tiene denom 1 -> usamos la mas comun como fallback
        $rareza_fallback = end($rarezas)["slug"];
        // y la quitamos del cascade para evitar doble tirada
        array_pop($rarezas_cascade);
    }

    // ── Cargar runas activas y agruparlas por rareza ──────────
    $stmt = $conexion->prepare("SELECT id, nombre, rareza, multiplicador FROM runas WHERE activa = 1");
    $stmt->execute();
    $runas_all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($runas_all)) {
        $conexion->rollback();
        echo json_encode(["ok" => false, "error" => "No hay runas"]); exit;
    }

    $runas_por_rareza = [];
    foreach ($runas_all as $r) {
        $runas_por_rareza[$r["rareza"]][] = $r;
    }

    // ── Sorteo en cascada ─────────────────────────────────────
    if (!isset($_SESSION["debug_forzadas"])) $_SESSION["debug_forzadas"] = [];

    $runas_ganadas  = [];
    $contador_runas = [];
    $counters_rar   = ["eterna"=>0, "divina"=>0, "mitica"=>0, "legendaria"=>0];

    for ($i = 0; $i < $total_sorteos; $i++) {
        $runa_elegida = null;
        $rareza_elegida = null;

        // forzadas tienen prioridad (debug)
        if (!empty($_SESSION["debug_forzadas"])) {
            $rareza_forzada = array_shift($_SESSION["debug_forzadas"]);
            if (!empty($runas_por_rareza[$rareza_forzada])) {
                $rareza_elegida = $rareza_forzada;
            }
        }

        // cascada normal: rareza mas rara primero, primera que acierte gana
        if ($rareza_elegida === null) {
            foreach ($rarezas_cascade as $rar) {
                $denom = (int)$rar["denominador"];
                if ($denom <= 0) continue;

                // probabilidad 1/denom de acertar esta rareza
                if (mt_rand(1, $denom) === 1) {
                    $rareza_elegida = $rar["slug"];
                    break;
                }
            }

            // si nada acerto -> fallback (comun)
            if ($rareza_elegida === null) {
                $rareza_elegida = $rareza_fallback;
            }
        }

        // dentro de la rareza, runa al azar (todas misma prob)
        if (!empty($runas_por_rareza[$rareza_elegida])) {
            $cands = $runas_por_rareza[$rareza_elegida];
            $runa_elegida = $cands[array_rand($cands)];
        }

        if ($runa_elegida) {
            $rid = (int) $runa_elegida["id"];
            $contador_runas[$rid] = ($contador_runas[$rid] ?? 0) + 1;
            $runas_ganadas[] = $runa_elegida;
            if (isset($counters_rar[$runa_elegida["rareza"]])) {
                $counters_rar[$runa_elegida["rareza"]]++;
            }
        }
    }

    // ── Guardado agrupado con upsert ──────────────────────────
    if (!empty($contador_runas)) {
        $valores = [];
        $params  = [];
        $types   = "";
        foreach ($contador_runas as $runa_id => $cantidad) {
            $valores[] = "(?, ?, ?)";
            $params[]  = $jugador_id;
            $params[]  = $runa_id;
            $params[]  = $cantidad;
            $types    .= "iii";
        }
        $sql = "INSERT INTO jugador_runas (jugador_id, runa_id, cantidad)
                VALUES " . implode(",", $valores) . "
                ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    // ── Saldos finales ────────────────────────────────────────
    $coste_total  = $clicks_validos * COSTE_POR_TIRADA;
    $coins_final  = $coins_reales - $coste_total;
    $points_final = $points_reales;

    // 28/04 v3.1: usar helper compartido para calcular stats reales
    // (runas + mejoras). antes esto solo miraba runas y dejaba mejoras
    // fuera de la cuenta, lo que causaba inconsistencias entre tirar y comprar
    list($coins_ps_real, $points_por_seg_new) = calcularStatsJugador($conexion, $jugador_id);

    // persistir estado del jugador
    $stmt = $conexion->prepare("
        UPDATE jugadores
        SET coins = ?, points = ?,
            coins_por_seg = ?, points_por_seg = ?,
            ultima_actualizacion = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ddddi", $coins_final, $points_final, $coins_ps_real, $points_por_seg_new, $jugador_id);
    $stmt->execute();
    $stmt->close();

    // stats agregadas
    $total_runas_lote = (int) array_sum($contador_runas);
    $stmt = $conexion->prepare("
        INSERT INTO jugador_stats
            (jugador_id, total_tiradas, total_runas_conseguidas,
             total_eternas, total_divinas, total_miticas, total_legendarias,
             fecha_primera_tirada)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            total_tiradas           = total_tiradas           + VALUES(total_tiradas),
            total_runas_conseguidas = total_runas_conseguidas + VALUES(total_runas_conseguidas),
            total_eternas           = total_eternas           + VALUES(total_eternas),
            total_divinas           = total_divinas           + VALUES(total_divinas),
            total_miticas           = total_miticas           + VALUES(total_miticas),
            total_legendarias       = total_legendarias       + VALUES(total_legendarias)
    ");
    $stmt->bind_param("iiiiiii",
        $jugador_id, $clicks_validos, $total_runas_lote,
        $counters_rar["eterna"], $counters_rar["divina"],
        $counters_rar["mitica"], $counters_rar["legendaria"]
    );
    $stmt->execute();
    $stmt->close();

    // refrescar runas del jugador para el panel
    $stmt = $conexion->prepare("
        SELECT r.id, r.nombre, r.rareza, jr.cantidad, g.nombre as grupo_nombre
        FROM jugador_runas jr
        INNER JOIN runas r ON jr.runa_id = r.id
        LEFT JOIN grupos_runas g ON r.grupo_id = g.id
        WHERE jr.jugador_id = ?
        ORDER BY g.id ASC, FIELD(r.rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $mis_runas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $conexion->commit();

    $_SESSION["batches_procesados"][] = $batch_id;
    if (count($_SESSION["batches_procesados"]) > 30) {
        $_SESSION["batches_procesados"] = array_slice($_SESSION["batches_procesados"], -30);
    }

    echo json_encode([
        "ok"                => true,
        "clicks_enviados"   => $clicks_envs,
        "clicks_validos"    => $clicks_validos,
        "runas_ganadas"     => $runas_ganadas,
        "coins"             => $coins_final,
        "points"            => $points_final,
        "coins_por_seg"     => $coins_por_seg,
        "points_por_seg"    => $points_por_seg_new,
        "runas"             => $mis_runas,
        "motivo"            => $motivo
    ]);

} catch (Exception $e) {
    @$conexion->rollback();
    error_log("tirar_runa: " . $e->getMessage());
    echo json_encode(["ok" => false, "error" => "Error interno"]);
}

$conexion->close();
