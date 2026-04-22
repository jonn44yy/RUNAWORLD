<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "conexion.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) die("ID invalido");

if ($id === (int)$_SESSION["idUsuario"]) {
    header("Location: ../ADMIN/usuarios.php");
    exit;
}

$stmt = $conexion->prepare("DELETE FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();
$conexion->close();

header("Location: ../ADMIN/usuarios.php");
exit;
