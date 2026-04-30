<?php
// 28/04 v3.1: ahora el usuario puede iniciar sesion tanto con su username
// como con su email. detectamos cual es por la presencia del @.
// el campo del formulario sigue llamandose "username" para no romper el HTML.
//
// 28/04 v3.2: si es email, se normaliza a minusculas. los emails son
// case-insensitive por estandar y queremos que "[email protected]" y
// "[email protected]" caigan en el mismo usuario aunque la columna en BD
// tenga collation case-sensitive.

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
// si es email lo pasamos a minusculas (los emails son case-insensitive)
$es_email = strpos($identificador, "@") !== false;
$columna  = $es_email ? "email" : "username";
if ($es_email) {
    $identificador = strtolower($identificador);
}

// Buscar usuario en la BD por la columna correspondiente.
// LOWER() en la query nos protege aunque la columna tenga collation
// case-sensitive (que no deberia, pero por si acaso). Para username
// no lo hacemos: dejamos que la collation de la columna decida.
$sql = $es_email
    ? "SELECT id, username, password, rol FROM usuarios WHERE LOWER(email) = ?"
    : "SELECT id, username, password, rol FROM usuarios WHERE username = ?";
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
