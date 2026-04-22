<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../registro.php");
    exit;
}

require_once "conexion.php";

// Recoger y limpiar datos
$username  = trim(strip_tags($_POST["username"]));
$email     = trim(strip_tags($_POST["email"]));
$password  = trim($_POST["password"]);
$password2 = trim($_POST["password2"]);
$fecha_nac = trim($_POST["fecha_nac"]);
$genero    = trim($_POST["genero"]);

$errores = [];

// Validaciones
if ($username === "") {
    $errores["username"] = "El nombre de usuario no puede estar vacío.";
} elseif (strlen($username) < 3) {
    $errores["username"] = "El nombre de usuario debe tener al menos 3 caracteres.";
}

if ($email === "") {
    $errores["email"] = "El correo no puede estar vacío.";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores["email"] = "El correo no tiene un formato válido.";
}

if ($password === "") {
    $errores["password"] = "La contraseña no puede estar vacía.";
} elseif (strlen($password) < 4) {
    $errores["password"] = "La contraseña debe tener al menos 4 caracteres.";
}

if ($password !== $password2) {
    $errores["password2"] = "Las contraseñas no coinciden.";
}

if ($fecha_nac === "") {
    $errores["fecha_nac"] = "La fecha de nacimiento es obligatoria.";
}

if (!in_array($genero, ["masculino", "femenino", "otro"])) {
    $errores["genero"] = "Selecciona un género válido.";
}

// Si hay errores, volver al formulario
if (!empty($errores)) {
    $_SESSION["errores"] = $errores;
    header("Location: ../registro.php");
    exit;
}

// Comprobar si el username ya existe
$stmt = $conexion->prepare("SELECT id FROM usuarios WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $_SESSION["errores"] = ["username" => "Este nombre de usuario ya está en uso."];
    header("Location: ../registro.php");
    exit;
}
$stmt->close();

// Comprobar si el email ya existe
$stmt = $conexion->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $_SESSION["errores"] = ["email" => "Este correo ya está registrado."];
    header("Location: ../registro.php");
    exit;
}
$stmt->close();

// Hashear contraseña e insertar usuario
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conexion->prepare("INSERT INTO usuarios (username, email, password, fecha_nac, genero) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $username, $email, $hash, $fecha_nac, $genero);

if ($stmt->execute()) {
    $nuevo_id = $conexion->insert_id;
    $stmt->close();

    // Crear fila de jugador automáticamente
    $stmt2 = $conexion->prepare("
        INSERT INTO jugadores (usuario_id, coins, points, coins_por_seg, points_por_seg,
                               coins_ps_max, points_ps_max, suerte, bulk_total, display_mode)
        VALUES (?, 10, 0, 1, 0, 1, 0, 1.00, 1, 'porcentaje')
    ");
    $stmt2->bind_param("i", $nuevo_id);
    $stmt2->execute();
    $stmt2->close();

    // Iniciar sesión automáticamente tras registro
    $_SESSION["idUsuario"] = $nuevo_id;
    $_SESSION["username"]  = $username;
    $_SESSION["rol"]       = "usuario";

    $conexion->close();
    header("Location: ../juego.php");
    exit;
} else {
    $_SESSION["errores"] = ["username" => "Error al registrar. Inténtalo de nuevo."];
    header("Location: ../registro.php");
    exit;
}