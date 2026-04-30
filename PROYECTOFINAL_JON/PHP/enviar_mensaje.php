<?php
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";

$id_usuario = $_SESSION["idUsuario"];

// Comprobar limite de 30 minutos
$stmt = $conexion->prepare("SELECT fecha_ultimo_mensaje FROM jugadores WHERE usuario_id = ?");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$jugador = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($jugador["fecha_ultimo_mensaje"] !== null) {
    $ultimo  = strtotime($jugador["fecha_ultimo_mensaje"]);
    $ahora   = time();
    $espera  = 30 * 60; // 30 minutos en segundos
    if (($ahora - $ultimo) < $espera) {
        $minutos_restantes = ceil(($espera - ($ahora - $ultimo)) / 60);
        echo json_encode(["ok" => false, "error" => "Debes esperar " . $minutos_restantes . " minuto/s antes de enviar otro mensaje."]);
        exit;
    }
}

// Recoger datos del formulario
$tipo     = trim($_POST["tipo"]     ?? "");
$asunto   = trim($_POST["asunto"]   ?? "");
$contenido = trim($_POST["contenido"] ?? "");

// Validaciones
$tipos_validos = ["ideas", "errores", "error_datos", "no_importante"];
if (!in_array($tipo, $tipos_validos)) {
    echo json_encode(["ok" => false, "error" => "Selecciona un tipo de mensaje valido."]);
    exit;
}
if ($asunto === "") {
    echo json_encode(["ok" => false, "error" => "El asunto no puede estar vacio."]);
    exit;
}
if ($contenido === "") {
    echo json_encode(["ok" => false, "error" => "El mensaje no puede estar vacio."]);
    exit;
}
if (strlen($contenido) > 500) {
    echo json_encode(["ok" => false, "error" => "El mensaje no puede superar los 500 caracteres."]);
    exit;
}

// Gestionar archivo adjunto si lo hay
$archivo_ruta = null;
if (isset($_FILES["archivo"]) && $_FILES["archivo"]["error"] === UPLOAD_ERR_OK) {
    $archivo    = $_FILES["archivo"];
    $tipos_img  = ["image/jpeg", "image/png", "image/webp"];
    $max_size   = 5 * 1024 * 1024; // 5MB

    if (!in_array($archivo["type"], $tipos_img)) {
        echo json_encode(["ok" => false, "error" => "Solo se permiten imagenes JPG, PNG o WEBP."]);
        exit;
    }
    if ($archivo["size"] > $max_size) {
        echo json_encode(["ok" => false, "error" => "La imagen no puede superar los 5MB."]);
        exit;
    }

    // Nombre unico para evitar conflictos
    $extension   = pathinfo($archivo["name"], PATHINFO_EXTENSION);
    $nombre_file = "ticket_" . $id_usuario . "_" . time() . "." . $extension;
    $destino     = "IMG/tickets/" . $nombre_file;

    if (!move_uploaded_file($archivo["tmp_name"], $destino)) {
        echo json_encode(["ok" => false, "error" => "Error al subir el archivo."]);
        exit;
    }

    $archivo_ruta = "IMG/tickets/" . $nombre_file;
}

// Insertar mensaje en BD
$stmt = $conexion->prepare("INSERT INTO mensajes (usuario_id, tipo, asunto, contenido, archivo) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("issss", $id_usuario, $tipo, $asunto, $contenido, $archivo_ruta);
$stmt->execute();
$stmt->close();

// Actualizar fecha ultimo mensaje del jugador
$stmt = $conexion->prepare("UPDATE jugadores SET fecha_ultimo_mensaje = NOW() WHERE usuario_id = ?");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$stmt->close();

$conexion->close();

echo json_encode(["ok" => true]);
