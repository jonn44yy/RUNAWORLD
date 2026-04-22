<?php
// ================================================================
// click_boost.php — incrementa el contador de boosts clickados
// ================================================================
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false]); exit;
}

require_once "conexion.php";

$id_usuario = $_SESSION["idUsuario"];

$stmt = $conexion->prepare("
    UPDATE jugador_stats js
    INNER JOIN jugadores j ON js.jugador_id = j.id
    SET js.boosts_clickados = js.boosts_clickados + 1
    WHERE j.usuario_id = ?
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$stmt->close();
$conexion->close();

echo json_encode(["ok" => true]);
