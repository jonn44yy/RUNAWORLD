<?php
session_start();

// Si no viene del formulario, redirigir
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../index.php");
    exit;
}

require_once "conexion.php";

$username = trim(strip_tags($_POST["username"]));
$password = trim($_POST["password"]);

// Validación básica
if ($username === "" || $password === "") {
    $_SESSION["error"] = "Rellena todos los campos.";
    header("Location: ../index.php");
    exit;
}

// Buscar usuario en la BD (prepared statement, seguro contra SQL injection)
$stmt = $conexion->prepare("SELECT id, username, password, rol FROM usuarios WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    $_SESSION["error"] = "Usuario o contraseña incorrectos.";
    header("Location: ../index.php");
    exit;
}

$fila = $resultado->fetch_assoc();

// Verificar contraseña hasheada
if (!password_verify($password, $fila["password"])) {
    $_SESSION["error"] = "Usuario o contraseña incorrectos.";
    header("Location: ../index.php");
    exit;
}

// Login correcto, guardar sesión
$_SESSION["idUsuario"] = $fila["id"];
$_SESSION["username"]  = $fila["username"];
$_SESSION["rol"]       = $fila["rol"];

$stmt->close();
$conexion->close();

// Redirigir según rol
if ($fila["rol"] === "admin") {
    header("Location: ../ADMIN/index.php");
} else {
    header("Location: ../juego.php");
}
exit;