<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    echo json_encode(["ok" => false]);
    exit;
}

require_once "conexion.php";

$stmt = $conexion->prepare("SELECT COUNT(*) as total FROM jugadores WHERE ultima_actualizacion > NOW() - INTERVAL 2 MINUTE");
$stmt->execute();
$activos = $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();
$conexion->close();

echo json_encode(["ok" => true, "activos" => $activos]);
