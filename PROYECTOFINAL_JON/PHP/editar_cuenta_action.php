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

$id       = (int)$_POST["id"];
$username = trim(strip_tags($_POST["username"]));
$email    = trim(strip_tags($_POST["email"]));
$genero   = trim($_POST["genero"]);
$password = trim($_POST["password"]);

$errores = [];

if ($username === "" || strlen($username) < 3) {
    $errores[] = "El nombre de usuario debe tener al menos 3 caracteres.";
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores[] = "El email no tiene un formato valido.";
}
if (!in_array($genero, ["masculino", "femenino", "otro"])) {
    $errores[] = "Genero no valido.";
}

if (!empty($errores)) {
    $_SESSION["errores"] = $errores;
    header("Location: ../ADMIN/editar_cuenta.php?id=$id");
    exit;
}

if ($password !== "") {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conexion->prepare("UPDATE usuarios SET username = ?, email = ?, genero = ?, password = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $username, $email, $genero, $hash, $id);
} else {
    $stmt = $conexion->prepare("UPDATE usuarios SET username = ?, email = ?, genero = ? WHERE id = ?");
    $stmt->bind_param("sssi", $username, $email, $genero, $id);
}

$stmt->execute();
$stmt->close();
$conexion->close();

header("Location: ../ADMIN/usuarios.php");
exit;
