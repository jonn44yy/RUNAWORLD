<?php
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";

$id_usuario = $_SESSION["idUsuario"];

// Obtener id del jugador
$stmt = $conexion->prepare("SELECT id FROM jugadores WHERE usuario_id = ?");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$jugador = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$jugador) {
    echo json_encode(["ok" => false, "error" => "Jugador no encontrado"]);
    exit;
}

$jugador_id = $jugador["id"];

// Borrar runas del jugador
$stmt = $conexion->prepare("DELETE FROM jugador_runas WHERE jugador_id = ?");
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$stmt->close();

// Borrar mejoras del jugador
$stmt = $conexion->prepare("DELETE FROM jugador_mejoras WHERE jugador_id = ?");
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$stmt->close();

// Resetear progreso
$stmt = $conexion->prepare("UPDATE jugadores SET coins = 0, points = 0, coins_por_seg = 1, points_por_seg = 0, suerte = 1.00 WHERE id = ?");
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$stmt->close();

$conexion->close();

echo json_encode(["ok" => true]);