<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../ADMIN/usuarios.php");
    exit;
}

require_once "conexion.php";

$id          = (int)$_POST["id"];
$coins       = floatval($_POST["coins"]);
$points      = floatval($_POST["points"]);
$coins_ps    = floatval($_POST["coins_por_seg"]);
$points_ps   = floatval($_POST["points_por_seg"]);

// 27/04 v3: campo suerte eliminado
$stmt = $conexion->prepare("
    UPDATE jugadores
    SET coins = ?, points = ?, coins_por_seg = ?, points_por_seg = ?
    WHERE usuario_id = ?
");
$stmt->bind_param("ddddi", $coins, $points, $coins_ps, $points_ps, $id);
$stmt->execute();
$stmt->close();
$conexion->close();

header("Location: ../ADMIN/usuarios.php");
exit;
