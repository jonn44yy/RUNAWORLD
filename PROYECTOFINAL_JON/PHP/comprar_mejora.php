<?php
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]); exit;
}

require_once "conexion.php";

$datos      = json_decode(file_get_contents("php://input"), true);
$mejora_id  = (int)($datos["mejora_id"] ?? 0);
$points     = floatval($datos["points"] ?? 0);
$coins      = floatval($datos["coins"]  ?? 0);
$id_usuario = $_SESSION["idUsuario"];

if ($mejora_id <= 0) { echo json_encode(["ok" => false, "error" => "Mejora inválida"]); exit; }

// ── Obtener jugador ───────────────────────────────────────────
$stmt = $conexion->prepare("SELECT id FROM jugadores WHERE usuario_id = ?");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$jugador = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$jugador) { echo json_encode(["ok" => false, "error" => "Jugador no encontrado"]); exit; }
$jugador_id = $jugador["id"];

// ── Obtener mejora ────────────────────────────────────────────
$stmt = $conexion->prepare("SELECT * FROM mejoras WHERE id = ? AND activa = 1");
$stmt->bind_param("i", $mejora_id);
$stmt->execute();
$mejora = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$mejora) { echo json_encode(["ok" => false, "error" => "Mejora no encontrada"]); exit; }

// ── Nivel actual ──────────────────────────────────────────────
$stmt = $conexion->prepare("SELECT nivel, cantidad FROM jugador_mejoras WHERE jugador_id = ? AND mejora_id = ?");
$stmt->bind_param("ii", $jugador_id, $mejora_id);
$stmt->execute();
$jm = $stmt->get_result()->fetch_assoc();
$stmt->close();
$nivel_actual   = (int)($jm["nivel"]    ?? 0);
$cantidad_actual= (int)($jm["cantidad"] ?? 0);

if ($nivel_actual >= $mejora["nivel_maximo"]) {
    echo json_encode(["ok" => false, "error" => "Nivel máximo alcanzado"]); exit;
}

// ── Coste y validar points ───────────────────────────────────
$coste = $mejora["coste_base"] * pow($mejora["coste_escala"], $nivel_actual);
if ($points < $coste) {
    echo json_encode(["ok" => false, "error" => "No tienes suficientes points"]); exit;
}

// ── Guardar nivel ─────────────────────────────────────────────
$nivel_nuevo    = $nivel_actual + 1;
$cantidad_nueva = $cantidad_actual + 1;

if ($jm) {
    $stmt = $conexion->prepare("UPDATE jugador_mejoras SET nivel = ?, cantidad = ? WHERE jugador_id = ? AND mejora_id = ?");
    $stmt->bind_param("iiii", $nivel_nuevo, $cantidad_nueva, $jugador_id, $mejora_id);
} else {
    $stmt = $conexion->prepare("INSERT INTO jugador_mejoras (jugador_id, mejora_id, nivel, cantidad) VALUES (?, ?, 1, 1)");
    $stmt->bind_param("ii", $jugador_id, $mejora_id);
}
$stmt->execute();
$stmt->close();

// ── Devolver TODAS las mejoras del jugador para que JS recalcule ──
$stmt = $conexion->prepare("
    SELECT m.tipo, m.valor, jm.nivel, jm.cantidad
    FROM jugador_mejoras jm
    INNER JOIN mejoras m ON m.id = jm.mejora_id
    WHERE jm.jugador_id = ? AND m.activa = 1
");
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$todas_mejoras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Coste siguiente nivel ─────────────────────────────────────
$coste_siguiente = $nivel_nuevo < $mejora["nivel_maximo"]
    ? $mejora["coste_base"] * pow($mejora["coste_escala"], $nivel_nuevo)
    : null;

// ── Descontar coste y guardar ────────────────────────────────
$points -= $coste; // descontar el coste de la mejora
$stmt = $conexion->prepare("UPDATE jugadores SET coins = ?, points = ? WHERE id = ?");
$stmt->bind_param("ddi", $coins, $points, $jugador_id);
$stmt->execute();
$stmt->close();
$conexion->close();

echo json_encode([
    "ok"              => true,
    "nivel"           => $nivel_nuevo,
    "nivel_maximo"    => (int)$mejora["nivel_maximo"],
    "coste_siguiente" => $coste_siguiente,
    "mejoras"         => $todas_mejoras,  // JS recalcula stats con esto
    "points"          => $points,
    "coins"           => $coins,
]);
