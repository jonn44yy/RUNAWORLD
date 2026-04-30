<?php
// 28/04 v3.1: ahora el usuario puede iniciar sesion tanto con su username
// como con su email. detectamos cual es por la presencia del @.
// el campo del formulario sigue llamandose "username" para no romper el HTML.

session_start();

// Si no viene del formulario, redirigir
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../index.php");
    exit;
}

require_once "conexion.php";

$identificador = trim(strip_tags($_POST["username"]));
$password      = trim($_POST["password"]);

// Validacion basica
if ($identificador === "" || $password === "") {
    $_SESSION["error"] = "Rellena todos los campos.";
    header("Location: ../index.php");
    exit;
}

// Detectar si es email o username segun contenga @.
// no validamos formato estricto del email aqui: si es invalido la query no
// devuelve nada y cae en el "usuario o contrasena incorrectos" generico,
// lo cual es mejor por seguridad (no damos pistas al atacante)
$es_email = strpos($identificador, "@") !== false;
$columna  = $es_email ? "email" : "username";

// Buscar usuario en la BD por la columna correspondiente
// (prepared statement, seguro contra SQL injection)
$sql  = "SELECT id, username, password, rol FROM usuarios WHERE $columna = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("s", $identificador);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    $_SESSION["error"] = "Usuario o contraseña incorrectos.";
    header("Location: ../index.php");
    exit;
}

$fila = $resultado->fetch_assoc();

// Verificar contrasena hasheada
if (!password_verify($password, $fila["password"])) {
    $_SESSION["error"] = "Usuario o contraseña incorrectos.";
    header("Location: ../index.php");
    exit;
}

// Login correcto, guardar sesion
$_SESSION["idUsuario"] = $fila["id"];
$_SESSION["username"]  = $fila["username"];
$_SESSION["rol"]       = $fila["rol"];

$stmt->close();
$conexion->close();

// Redirigir segun rol
if ($fila["rol"] === "admin") {
    header("Location: ../ADMIN/index.php");
} else {
    header("Location: ../juego.php");
}
exit;
