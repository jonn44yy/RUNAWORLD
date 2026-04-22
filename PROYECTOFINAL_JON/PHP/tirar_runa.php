<?php
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]); exit;
}

require_once "conexion.php";

$datos      = json_decode(file_get_contents("php://input"), true);
$id_usuario = $_SESSION["idUsuario"];

// ── Leer datos del JS (toda la lógica es client-side) ────────
$coins      = floatval($datos["coins"]   ?? 0);
$points     = floatval($datos["points"]  ?? 0);

// ── Obtener jugador_id y suerte de BD ────────────────────────
$stmt = $conexion->prepare("SELECT id, suerte FROM jugadores WHERE usuario_id = ?");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$jugador    = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$jugador) { echo json_encode(["ok" => false, "error" => "Jugador no encontrado"]); exit; }

$jugador_id = $jugador["id"];
$suerte     = floatval($jugador["suerte"]);

// ── Bulk desde BD ─────────────────────────────────────────────
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

// ── Cargar runas activas ──────────────────────────────────────
$stmt = $conexion->prepare("SELECT id, nombre, rareza, peso, multiplicador FROM runas WHERE activa = 1");
$stmt->execute();
$runas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($runas)) { echo json_encode(["ok" => false, "error" => "No hay runas"]); exit; }

// ── Calcular pesos por suerte ─────────────────────────────────
require_once "calcular_pesos.php";
$campana      = calcularPesosPorSuerte($suerte, $conexion);
$pesos_rareza = $campana["pesos"];
$peso_total   = $campana["total"];

if ($peso_total <= 0) { echo json_encode(["ok" => false, "error" => "Sin runas disponibles"]); exit; }

foreach ($runas as &$r) {
    $r["peso_efectivo"] = $pesos_rareza[$r["rareza"]] ?? 0;
}
unset($r);

// ── Tirar ─────────────────────────────────────────────────────
// 20/04: cola de forzados para debug. si $_SESSION["debug_forzadas"] tiene
// algo, esas runas salen antes que el sorteo normal. se consumen de la cola.
// la cola se rellena desde PHP/debug_forzar_runa.php con ?rareza=eterna&cantidad=1
// cuando la cola esta vacia, volvemos a sorteo aleatorio normal
if (!isset($_SESSION["debug_forzadas"])) $_SESSION["debug_forzadas"] = [];

$runas_ganadas = [];
for ($i = 0; $i < $cantidad_bulk; $i++) {
    $runa_elegida = null;

    // si hay rareza forzada en la cola, saco una runa random de esa rareza
    if (!empty($_SESSION["debug_forzadas"])) {
        $rareza_forzada = array_shift($_SESSION["debug_forzadas"]);
        $candidatas = array_filter($runas, fn($r) => $r["rareza"] === $rareza_forzada);
        if (!empty($candidatas)) {
            $candidatas = array_values($candidatas);
            $runa_elegida = $candidatas[array_rand($candidatas)];
        }
    }

    // si no hay forzada (o fallo la busqueda), sorteo normal por peso
    if ($runa_elegida === null) {
        $aleatorio = (mt_rand() / mt_getrandmax()) * $peso_total;
        $acumulado = 0;
        foreach ($runas as $runa) {
            $acumulado += $runa["peso_efectivo"];
            if ($aleatorio <= $acumulado) { $runa_elegida = $runa; break; }
        }
    }

    if ($runa_elegida) $runas_ganadas[] = $runa_elegida;
}

// ── Guardar runas ganadas ─────────────────────────────────────
foreach ($runas_ganadas as $rg) {
    $stmt = $conexion->prepare("SELECT id, cantidad FROM jugador_runas WHERE jugador_id = ? AND runa_id = ?");
    $stmt->bind_param("ii", $jugador_id, $rg["id"]);
    $stmt->execute();
    $existe = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existe) {
        $nueva = $existe["cantidad"] + 1;
        $stmt  = $conexion->prepare("UPDATE jugador_runas SET cantidad = ? WHERE id = ?");
        $stmt->bind_param("ii", $nueva, $existe["id"]);
    } else {
        $stmt = $conexion->prepare("INSERT INTO jugador_runas (jugador_id, runa_id, cantidad) VALUES (?, ?, 1)");
        $stmt->bind_param("ii", $jugador_id, $rg["id"]);
    }
    $stmt->execute();
    $stmt->close();
}

// ── Comprobar bonus por completar grupo ──────────────────────
$bonus_conseguidos = [];

$stmt = $conexion->prepare("
    SELECT g.id as grupo_id, g.nombre as grupo_nombre,
           COUNT(r.id) as total_runas,
           COUNT(jr.runa_id) as runas_jugador,
           bg.id as bonus_id, bg.tipo as bonus_tipo,
           bg.valor as bonus_valor, bg.descripcion as bonus_desc
    FROM grupos_runas g
    INNER JOIN runas r ON r.grupo_id = g.id AND r.activa = 1
    LEFT JOIN jugador_runas jr ON jr.runa_id = r.id AND jr.jugador_id = ? AND jr.cantidad > 0
    INNER JOIN bonus_grupo bg ON bg.grupo_id = g.id
    LEFT JOIN jugador_bonus jb ON jb.bonus_id = bg.id AND jb.jugador_id = ?
    WHERE jb.id IS NULL
    GROUP BY g.id, bg.id
    HAVING runas_jugador = total_runas AND total_runas > 0
");
$stmt->bind_param("ii", $jugador_id, $jugador_id);
$stmt->execute();
$bonuses_nuevos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$suerte_final_para_guardar = $suerte;
foreach ($bonuses_nuevos as $b) {
    $stmt = $conexion->prepare("INSERT IGNORE INTO jugador_bonus (jugador_id, bonus_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $jugador_id, $b["bonus_id"]);
    $stmt->execute();
    $insertado = $stmt->affected_rows;
    $stmt->close();

    if ($insertado > 0) {
        if ($b["bonus_tipo"] === "suerte") {
            $suerte_final_para_guardar *= floatval($b["bonus_valor"]); // ← fix: usar la variable correcta
        }
        $bonus_conseguidos[] = [
            "grupo"       => $b["grupo_nombre"],
            "descripcion" => $b["bonus_desc"],
            "tipo"        => $b["bonus_tipo"],
            "valor"       => $b["bonus_valor"]
        ];
    }
}

// ── Guardar en BD — solo lo que el servidor gestiona ─────────
// coins y points los manda el JS ya calculados
$stmt = $conexion->prepare("
    UPDATE jugadores SET coins = ?, points = ?, suerte = ? WHERE id = ?
");
$stmt->bind_param("dddi", $coins, $points, $suerte_final_para_guardar, $jugador_id);
$stmt->execute();
$stmt->close();

// ── Calcular points_por_seg desde runas del jugador ──────────
$stmt = $conexion->prepare("
    SELECT COALESCE(SUM(r.multiplicador * jr.cantidad), 0) as total
    FROM jugador_runas jr
    INNER JOIN runas r ON jr.runa_id = r.id
    WHERE jr.jugador_id = ?
");
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$points_por_seg = floatval($stmt->get_result()->fetch_assoc()["total"]);
$stmt->close();

// ── Devolver runas del jugador ────────────────────────────────
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
$conexion->close();

echo json_encode([
    "ok"                => true,
    "runas_ganadas"     => $runas_ganadas,
    "coins_restantes"   => $coins,
    "points_por_seg"    => $points_por_seg,
    "suerte"            => $suerte_final_para_guardar,
    "runas"             => $mis_runas,
    "bonus_conseguidos" => $bonus_conseguidos
]);
