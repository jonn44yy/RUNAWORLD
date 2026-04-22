<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "conexion.php";

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";

if ($accion === "crear") {
    $nombre = trim(strip_tags($_POST["nombre"]));

    if ($nombre === "") {
        $_SESSION["errores"] = ["El nombre no puede estar vacio."];
        header("Location: ../ADMIN/gestionar_grupos.php");
        exit;
    }

    // Comprobar que no existe ya
    $stmt = $conexion->prepare("SELECT id FROM grupos_runas WHERE nombre = ?");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $_SESSION["errores"] = ["Ya existe una lista con ese nombre."];
        $stmt->close();
        header("Location: ../ADMIN/gestionar_grupos.php");
        exit;
    }
    $stmt->close();

    $stmt = $conexion->prepare("INSERT INTO grupos_runas (nombre) VALUES (?)");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $stmt->close();

    header("Location: ../ADMIN/gestionar_grupos.php");
    exit;

} elseif ($accion === "eliminar") {
    $id = (int)$_GET["id"];
    if ($id <= 0) die("ID invalido");

    // Las runas quedan con grupo_id = NULL (ON DELETE SET NULL en la FK)
    $stmt = $conexion->prepare("DELETE FROM grupos_runas WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: ../ADMIN/gestionar_grupos.php");
    exit;
}

$conexion->close();
header("Location: ../ADMIN/gestionar_grupos.php");
exit;
