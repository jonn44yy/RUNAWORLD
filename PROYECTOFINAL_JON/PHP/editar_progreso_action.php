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

// 29/04: si admin edita produccion manualmente, la guardamos tambien como
// configuracion/cap para que no se pierda en la siguiente tirada/autosave.
$stmt = $conexion->prepare("
    UPDATE jugadores
    SET coins = ?, points = ?,
        coins_por_seg = ?, points_por_seg = ?,
        coins_ps_config = ?, points_ps_config = ?,
        coins_ps_max = GREATEST(coins_ps_max, ?),
        points_ps_max = GREATEST(points_ps_max, ?)
    WHERE usuario_id = ?
");
$stmt->bind_param("ddddddddi", $coins, $points, $coins_ps, $points_ps, $coins_ps, $points_ps, $coins_ps, $points_ps, $id);
$stmt->execute();
$stmt->close();
$conexion->close();

header("Location: ../ADMIN/usuarios.php");
exit;
