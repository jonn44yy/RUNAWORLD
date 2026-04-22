<?php
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";

$datos = json_decode(file_get_contents("php://input"), true);
if (!isset($datos["coins"]) || !isset($datos["points"])) {
    echo json_encode(["ok" => false, "error" => "Datos incompletos"]);
    exit;
}

// La lógica de coins/points es client-side (JS)
// Aquí solo guardamos el estado actual para persistencia
$coins      = floatval($datos["coins"]);
$points     = floatval($datos["points"]);
$id_usuario = $_SESSION["idUsuario"];

$stmt = $conexion->prepare("UPDATE jugadores SET coins = ?, points = ? WHERE usuario_id = ?");
$stmt->bind_param("ddi", $coins, $points, $id_usuario);
$stmt->execute() ? $result = true : $result = false;
$stmt->close();
$conexion->close();

echo json_encode(["ok" => $result]);
