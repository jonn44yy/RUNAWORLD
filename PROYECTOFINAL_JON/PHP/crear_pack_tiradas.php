<?php
// crear_pack_tiradas.php — Runaworld v6.0 pack RNG
// Genera un paquete de tiradas autorizadas por el servidor para que el
// cliente pueda mostrarlas una a una de forma instantanea sin hacer un fetch
// por cada click.
//
// Modelo v6.0: el pack reserva/cobra coins al crearse; las runas se aplican al confirmar consumo.
// Ventajas: servidor autoritativo, pocas peticiones, si el usuario cierra
// la pestana no pierde las runas ya pagadas/generadas.
//
// Payload esperado:
//   { "cantidad": 25, "pack_id": "uuid-v4" }
// Respuesta:
//   { ok, pack_id, unidades:[{seq, runas_ganadas:[...]}], coins, points, ... }

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]); exit;
}

require_once "conexion.php";
require_once "calcular_stats.php";

define("COSTE_POR_TIRADA", 1);
define("PACK_SIZE_MAX", 50);          // maximo de clicks preautorizados por request
define("PACK_SIZE_DEFAULT", 50);
define("ELAPSED_CAP", 600);
define("PACK_MIN_INTERVAL_MS", 50);  // rate limit suave por sesion
define("LUCK_MULTIPLIER_BASE", 1.0);
define("LUCK_MULTIPLIER_MAX", 100000.0);
define("LUCK_HARD_CUT", true);
define("CORRUPT_VARIANT_CHANCE", 0.01);

function responder(array $data): void {
    echo json_encode($data);
    exit;
}

function rngFloat01(): float {
    return mt_rand() / mt_getrandmax();
}

function clampFloat(float $value, float $min, float $max): float {
    return max($min, min($max, $value));
}

function calcularMultiplicadorSuertePack(mysqli $conexion, int $jugador_id): float {
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

function aciertaRarezaConSuertePack(int $denom, float $luck): bool {
    if ($denom <= 1) return true;
    $probabilidad = $luck / $denom;
    if (LUCK_HARD_CUT && $probabilidad >= 1.0) return true;
    return rngFloat01() < min(1.0, $probabilidad);
}

function animacionCorruptaKeyPorRarezaPack(string $rareza): string {
    if ($rareza === "eterna") return "eterna_corrupta";
    if ($rareza === "divina") return "divina_corrupta";
    if ($rareza === "mitica") return "mitica_corrupta";
    if ($rareza === "legendaria") return "legendaria_corrupta";
    return "";
}

function uuidServidor(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function ensurePackTable(mysqli $conexion): void {
    // Tabla ligera para idempotencia/auditoria. No es la fuente de verdad de
    // inventario; las runas se aplican al crear el pack.
    $conexion->query("\n        CREATE TABLE IF NOT EXISTS packs_tiradas (\n            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n            jugador_id INT NOT NULL,\n            pack_id VARCHAR(64) NOT NULL,\n            resultados_json MEDIUMTEXT NOT NULL,\n            total_clicks INT NOT NULL DEFAULT 0,\n            total_runas INT NOT NULL DEFAULT 0,\n            consumidas INT NOT NULL DEFAULT 0,\n            estado ENUM('creado','parcial','consumido') NOT NULL DEFAULT 'creado',\n            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            confirmado_en DATETIME NULL,\n            PRIMARY KEY (id),\n            UNIQUE KEY uq_pack_id (pack_id),\n            KEY idx_jugador_creado (jugador_id, creado_en)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");
}

function cargarInventario(mysqli $conexion, int $jugador_id): array {
    $stmt = $conexion->prepare("\n        SELECT r.id, r.nombre, r.rareza, jr.cantidad, g.nombre as grupo_nombre\n        FROM jugador_runas jr\n        INNER JOIN runas r ON jr.runa_id = r.id\n        LEFT JOIN grupos_runas g ON r.grupo_id = g.id\n        WHERE jr.jugador_id = ?\n        ORDER BY g.id ASC, FIELD(r.rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')\n    ");
    $stmt->bind_param("i", $jugador_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}



function aplicarVarianteCorruptaSiTocaPack(array $runa, bool $basicas_completas, array $corruptas_por_rareza): array {
    if (!$basicas_completas) return $runa;
    if (rngFloat01() >= CORRUPT_VARIANT_CHANCE) return $runa;

    $rareza = (string)($runa["rareza"] ?? "");
    if (isset($corruptas_por_rareza[$rareza])) {
        $corrupta = $corruptas_por_rareza[$rareza];
        $corrupta["variante"] = "corrupta";
        $corrupta["variante_label"] = "Corrupta";
        $corrupta["base_runa_id"] = (int)($runa["id"] ?? 0);
        $anim_key = animacionCorruptaKeyPorRarezaPack($rareza);
        if ($anim_key !== "") {
            $corrupta["animacion_slug"] = $anim_key;
            $corrupta["rareza_animacion"] = $anim_key;
        }
        return $corrupta;
    }

    // Fallback defensivo: si falta la fila corrupta en BD, al menos el cliente la ve como corrupta.
    $runa["variante"] = "corrupta";
    $runa["variante_label"] = "Corrupta";
    $runa["multiplicador"] = ((float)($runa["multiplicador"] ?? 1)) * 100;
    $anim_key = animacionCorruptaKeyPorRarezaPack($rareza);
    if ($anim_key !== "") {
        $runa["animacion_slug"] = $anim_key;
        $runa["rareza_animacion"] = $anim_key;
    }
    $runa["nombre"] = ($runa["nombre"] ?? "Runa") . " Corrupta";
    return $runa;
}

$datos = json_decode(file_get_contents("php://input"), true);
if (!is_array($datos)) $datos = [];

$id_usuario = (int)$_SESSION["idUsuario"];
$cantidad_solicitada = (int)($datos["cantidad"] ?? PACK_SIZE_DEFAULT);
$pack_id = (string)($datos["pack_id"] ?? uuidServidor());

if ($cantidad_solicitada <= 0) {
    responder(["ok" => false, "error" => "cantidad invalida"]);
}
if ($cantidad_solicitada > PACK_SIZE_MAX) {
    $cantidad_solicitada = PACK_SIZE_MAX;
}
if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $pack_id)) {
    responder(["ok" => false, "error" => "pack_id invalido"]);
}

// Rate limit suave por sesion: suficiente para proteger de loops accidentales,
// sin perjudicar la precarga normal del cliente.
$now_ms = (int)floor(microtime(true) * 1000);
$last_ms = (int)($_SESSION["ultimo_pack_ms"] ?? 0);
if ($last_ms > 0 && ($now_ms - $last_ms) < PACK_MIN_INTERVAL_MS) {
    responder(["ok" => false, "error" => "demasiadas peticiones", "retry_ms" => 150]);
}
$_SESSION["ultimo_pack_ms"] = $now_ms;

$conexion->begin_transaction();

try {
    ensurePackTable($conexion);

    // Idempotencia: si el cliente reintenta el mismo pack_id, devolvemos lo ya generado.
    $stmt = $conexion->prepare("SELECT resultados_json FROM packs_tiradas WHERE pack_id = ? LIMIT 1");
    $stmt->bind_param("s", $pack_id);
    $stmt->execute();
    $packExistente = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($packExistente) {
        $conexion->commit();
        $data = json_decode($packExistente["resultados_json"], true);
        if (is_array($data)) responder($data + ["duplicado" => true]);
        responder(["ok" => false, "error" => "pack duplicado corrupto"]);
    }

    $stmt = $conexion->prepare("\n        SELECT id, coins, points, coins_por_seg, points_por_seg,\n               UNIX_TIMESTAMP(ultima_actualizacion) AS ult_ts\n        FROM jugadores WHERE usuario_id = ? FOR UPDATE\n    ");
    $stmt->bind_param("i", $id_usuario);
    $stmt->execute();
    $jugador = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$jugador) {
        $conexion->rollback();
        responder(["ok" => false, "error" => "Jugador no encontrado"]);
    }

    $jugador_id = (int)$jugador["id"];
    $luck_multiplier = calcularMultiplicadorSuertePack($conexion, $jugador_id);
    $luck_detalle = function_exists('calcularSuerteJugadorDetalle') ? calcularSuerteJugadorDetalle($conexion, $jugador_id) : ["total"=>$luck_multiplier,"tienda"=>$luck_multiplier,"colecciones"=>1,"colecciones_completadas"=>0,"bonus_por_coleccion"=>1.5];
    $basicas_completas = function_exists('coleccionBasicaCompletaJugador') ? coleccionBasicaCompletaJugador($conexion, $jugador_id) : false;
    $coins = (float)$jugador["coins"];
    $points = (float)$jugador["points"];
    $coins_por_seg = (float)$jugador["coins_por_seg"];
    $points_por_seg = (float)$jugador["points_por_seg"];
    $ult_ts = (int)($jugador["ult_ts"] ?? 0);
    $elapsed_efec = min(max(0, time() - $ult_ts), ELAPSED_CAP);

    $coins_reales = $coins + ($coins_por_seg * $elapsed_efec);
    $points_reales = $points + ($points_por_seg * $elapsed_efec);

    $clicks_max_coins = (int)floor($coins_reales / COSTE_POR_TIRADA);
    $clicks_validos = min($cantidad_solicitada, $clicks_max_coins);

    if ($clicks_validos <= 0) {
        $stmt = $conexion->prepare("\n            UPDATE jugadores SET coins = ?, points = ?, ultima_actualizacion = NOW() WHERE id = ?\n        ");
        $stmt->bind_param("ddi", $coins_reales, $points_reales, $jugador_id);
        $stmt->execute();
        $stmt->close();
        $conexion->commit();
        responder(["ok" => true, "pack_id" => $pack_id, "unidades" => [], "clicks_validos" => 0, "motivo_corte" => "sin_coins", "coins" => $coins_reales, "points" => $points_reales, "points_por_seg" => $points_por_seg, "luck_multiplier" => $luck_multiplier, "luck_shop_multiplier" => ($luck_detalle["tienda"] ?? $luck_multiplier), "luck_collection_multiplier" => ($luck_detalle["colecciones"] ?? 1), "completed_collections" => ($luck_detalle["colecciones_completadas"] ?? 0)]);
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

    $stmt = $conexion->prepare("SELECT slug, denominador FROM rarezas WHERE activa = 1 ORDER BY denominador DESC");
    $stmt->execute();
    $rarezas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (empty($rarezas)) {
        $conexion->rollback();
        responder(["ok" => false, "error" => "No hay rarezas"]);
    }

    $rarezas_cascade = [];
    $rareza_fallback = null;
    foreach ($rarezas as $r) {
        if ((int)$r["denominador"] <= 1) $rareza_fallback = $r["slug"];
        else $rarezas_cascade[] = $r;
    }
    if ($rareza_fallback === null) {
        $rareza_fallback = end($rarezas)["slug"];
        array_pop($rarezas_cascade);
    }

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
                    $anim_key = animacionCorruptaKeyPorRarezaPack((string)$debug_runa_forzada["rareza"]);
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
        responder(["ok" => false, "error" => "No hay runas"]);
    }

    $runas_por_rareza = [];
    foreach ($runas_all as $r) $runas_por_rareza[$r["rareza"]][] = $r;

    if (!isset($_SESSION["debug_forzadas"])) $_SESSION["debug_forzadas"] = [];

    $unidades = [];
    $contador_runas = [];
    $counters_rar = ["eterna"=>0, "divina"=>0, "mitica"=>0, "legendaria"=>0];

    for ($click = 1; $click <= $clicks_validos; $click++) {
        $runasUnidad = [];
        for ($b = 0; $b < $cantidad_bulk; $b++) {
            $runa_elegida = null;
            $rareza_elegida = null;

            if (!empty($_SESSION["debug_forzadas"])) {
                $rareza_forzada = array_shift($_SESSION["debug_forzadas"]);
                if (!empty($runas_por_rareza[$rareza_forzada])) $rareza_elegida = $rareza_forzada;
            }

            if ($rareza_elegida === null) {
                foreach ($rarezas_cascade as $rar) {
                    $denom = (int)$rar["denominador"];
                    if ($denom <= 0) continue;
                    if (aciertaRarezaConSuertePack($denom, $luck_multiplier)) {
                        $rareza_elegida = $rar["slug"];
                        break;
                    }
                }
                if ($rareza_elegida === null) $rareza_elegida = $rareza_fallback;
            }

            if (!empty($runas_por_rareza[$rareza_elegida])) {
                $cands = $runas_por_rareza[$rareza_elegida];
                $runa_elegida = $cands[array_rand($cands)];
            }

            if ($runa_elegida || $debug_runa_forzada) {
                $runa_visual = $debug_runa_forzada ?: aplicarVarianteCorruptaSiTocaPack($runa_elegida, $basicas_completas, $corruptas_por_rareza);
                $rid = (int)$runa_visual["id"];
                $contador_runas[$rid] = ($contador_runas[$rid] ?? 0) + 1;
                $runasUnidad[] = $runa_visual;
                $rareza_contador = (string)($runa_visual["rareza"] ?? ($runa_elegida["rareza"] ?? ""));
                if (isset($counters_rar[$rareza_contador])) $counters_rar[$rareza_contador]++;
            }
        }
        $unidades[] = ["seq" => $click, "pack_id" => $pack_id, "runas_ganadas" => $runasUnidad];
    }

    // v5.8: NO aplicamos las runas aqui.
    // El pack solo reserva/cobra coins y guarda los resultados. Las runas,
    // stats y points_por_seg se aplican en confirmar_pack_tiradas.php cuando
    // el cliente realmente revela/consume cada unidad.
    $total_runas_lote = (int) array_sum($contador_runas);

    $coste_total = $clicks_validos * COSTE_POR_TIRADA;
    $coins_final = $coins_reales - $coste_total;
    $points_final = $points_reales;

    list($coins_ps_real, $points_por_seg_real, $coins_ps_max_real, $points_ps_max_real) = calcularStatsJugadorConConfig($conexion, $jugador_id);

    $stmt = $conexion->prepare("\n        UPDATE jugadores\n        SET coins = ?, points = ?,
            coins_por_seg = ?, points_por_seg = ?,
            coins_ps_max = GREATEST(coins_ps_max, ?),
            points_ps_max = GREATEST(points_ps_max, ?),
            ultima_actualizacion = NOW()
        WHERE id = ?\n    ");
    $stmt->bind_param("ddddddi", $coins_final, $points_final, $coins_ps_real, $points_por_seg_real, $coins_ps_max_real, $points_ps_max_real, $jugador_id);
    $stmt->execute();
    $stmt->close();

    $mis_runas = cargarInventario($conexion, $jugador_id);

    $debug_economia = null;
    if (function_exists('debugEconomiaActivo') && debugEconomiaActivo(is_array($datos) ? $datos : [])) {
        $debug_economia = [
            "endpoint" => "crear_pack_tiradas.php",
            "jugador_id" => $jugador_id,
            "points_antes" => $points,
            "points_despues" => $points_final,
            "points_por_seg_antes" => $points_por_seg,
            "points_por_seg_despues" => $points_por_seg_real,
            "elapsed_segundos" => $elapsed_efec,
            "passive_awarded" => $points_por_seg * $elapsed_efec,
            "clicks_recibidos" => $cantidad_solicitada,
            "cantidad_bulk" => $cantidad_bulk,
            "tiradas_efectivas" => $clicks_validos,
            "count_runas_ganadas" => $total_runas_lote,
            "batch_id" => $pack_id,
            "batch_ya_procesado" => false,
            "pps_recalc_query_total" => function_exists('totalAportesPpsJugador') ? totalAportesPpsJugador($conexion, $jugador_id) : null,
            "top_aportes_pps" => function_exists('topAportesPpsJugador') ? topAportesPpsJugador($conexion, $jugador_id, 10) : [],
        ];
    }

    $respuesta = [
        "ok" => true,
        "mode" => "pack",
        "pack_id" => $pack_id,
        "clicks_solicitados" => $cantidad_solicitada,
        "clicks_validos" => $clicks_validos,
        "bulk" => $cantidad_bulk,
        "unidades" => $unidades,
        "coins" => $coins_final,
        "points" => $points_final,
        "coins_por_seg" => $coins_ps_real,
        "points_por_seg" => $points_por_seg_real,
        "runas" => $mis_runas,
        "luck_multiplier" => $luck_multiplier, "luck_shop_multiplier" => ($luck_detalle["tienda"] ?? $luck_multiplier), "luck_collection_multiplier" => ($luck_detalle["colecciones"] ?? 1), "completed_collections" => ($luck_detalle["colecciones_completadas"] ?? 0),
        "collection_states" => ($luck_detalle["colecciones_estado"] ?? []), "collection_bulk_bonus" => ($luck_detalle["bulk_bonus_colecciones"] ?? 0)
    ];
    if ($debug_economia !== null) $respuesta["debug_economia"] = $debug_economia;

    $json = json_encode($respuesta);
    $stmt = $conexion->prepare("\n        INSERT INTO packs_tiradas (jugador_id, pack_id, resultados_json, total_clicks, total_runas, consumidas, estado)\n        VALUES (?, ?, ?, ?, ?, 0, 'creado')\n    ");
    $stmt->bind_param("issii", $jugador_id, $pack_id, $json, $clicks_validos, $total_runas_lote);
    $stmt->execute();
    $stmt->close();

    $conexion->commit();
    responder($respuesta);

} catch (Throwable $e) {
    @$conexion->rollback();
    error_log("crear_pack_tiradas: " . $e->getMessage());
    responder(["ok" => false, "error" => "Error interno"]);
}

$conexion->close();
