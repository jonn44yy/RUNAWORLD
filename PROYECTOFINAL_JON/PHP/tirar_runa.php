<?php
// 29/04 v4: sistema de cascada por rareza CON suerte controlada.
// La suerte funciona como multiplicador directo de probabilidad por rareza:
//   probabilidad efectiva = min(1, suerte / denominador)
// Ejemplo: con suerte x3, una rareza 1/100 pasa a 3/100.
// Si suerte >= denominador, esa rareza queda garantizada al llegar a ella
// y todas las rarezas inferiores dejan de salir por el break del cascade.
// Orden: eterna -> divina -> mitica -> legendaria -> epica -> rara -> poco_comun -> comun.
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

// ── Config suerte ────────────────────────────────────────────
// LUCK_MULTIPLIER_BASE es el multiplicador base del servidor.
//   1.0 = probabilidades normales
//   3.0 = x3 suerte: 1/100 pasa a 3/100
//   10.0 = poco_comun queda garantizada si no sale algo superior; comun deja de salir
//   25.0 = rara queda garantizada si no sale algo superior; poco_comun y comun dejan de salir
//   100.0 = epica queda garantizada si no sale algo superior; rara hacia abajo desaparecen
//   1000.0 = legendaria garantizada si no sale algo superior; epica hacia abajo desaparecen
//   5000.0 = mitica garantizada si no sale algo superior
//   25000.0 = divina garantizada si no sale eterna
//   100000.0 = eterna garantizada
define("LUCK_MULTIPLIER_BASE", 1.0);
define("LUCK_MULTIPLIER_MAX",  100000.0);
define("LUCK_HARD_CUT",        true);
define("CORRUPT_VARIANT_CHANCE", 0.01);

function rngFloat01(): float {
    return mt_rand() / mt_getrandmax();
}

function clampFloat(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
}

/**
 * Calcula la suerte final del jugador como multiplicador.
 *
 * Ahora mismo tu BD no tiene columna `suerte`, así que el sistema funciona
 * con LUCK_MULTIPLIER_BASE. Para hacerlo escalable sin tocar este archivo,
 * puedes crear mejoras con tipo `suerte`, `luck` o `luck_add`.
 *
 * Regla para mejoras futuras:
 *   suerte_final = LUCK_MULTIPLIER_BASE + SUM(mejora.valor * cantidad)
 *
 * Ejemplo:
 *   LUCK_MULTIPLIER_BASE = 1
 *   mejora tipo `suerte`, valor = 2, cantidad = 1
 *   suerte_final = 3 => x3 suerte
 */
function calcularMultiplicadorSuerte(mysqli $conexion, int $jugador_id): float {
    if (function_exists('calcularMultiplicadorSuerteJugador')) {
        return clampFloat((float)calcularMultiplicadorSuerteJugador($conexion, $jugador_id), 1.0, (float) LUCK_MULTIPLIER_MAX);
    }
    $luck = (float) LUCK_MULTIPLIER_BASE;
    $stmt = $conexion->prepare("\n        SELECT COALESCE(SUM(m.valor * jm.nivel), 0) AS suerte_bonus\n        FROM jugador_mejoras jm\n        INNER JOIN mejoras m ON jm.mejora_id = m.id\n        WHERE jm.jugador_id = ?\n          AND m.activa = 1\n          AND m.tipo IN ('suerte', 'luck', 'luck_add')\n    ");
    if ($stmt) {
        $stmt->bind_param("i", $jugador_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $luck += (float)($row["suerte_bonus"] ?? 0);
    }
    return clampFloat($luck, 1.0, (float) LUCK_MULTIPLIER_MAX);
}

/**
 * Devuelve true si la rareza acierta con la suerte actual.
 * Fórmula: P(acierto) = min(1, luck / denominador).
 *
 * Con esto, x3 suerte significa exactamente x3 sobre la probabilidad
 * condicional de esa rareza dentro del cascade.
 */
function aciertaRarezaConSuerte(int $denom, float $luck): bool {
    if ($denom <= 1) return true;

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
    if (!$basicas_completas) return $runa;
    if (rngFloat01() >= CORRUPT_VARIANT_CHANCE) return $runa;

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

    $runa["variante"] = "corrupta";
    $runa["variante_label"] = "Corrupta";
    $runa["multiplicador"] = ((float)($runa["multiplicador"] ?? 1)) * 100;
    $anim_key = animacionCorruptaKeyPorRareza($rareza);
    if ($anim_key !== "") {
        $runa["animacion_slug"] = $anim_key;
        $runa["rareza_animacion"] = $anim_key;
    }
    $runa["nombre"] = ($runa["nombre"] ?? "Runa") . " Corrupta";
    return $runa;
}

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
    $resp_dup = ["ok" => true, "duplicado" => true, "clicks_validos" => 0];
    if (function_exists('debugEconomiaActivo') && debugEconomiaActivo(is_array($datos) ? $datos : [])) {
        $resp_dup["debug_economia"] = [
            "endpoint" => "tirar_runa.php",
            "batch_id" => $batch_id,
            "batch_ya_procesado" => true,
            "clicks_recibidos" => $clicks_envs,
        ];
    }
    echo json_encode($resp_dup);
    exit;
}

$conexion->begin_transaction();

try {
    // ── Lock del jugador ───────────────────────────────────────
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

    $jugador_id      = (int)   $jugador["id"];
    $luck_multiplier = calcularMultiplicadorSuerte($conexion, $jugador_id);
    $luck_detalle = function_exists('calcularSuerteJugadorDetalle') ? calcularSuerteJugadorDetalle($conexion, $jugador_id) : ["total"=>$luck_multiplier,"tienda"=>$luck_multiplier,"colecciones"=>1,"colecciones_completadas"=>0,"bonus_por_coleccion"=>1.5];
    $basicas_completas = function_exists('coleccionBasicaCompletaJugador') ? coleccionBasicaCompletaJugador($conexion, $jugador_id) : false;
    $coins           = floatval($jugador["coins"]);
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
            "points_por_seg" => $points_por_seg,
            "luck_multiplier"   => $luck_multiplier,
        "luck_shop_multiplier" => ($luck_detalle["tienda"] ?? $luck_multiplier),
        "luck_collection_multiplier" => ($luck_detalle["colecciones"] ?? 1),
        "completed_collections" => ($luck_detalle["colecciones_completadas"] ?? 0),
        "collection_states" => ($luck_detalle["colecciones_estado"] ?? []),
        "collection_bulk_bonus" => ($luck_detalle["bulk_bonus_colecciones"] ?? 0)
        ]);
        exit;
    }

    // Bulk real: sin mejora = 1 runa/click; bulk/bulk_normal suman por nivel;
    // bulk_extra suma su valor una vez si esta comprado.
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
        WHERE jm.jugador_id = ? AND m.activa = 1
          AND m.tipo IN ('bulk','bulk_normal','bulk_extra')
    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $bulk_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $cantidad_bulk = max(1, 1 + (int)($bulk_row["bulk_add"] ?? 0));
    $cantidad_bulk += (int)($luck_detalle["bulk_bonus_colecciones"] ?? 0);

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
    $stmt = $conexion->prepare("SELECT id, nombre, rareza, multiplicador FROM runas
        WHERE activa = 1
          AND LOWER(nombre) NOT LIKE '%corrupt%'
          AND LOWER(nombre) NOT LIKE '%caos%' ");
    $stmt->execute();
    $runas_all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();


    $stmt = $conexion->prepare("
        SELECT id, nombre, rareza, multiplicador
        FROM runas
        WHERE activa = 1
          AND LOWER(nombre) LIKE '%corrupt%'
          AND LOWER(nombre) NOT LIKE '%caos%'
    ");
    $stmt->execute();
    $runas_corruptas_all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $corruptas_por_rareza = [];
    foreach ($runas_corruptas_all as $cr) {
        $corruptas_por_rareza[$cr["rareza"]] = $cr;
    }
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

        // cascada normal con suerte:
        // rareza mas rara primero, primera que acierte gana.
        // La suerte multiplica la probabilidad condicional de cada rareza:
        //   denom 100 con suerte x3 => 3/100
        // Si luck >= denom, esa rareza queda garantizada al llegar a ella
        // y las inferiores desaparecen porque hacemos break.
        if ($rareza_elegida === null) {
            foreach ($rarezas_cascade as $rar) {
                $denom = (int)$rar["denominador"];
                if ($denom <= 0) continue;

                if (aciertaRarezaConSuerte($denom, $luck_multiplier)) {
                    $rareza_elegida = $rar["slug"];
                    break;
                }
            }

            // si nada acerto -> fallback (normalmente comun)
            if ($rareza_elegida === null) {
                $rareza_elegida = $rareza_fallback;
            }
        }

        // dentro de la rareza, runa al azar (todas misma prob)
        if (!empty($runas_por_rareza[$rareza_elegida])) {
            $cands = $runas_por_rareza[$rareza_elegida];
            $runa_elegida = $cands[array_rand($cands)];
        }

        if ($runa_elegida || $debug_runa_forzada) {
            $runa_final = $debug_runa_forzada ?: aplicarVarianteCorruptaSiToca($runa_elegida, $basicas_completas, $corruptas_por_rareza);
            $rid = (int) $runa_final["id"];
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
    list($coins_ps_real, $points_por_seg_new, $coins_ps_max_real, $points_ps_max_real) = calcularStatsJugadorConConfig($conexion, $jugador_id);

    // persistir estado del jugador
    $stmt = $conexion->prepare("
        UPDATE jugadores
        SET coins = ?, points = ?,
            coins_por_seg = ?, points_por_seg = ?,
            coins_ps_max = GREATEST(coins_ps_max, ?),
            points_ps_max = GREATEST(points_ps_max, ?),
            ultima_actualizacion = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ddddddi", $coins_final, $points_final, $coins_ps_real, $points_por_seg_new, $coins_ps_max_real, $points_ps_max_real, $jugador_id);
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

    $debug_economia = null;
    if (function_exists('debugEconomiaActivo') && debugEconomiaActivo(is_array($datos) ? $datos : [])) {
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
            "pps_recalc_query_total" => function_exists('totalAportesPpsJugador') ? totalAportesPpsJugador($conexion, $jugador_id) : null,
            "top_aportes_pps" => function_exists('topAportesPpsJugador') ? topAportesPpsJugador($conexion, $jugador_id, 10) : [],
        ];
    }

    $conexion->commit();

    $_SESSION["batches_procesados"][] = $batch_id;
    if (count($_SESSION["batches_procesados"]) > 30) {
        $_SESSION["batches_procesados"] = array_slice($_SESSION["batches_procesados"], -30);
    }

    $respuesta = [
        "ok"                => true,
        "clicks_enviados"   => $clicks_envs,
        "clicks_validos"    => $clicks_validos,
        "runas_ganadas"     => $runas_ganadas,
        "coins"             => $coins_final,
        "points"            => $points_final,
        "coins_por_seg"     => $coins_por_seg,
        "points_por_seg"    => $points_por_seg_new,
        "runas"             => $mis_runas,
        "motivo"            => $motivo,
        "luck_multiplier"   => $luck_multiplier,
        "luck_shop_multiplier" => ($luck_detalle["tienda"] ?? $luck_multiplier),
        "luck_collection_multiplier" => ($luck_detalle["colecciones"] ?? 1),
        "completed_collections" => ($luck_detalle["colecciones_completadas"] ?? 0)
    ];
    if ($debug_economia !== null) $respuesta["debug_economia"] = $debug_economia;
    echo json_encode($respuesta);

} catch (Exception $e) {
    @$conexion->rollback();
    error_log("tirar_runa: " . $e->getMessage());
    echo json_encode(["ok" => false, "error" => "Error interno"]);
}

$conexion->close();
