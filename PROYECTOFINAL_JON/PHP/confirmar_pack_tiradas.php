<?php
// confirmar_pack_tiradas.php — Runaworld v6.0
//
// En v5.8 los packs se cobran/generan en crear_pack_tiradas.php, pero las
// runas NO se aplican al inventario hasta que el cliente confirma que ha
// revelado/consumido esas unidades. Esto evita que una sola pulsacion parezca
// haber entregado 25 runas de golpe.

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]); exit;
}

require_once "conexion.php";
require_once "calcular_stats.php";

function responder(array $data): void {
    echo json_encode($data);
    exit;
}

function ensurePackTableConfirm(mysqli $conexion): void {
    $conexion->query("
        CREATE TABLE IF NOT EXISTS packs_tiradas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            jugador_id INT NOT NULL,
            pack_id VARCHAR(64) NOT NULL,
            resultados_json MEDIUMTEXT NOT NULL,
            total_clicks INT NOT NULL DEFAULT 0,
            total_runas INT NOT NULL DEFAULT 0,
            consumidas INT NOT NULL DEFAULT 0,
            estado ENUM('creado','parcial','consumido') NOT NULL DEFAULT 'creado',
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmado_en DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pack_id (pack_id),
            KEY idx_jugador_creado (jugador_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function cargarInventarioConfirm(mysqli $conexion, int $jugador_id): array {
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
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$datos = json_decode(file_get_contents("php://input"), true);
if (!is_array($datos)) $datos = [];

$pack_id = (string)($datos["pack_id"] ?? "");
$consumidas = (int)($datos["consumidas"] ?? 0);

if (!preg_match('/^[a-f0-9\-]{8,64}$/i', $pack_id)) {
    responder(["ok" => false, "error" => "pack_id invalido"]);
}
if ($consumidas < 0) $consumidas = 0;

$id_usuario = (int)$_SESSION["idUsuario"];

$conexion->begin_transaction();

try {
    ensurePackTableConfirm($conexion);

    $stmt = $conexion->prepare("
        SELECT pt.id, pt.jugador_id, pt.total_clicks, pt.consumidas, pt.resultados_json,
               j.coins, j.points, j.coins_por_seg, j.points_por_seg,
               UNIX_TIMESTAMP(j.ultima_actualizacion) AS ult_ts
        FROM packs_tiradas pt
        INNER JOIN jugadores j ON pt.jugador_id = j.id
        WHERE pt.pack_id = ? AND j.usuario_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param("si", $pack_id, $id_usuario);
    $stmt->execute();
    $pack = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pack) {
        $conexion->rollback();
        responder(["ok" => false, "error" => "pack no encontrado"]);
    }

    $jugador_id = (int)$pack["jugador_id"];
    $total = (int)$pack["total_clicks"];
    $prev = (int)$pack["consumidas"];
    $nuevo = max($prev, min($total, $consumidas));

    // Idempotente: si ya estaba confirmado hasta ese punto, no vuelve a sumar runas.
    if ($nuevo <= $prev) {
        $estado = $prev >= $total ? "consumido" : ($prev > 0 ? "parcial" : "creado");
        $conexion->commit();
        responder(["ok" => true, "pack_id" => $pack_id, "consumidas" => $prev, "total" => $total, "estado" => $estado, "sin_cambios" => true]);
    }

    $data = json_decode($pack["resultados_json"], true);
    $unidades = is_array($data["unidades"] ?? null) ? $data["unidades"] : [];

    $contador_runas = [];
    $counters_rar = ["eterna"=>0, "divina"=>0, "mitica"=>0, "legendaria"=>0];

    for ($idx = $prev; $idx < $nuevo; $idx++) {
        if (!isset($unidades[$idx])) continue;
        $runasUnidad = is_array($unidades[$idx]["runas_ganadas"] ?? null) ? $unidades[$idx]["runas_ganadas"] : [];
        foreach ($runasUnidad as $runa) {
            $rid = (int)($runa["id"] ?? 0);
            if ($rid <= 0) continue;
            $contador_runas[$rid] = ($contador_runas[$rid] ?? 0) + 1;
            $rar = (string)($runa["rareza"] ?? "");
            if (isset($counters_rar[$rar])) $counters_rar[$rar]++;
        }
    }

    if (!empty($contador_runas)) {
        $valores = [];
        $params = [];
        $types = "";
        foreach ($contador_runas as $runa_id => $cantidad) {
            $valores[] = "(?, ?, ?)";
            $params[] = $jugador_id;
            $params[] = $runa_id;
            $params[] = $cantidad;
            $types .= "iii";
        }
        $sql = "INSERT INTO jugador_runas (jugador_id, runa_id, cantidad)
                VALUES " . implode(",", $valores) . "
                ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    $total_runas_delta = (int)array_sum($contador_runas);
    if ($total_runas_delta > 0) {
        $tiradas_delta = $nuevo - $prev;
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
            $jugador_id, $tiradas_delta, $total_runas_delta,
            $counters_rar["eterna"], $counters_rar["divina"],
            $counters_rar["mitica"], $counters_rar["legendaria"]
        );
        $stmt->execute();
        $stmt->close();
    }

    // Aplicar pasivos desde la ultima actualizacion antes de cambiar pps.
    $elapsed = min(max(0, time() - (int)($pack["ult_ts"] ?? 0)), 600);
    $coins_actual = (float)$pack["coins"] + ((float)$pack["coins_por_seg"] * $elapsed);
    $points_actual = (float)$pack["points"] + ((float)$pack["points_por_seg"] * $elapsed);

    list($coins_ps_real, $points_ps_real, $coins_ps_max_real, $points_ps_max_real) = calcularStatsJugadorConConfig($conexion, $jugador_id);

    $estado = $nuevo >= $total ? "consumido" : ($nuevo > 0 ? "parcial" : "creado");

    $stmt = $conexion->prepare("
        UPDATE packs_tiradas
        SET consumidas = ?, estado = ?, confirmado_en = IF(? >= total_clicks, NOW(), confirmado_en)
        WHERE id = ?
    ");
    $stmt->bind_param("isii", $nuevo, $estado, $nuevo, $pack["id"]);
    $stmt->execute();
    $stmt->close();

    $stmt = $conexion->prepare("
        UPDATE jugadores
        SET coins = ?, points = ?,
            coins_por_seg = ?, points_por_seg = ?,
            coins_ps_max = GREATEST(coins_ps_max, ?),
            points_ps_max = GREATEST(points_ps_max, ?),
            ultima_actualizacion = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ddddddi", $coins_actual, $points_actual, $coins_ps_real, $points_ps_real, $coins_ps_max_real, $points_ps_max_real, $jugador_id);
    $stmt->execute();
    $stmt->close();

    $runas = cargarInventarioConfirm($conexion, $jugador_id);

    $conexion->commit();

    responder([
        "ok" => true,
        "pack_id" => $pack_id,
        "consumidas" => $nuevo,
        "aplicadas_ahora" => $nuevo - $prev,
        "total" => $total,
        "estado" => $estado,
        "coins" => $coins_actual,
        "points" => $points_actual,
        "coins_por_seg" => $coins_ps_real,
        "points_por_seg" => $points_ps_real,
        "runas" => $runas
    ]);

} catch (Throwable $e) {
    @$conexion->rollback();
    error_log("confirmar_pack_tiradas: " . $e->getMessage());
    responder(["ok" => false, "error" => "Error interno"]);
}

$conexion->close();
