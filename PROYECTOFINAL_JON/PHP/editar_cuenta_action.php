<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? '') !== "admin") {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../ADMIN/usuarios.php");
    exit;
}

require_once "conexion.php";

function columnExists($conexion, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conexion->real_escape_string($column);
    $res = $conexion->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

$id = isset($_POST["id"]) ? (int)$_POST["id"] : 0;
$username = trim(strip_tags($_POST["username"] ?? ''));
$email = trim(strip_tags($_POST["email"] ?? ''));
$genero = trim($_POST["genero"] ?? 'otro');
$rol = trim($_POST["rol"] ?? 'usuario');
$password = trim($_POST["password"] ?? '');

$errores = [];

if ($id <= 0) {
    $errores[] = "ID de usuario inválido.";
}
if ($username === "" || strlen($username) < 3) {
    $errores[] = "El nombre de usuario debe tener al menos 3 caracteres.";
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores[] = "El email no tiene un formato válido.";
}
if (!in_array($genero, ["masculino", "femenino", "otro"], true)) {
    $errores[] = "Género no válido.";
}
if (!in_array($rol, ["usuario", "admin"], true)) {
    $errores[] = "Rol no válido.";
}
if ($id === (int)$_SESSION['idUsuario'] && $rol !== 'admin') {
    $errores[] = "No puedes quitarte el rol admin a ti mismo.";
}
if ($password !== "" && strlen($password) < 6) {
    $errores[] = "La nueva contraseña debe tener al menos 6 caracteres.";
}

if ($id > 0) {
    $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE username = ? AND id <> ? LIMIT 1");
    $stmt->bind_param("si", $username, $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $errores[] = "Ya existe otro usuario con ese nombre.";
    }
    $stmt->close();

    $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1");
    $stmt->bind_param("si", $email, $id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $errores[] = "Ya existe otro usuario con ese email.";
    }
    $stmt->close();
}

if (!empty($errores)) {
    $_SESSION["errores"] = $errores;
    $conexion->close();
    header("Location: ../ADMIN/editar_cuenta.php?id=$id");
    exit;
}

$fields = ["username = ?", "email = ?"];
$types = "ss";
$values = [$username, $email];

if (columnExists($conexion, 'usuarios', 'genero')) {
    $fields[] = "genero = ?";
    $types .= "s";
    $values[] = $genero;
}

if (columnExists($conexion, 'usuarios', 'rol')) {
    $fields[] = "rol = ?";
    $types .= "s";
    $values[] = $rol;
}

if ($password !== "") {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $fields[] = "password = ?";
    $types .= "s";
    $values[] = $hash;
}

$types .= "i";
$values[] = $id;

$sql = "UPDATE usuarios SET " . implode(', ', $fields) . " WHERE id = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param($types, ...$values);
$stmt->execute();
$stmt->close();
$conexion->close();

$_SESSION["ok"] = "Cuenta actualizada correctamente.";
header("Location: ../ADMIN/editar_cuenta.php?id=$id");
exit;