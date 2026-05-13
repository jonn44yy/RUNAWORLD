<?php
// tirar_runa.php — RuneWorld
// Sistema de tiradas por lotes + suerte + variantes corruptas.
// IMPORTANTE: este archivo debe contener SOLO PHP.
// El código JS de runa-sync.js debe estar únicamente en /JS/runa-sync.js.

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";
require_once "calcular_stats.php";

// ── Config anti-cheat ────────────────────────────────────────
define("COSTE_POR_TIRADA", 1);
define("MAX_CPS_HUMANO",   25);
define("GRACIA_SEGUNDOS",  3);
define("MAX_BATCH_CLICKS", 50000);
define("ELAPSED_CAP",      600);

// ── Config suerte ────────────────────────────────────────────
define("LUCK_MULTIPLIER_BASE", 1.0);
define("LUCK_MULTIPLIER_MAX",  100000.0);
define("LUCK_HARD_CUT",        true);

// 1% de posibilidad de convertir una runa normal en su variante corrupta
// cuando la colección básica esté completa.
define("CORRUPT_VARIANT_CHANCE", 0.01);

function responderJson(array $data): void {
    echo json_encode($data);
    exit;
}

function rngFloat01(): float {
    return mt_rand() / mt_getrandmax();
}

function clampFloat(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
}

function calcularMultiplicadorSuerte(mysqli $conexion, int $jugador_id): float {
    if (function_exists('calcularMultiplicadorSuerteJugador')) {
        return clampFloat(
            (float)calcularMultiplicadorSuerteJugador($conexion, $jugador_id),
            1.0,
            (float)LUCK_MULTIPLIER_MAX
        );
    }

    $luck = (float)LUCK_MULTIPLIER_BASE;

    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(m.valor * jm.nivel), 0) AS suerte_bonus
        FROM jugador_mejoras jm
        INNER JOIN mejoras m ON jm.mejora_id = m.id
        WHERE jm.jugador_id = ?
          AND m.activa = 1
          AND m.tipo IN ('suerte', 'luck', 'luck_add')
    ");

    if ($stmt) {
        $stmt->bind_param("i", $jugador_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $luck += (float)($row["suerte_bonus"] ?? 0);
    }

    return clampFloat($luck, 1.0, (float)LUCK_MULTIPLIER_MAX);
}

function aciertaRarezaConSuerte(int $denom, float $luck): bool {
    if ($denom <= 1) {
        return true;
    }

    $probabilidad = $luck / $denom;

    if (LUCK_HARD_CUT && $probabilidad >= 1.0) {
        return true;
    }

    return rngFloat01() < min(1.0, $probabilidad);
}

function animacionCorruptaKeyPorRareza(string $rareza): string {
    if ($rareza === "eterna") return "eterna_corrupta";
    if ($rareza === "divina") return "divina_corrupta";
    if ($rareza === "mitica") return "mitica_corrupta";
    if ($rareza === "legendaria") return "legendaria_corrupta";

    return "";
}

function aplicarVarianteCorruptaSiToca(array $runa, bool $basicas_completas, array $corruptas_por_rareza): array {
    if (!$basicas_completas) {
        return $runa;
    }

    if (rngFloat01() >= CORRUPT_VARIANT_CHANCE) {
        return $runa;
    }

    $rareza = (string)($runa["rareza"] ?? "");

    if (isset($corruptas_por_rareza[$rareza])) {
        $corrupta = $corruptas_por_rareza[$rareza];

        $corrupta["variante"] = "corrupta";
        $corrupta["variante_label"] = "Corrupta";
        $corrupta["base_runa_id"] = (int)($runa["id"] ?? 0);

        $anim_key = animacionCorruptaKeyPorRareza($rareza);

        if ($anim_key !== "") {
            $corrupta["animacion_slug"] = $anim_key;
            $corrupta["rareza_animacion"] = $anim_key;
        }

        return $corrupta;
    }

    // Fallback por si no existe una runa corrupta real en BD.
    $runa["variante"] = "corrupta";
    $runa["variante_label"] = "Corrupta";
    $runa["multiplicador"] = ((float)($runa["multiplicador"] ?? 1)) * 100;
    $runa["nombre"] = ($runa["nombre"] ?? "Runa") . " Corrupta";

    $anim_key = animacionCorruptaKeyPorRareza($rareza);

    if ($anim_key !== "") {
        $runa["animacion_slug"] = $anim_key;
        $runa["rareza_animacion"] = $anim_key;
    }

    return $runa;
}

// ── Payload del cliente ──────────────────────────────────────
$rawBody = file_get_contents("php://input");
$datos = json_decode($rawBody, true);

if (!is_array($datos)) {
    $datos = [];
}

$id_usuario  = (int)$_SESSION["idUsuario"];
$clicks_envs = (int)($datos["clicks"] ?? 0);
$batch_id    = (string)($datos["batch_id"] ?? "");
$motivo      = (string)($datos["reason"] ?? "interval");

if ($clicks_envs <= 0) {
    responderJson(["ok" => false, "error" => "sin clicks"]);
}

if ($clicks_envs > MAX_BATCH_CLICKS) {
    responderJson(["ok" => false, "error" => "lote demasiado grande"]);
}

if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $batch_id)) {
    responderJson(["ok" => false, "error" => "batch_id invalido"]);
}

if (!in_array($motivo, ["interval", "burst", "unload", "critical"], true)) {
    $motivo = "interval";
}

// Idempotencia por sesión.
if (!isset($_SESSION["batches_procesados"])) {
    $_SESSION["batches_procesados"] = [];
}

if (in_array($batch_id, $_SESSION["batches_procesados"], true)) {
    $resp_dup = [
        "ok" => true,
        "duplicado" => true,
        "clicks_validos" => 0
    ];

    if (function_exists('debugEconomiaActivo') && debugEconomiaActivo($datos)) {
        $resp_dup["debug_economia"] = [
            "endpoint" => "tirar_runa.php",
            "batch_id" => $batch_id,
            "batch_ya_procesado" => true,
            "clicks_recibidos" => $clicks_envs,
        ];
    }

    responderJson($resp_dup);
}

$conexion->begin_transaction();

try {
    // ── Lock del jugador ───────────────────────────────────────
    $stmt = $conexion->prepare("
        SELECT id, coins, points, coins_por_seg, points_por_seg,
               UNIX_TIMESTAMP(ultima_actualizacion) AS ult_ts
        FROM jugadores
        WHERE usuario_id = ?
        FOR UPDATE
    ");
    $stmt->bind_param("i", $id_usuario);
    $stmt->execute();
    $jugador = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$jugador) {
        $conexion->rollback();
        responderJson(["ok" => false, "error" => "Jugador no encontrado"]);
    }

    $jugador_id      = (int)$jugador["id"];
    $luck_multiplier = calcularMultiplicadorSuerte($conexion, $jugador_id);

    $luck_detalle = function_exists('calcularSuerteJugadorDetalle')
        ? calcularSuerteJugadorDetalle($conexion, $jugador_id)
        : [
            "total" => $luck_multiplier,
            "tienda" => $luck_multiplier,
            "colecciones" => 1,
            "colecciones_completadas" => 0,
            "bonus_por_coleccion" => 1.5,
            "bulk_bonus_colecciones" => 0
        ];

    $basicas_completas = function_exists('coleccionBasicaCompletaJugador')
        ? coleccionBasicaCompletaJugador($conexion, $jugador_id)
        : false;

    $coins          = (float)$jugador["coins"];
    $points         = (float)$jugador["points"];
    $coins_por_seg  = (float)$jugador["coins_por_seg"];
    $points_por_seg = (float)$jugador["points_por_seg"];
    $ult_ts         = (int)($jugador["ult_ts"] ?? 0);

    $ahora        = time();
    $elapsed      = max(0, $ahora - $ult_ts);
    $elapsed_efec = min($elapsed, ELAPSED_CAP);

    // ── Ganancia pasiva ───────────────────────────────────────
    $coins_ganados  = $coins_por_seg * $elapsed_efec;
    $points_ganados = $points_por_seg * $elapsed_efec;

    $coins_reales  = $coins + $coins_ganados;
    $points_reales = $points + $points_ganados;

    // ── Recortes anti-cheat ───────────────────────────────────
    $clicks_max_tiempo = (int)(($elapsed_efec + GRACIA_SEGUNDOS) * MAX_CPS_HUMANO);
    $clicks_max_coins  = (int)floor($coins_reales / COSTE_POR_TIRADA);
    $clicks_validos    = min($clicks_envs, $clicks_max_tiempo, $clicks_max_coins);

    if ($clicks_envs > $clicks_max_tiempo) {
        error_log(sprintf(
            "[anti-cheat] user=%d clicks=%d cap_tiempo=%d cap_coins=%d elapsed=%ds reason=%s",
            $id_usuario,
            $clicks_envs,
            $clicks_max_tiempo,
            $clicks_max_coins,
            $elapsed,
            $motivo
        ));
    }

    // ── Sin clicks válidos: solo guardamos pasivos ────────────
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

        responderJson([
            "ok" => true,
            "clicks_enviados" => $clicks_envs,
            "clicks_validos" => 0,
            "motivo_corte" => $clicks_max_coins <= 0 ? "sin_coins" : "fuera_de_tiempo",
            "coins" => $coins_reales,
            "points" => $points_reales,
            "coins_por_seg" => $coins_por_seg,
            "points_por_seg" => $points_por_seg,
            "luck_multiplier" => $luck_multiplier,
            "luck_shop_multiplier" => ($luck_detalle["tienda"] ?? $luck_multiplier),
            "luck_collection_multiplier" => ($luck_detalle["colecciones"] ?? 1),
            "completed_collections" => ($luck_detalle["colecciones_completadas"] ?? 0),
            "collection_states" => ($luck_detalle["colecciones_estado"] ?? []),
            "collection_bulk_bonus" => ($luck_detalle["bulk_bonus_colecciones"] ?? 0)
        ]);
    }

    // ── Bulk real ──────────────────────────────────────────────
    $stmt = $conexion->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN m.tipo IN ('bulk','bulk_normal') THEN m.valor * jm.nivel
                WHEN m.tipo = 'bulk_extra' AND jm.nivel >= 1 THEN m.valor
                ELSE 0
            END
        ), 0) AS bulk_add
        FROM jugador_mejoras jm
        INNER JOIN mejoras m ON m.id = jm.mejora_id
        WHERE jm.jugador_id = ?
          AND m.activa = 1
          AND m.tipo IN ('bulk','bulk_normal','bulk_extra')
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $bulk_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cantidad_bulk = max(1, 1 + (int)($bulk_row["bulk_add"] ?? 0));
    $cantidad_bulk += (int)($luck_detalle["bulk_bonus_colecciones"] ?? 0);

    $total_sorteos = $clicks_validos * $cantidad_bulk;

    // ── Cargar rarezas ordenadas ──────────────────────────────
    $stmt = $conexion->prepare("
        SELECT slug, denominador
        FROM rarezas
        WHERE activa = 1
        ORDER BY denominador DESC
    ");
    $stmt->execute();
    $rarezas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($rarezas)) {
        $conexion->rollback();
        responderJson(["ok" => false, "error" => "No hay rarezas"]);
    }

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
        $ultima = end($rarezas);
        $rareza_fallback = $ultima["slug"];
        array_pop($rarezas_cascade);
    }

    // ── Cargar runas normales activas ─────────────────────────
    $stmt = $conexion->prepare("
        SELECT id, nombre, rareza, multiplicador
        FROM runas
        WHERE activa = 1
          AND COALESCE(tier, 0) < 100
          AND LOWER(COALESCE(nombre, '')) NOT LIKE '%corrupt%'
          AND LOWER(COALESCE(nombre, '')) NOT LIKE '%caos%'
    ");
    $stmt->execute();
    $runas_all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ── Cargar runas corruptas activas ────────────────────────
    $stmt = $conexion->prepare("
        SELECT id, nombre, rareza, multiplicador
        FROM runas
        WHERE activa = 1
          AND (
                COALESCE(tier, 0) >= 100
                OR LOWER(COALESCE(nombre, '')) LIKE '%corrupt%'
                OR LOWER(COALESCE(imagen, '')) LIKE '%corrupt%'
              )
          AND LOWER(COALESCE(nombre, '')) NOT LIKE '%caos%'
        ORDER BY COALESCE(tier, 999), id
    ");
    $stmt->execute();
    $runas_corruptas_all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $corruptas_por_rareza = [];

    foreach ($runas_corruptas_all as $cr) {
        $corruptas_por_rareza[$cr["rareza"]] = $cr;
    }

    // ── Debug local opcional ──────────────────────────────────
    $debug_runa_forzada = null;
    $debug_nombre = trim((string)($datos["debug_runa"] ?? ""));
    $debug_local = in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true);

    if ($debug_local && $debug_nombre !== "") {
        foreach (array_merge($runas_all, $runas_corruptas_all) as $dbg) {
            if ((string)($dbg["id"] ?? "") === $debug_nombre || strcasecmp((string)($dbg["nombre"] ?? ""), $debug_nombre) === 0) {
                $debug_runa_forzada = $dbg;

                if (stripos((string)$debug_runa_forzada["nombre"], "corrupt") !== false) {
                    $debug_runa_forzada["variante"] = "corrupta";
                    $debug_runa_forzada["variante_label"] = "Corrupta";

                    $anim_key = animacionCorruptaKeyPorRareza((string)$debug_runa_forzada["rareza"]);

                    if ($anim_key !== "") {
                        $debug_runa_forzada["animacion_slug"] = $anim_key;
                        $debug_runa_forzada["rareza_animacion"] = $anim_key;
                    }
                }

                break;
            }
        }
    }

    if (empty($runas_all)) {
        $conexion->rollback();
        responderJson(["ok" => false, "error" => "No hay runas"]);
    }

    $runas_por_rareza = [];

    foreach ($runas_all as $r) {
        $runas_por_rareza[$r["rareza"]][] = $r;
    }

    // ── Sorteo en cascada ─────────────────────────────────────
    if (!isset($_SESSION["debug_forzadas"])) {
        $_SESSION["debug_forzadas"] = [];
    }

    $runas_ganadas  = [];
    $contador_runas = [];
    $counters_rar   = [
        "eterna" => 0,
        "divina" => 0,
        "mitica" => 0,
        "legendaria" => 0
    ];

    for ($i = 0; $i < $total_sorteos; $i++) {
        $runa_elegida = null;
        $rareza_elegida = null;

        if (!empty($_SESSION["debug_forzadas"])) {
            $rareza_forzada = array_shift($_SESSION["debug_forzadas"]);

            if (!empty($runas_por_rareza[$rareza_forzada])) {
                $rareza_elegida = $rareza_forzada;
            }
        }

        if ($rareza_elegida === null) {
            foreach ($rarezas_cascade as $rar) {
                $denom = (int)$rar["denominador"];

                if ($denom <= 0) {
                    continue;
                }

                if (aciertaRarezaConSuerte($denom, $luck_multiplier)) {
                    $rareza_elegida = $rar["slug"];
                    break;
                }
            }

            if ($rareza_elegida === null) {
                $rareza_elegida = $rareza_fallback;
            }
        }

        if (!empty($runas_por_rareza[$rareza_elegida])) {
            $cands = $runas_por_rareza[$rareza_elegida];
            $runa_elegida = $cands[array_rand($cands)];
        }

        if ($runa_elegida || $debug_runa_forzada) {
            $runa_final = $debug_runa_forzada ?: aplicarVarianteCorruptaSiToca(
                $runa_elegida,
                $basicas_completas,
                $corruptas_por_rareza
            );

            $rid = (int)$runa_final["id"];

            $contador_runas[$rid] = ($contador_runas[$rid] ?? 0) + 1;
            $runas_ganadas[] = $runa_final;

            $rareza_contador = (string)($runa_final["rareza"] ?? ($runa_elegida["rareza"] ?? ""));

            if (isset($counters_rar[$rareza_contador])) {
                $counters_rar[$rareza_contador]++;
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

        $sql = "
            INSERT INTO jugador_runas (jugador_id, runa_id, cantidad)
            VALUES " . implode(",", $valores) . "
            ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)
        ";

        $stmt = $conexion->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    // ── Saldos finales ────────────────────────────────────────
    $coste_total  = $clicks_validos * COSTE_POR_TIRADA;
    $coins_final  = $coins_reales - $coste_total;
    $points_final = $points_reales;

    list($coins_ps_real, $points_por_seg_new, $coins_ps_max_real, $points_ps_max_real) =
        calcularStatsJugadorConConfig($conexion, $jugador_id);

    // ── Persistir estado del jugador ──────────────────────────
    $stmt = $conexion->prepare("
        UPDATE jugadores
        SET coins = ?,
            points = ?,
            coins_por_seg = ?,
            points_por_seg = ?,
            coins_ps_max = GREATEST(coins_ps_max, ?),
            points_ps_max = GREATEST(points_ps_max, ?),
            ultima_actualizacion = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param(
        "ddddddi",
        $coins_final,
        $points_final,
        $coins_ps_real,
        $points_por_seg_new,
        $coins_ps_max_real,
        $points_ps_max_real,
        $jugador_id
    );
    $stmt->execute();
    $stmt->close();

    // ── Stats agregadas ───────────────────────────────────────
    $total_runas_lote = (int)array_sum($contador_runas);

    $stmt = $conexion->prepare("
        INSERT INTO jugador_stats
            (jugador_id, total_tiradas, total_runas_conseguidas,
             total_eternas, total_divinas, total_miticas, total_legendarias,
             fecha_primera_tirada)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            total_tiradas = total_tiradas + VALUES(total_tiradas),
            total_runas_conseguidas = total_runas_conseguidas + VALUES(total_runas_conseguidas),
            total_eternas = total_eternas + VALUES(total_eternas),
            total_divinas = total_divinas + VALUES(total_divinas),
            total_miticas = total_miticas + VALUES(total_miticas),
            total_legendarias = total_legendarias + VALUES(total_legendarias)
    ");
    $stmt->bind_param(
        "iiiiiii",
        $jugador_id,
        $clicks_validos,
        $total_runas_lote,
        $counters_rar["eterna"],
        $counters_rar["divina"],
        $counters_rar["mitica"],
        $counters_rar["legendaria"]
    );
    $stmt->execute();
    $stmt->close();

    // ── Refrescar runas del jugador para el panel ─────────────
    $stmt = $conexion->prepare("
        SELECT r.id, r.nombre, r.rareza, jr.cantidad, g.nombre AS grupo_nombre
        FROM jugador_runas jr
        INNER JOIN runas r ON jr.runa_id = r.id
        LEFT JOIN grupos_runas g ON r.grupo_id = g.id
        WHERE jr.jugador_id = ?
        ORDER BY g.id ASC,
                 FIELD(r.rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $mis_runas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ── Debug economía opcional ───────────────────────────────
    $debug_economia = null;

    if (function_exists('debugEconomiaActivo') && debugEconomiaActivo($datos)) {
        $debug_economia = [
            "endpoint" => "tirar_runa.php",
            "jugador_id" => $jugador_id,
            "points_antes" => $points,
            "points_despues" => $points_final,
            "points_por_seg_antes" => $points_por_seg,
            "points_por_seg_despues" => $points_por_seg_new,
            "elapsed_segundos" => $elapsed_efec,
            "passive_awarded" => $points_ganados,
            "clicks_recibidos" => $clicks_envs,
            "cantidad_bulk" => $cantidad_bulk,
            "tiradas_efectivas" => $clicks_validos,
            "count_runas_ganadas" => $total_runas_lote,
            "batch_id" => $batch_id,
            "batch_ya_procesado" => false,
            "pps_recalc_query_total" => function_exists('totalAportesPpsJugador')
                ? totalAportesPpsJugador($conexion, $jugador_id)
                : null,
            "top_aportes_pps" => function_exists('topAportesPpsJugador')
                ? topAportesPpsJugador($conexion, $jugador_id, 10)
                : [],
        ];
    }

    $conexion->commit();

    $_SESSION["batches_procesados"][] = $batch_id;

    if (count($_SESSION["batches_procesados"]) > 30) {
        $_SESSION["batches_procesados"] = array_slice($_SESSION["batches_procesados"], -30);
    }

    $respuesta = [
        "ok" => true,
        "clicks_enviados" => $clicks_envs,
        "clicks_validos" => $clicks_validos,
        "runas_ganadas" => $runas_ganadas,
        "coins" => $coins_final,
        "points" => $points_final,
        "coins_por_seg" => $coins_ps_real,
        "points_por_seg" => $points_por_seg_new,
        "runas" => $mis_runas,
        "motivo" => $motivo,
        "luck_multiplier" => $luck_multiplier,
        "luck_shop_multiplier" => ($luck_detalle["tienda"] ?? $luck_multiplier),
        "luck_collection_multiplier" => ($luck_detalle["colecciones"] ?? 1),
        "completed_collections" => ($luck_detalle["colecciones_completadas"] ?? 0),
        "collection_states" => ($luck_detalle["colecciones_estado"] ?? []),
        "collection_bulk_bonus" => ($luck_detalle["bulk_bonus_colecciones"] ?? 0)
    ];

    if ($debug_economia !== null) {
        $respuesta["debug_economia"] = $debug_economia;
    }

    responderJson($respuesta);

} catch (Throwable $e) {
    @$conexion->rollback();

    error_log("tirar_runa: " . $e->getMessage());

    responderJson([
        "ok" => false,
        "error" => "Error interno"
    ]);
}

$conexion->close();