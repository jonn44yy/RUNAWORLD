<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "conexion.php";

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";
$tipos_validos = ["coins_seg","coins_seg_multi","points_seg","points_seg_multi","suerte","bulk"];

if ($accion === "crear" || $accion === "editar") {
    $nombre        = trim(strip_tags($_POST["nombre"]));
    $tipo          = trim($_POST["tipo"]);
    $coste_base    = floatval($_POST["coste_base"]);
    $coste_escala  = floatval($_POST["coste_escala"]);
    $valor         = floatval($_POST["valor"]);
    $nivel_maximo  = (int)$_POST["nivel_maximo"];
    $descripcion   = trim(strip_tags($_POST["descripcion"] ?? ""));
    $activa        = (int)$_POST["activa"];

    $errores = [];
    if ($nombre === "")                        $errores[] = "El nombre no puede estar vacio.";
    if (!in_array($tipo, $tipos_validos))      $errores[] = "Tipo no valido.";
    if ($coste_base <= 0)                      $errores[] = "El coste base debe ser mayor que 0.";
    if ($coste_escala < 1)                     $errores[] = "La escala debe ser mayor o igual a 1.";
    if ($nivel_maximo < 1)                     $errores[] = "El nivel maximo debe ser al menos 1.";

    if (!empty($errores)) {
        $_SESSION["errores"] = $errores;
        if ($accion === "crear") {
            header("Location: ../ADMIN/crear_mejora.php");
        } else {
            $id = (int)$_POST["id"];
            header("Location: ../ADMIN/editar_mejora.php?id=$id");
        }
        exit;
    }

    if ($accion === "crear") {
        $stmt = $conexion->prepare("INSERT INTO mejoras (nombre, tipo, coste_base, coste_escala, valor, nivel_maximo, descripcion, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdddisi", $nombre, $tipo, $coste_base, $coste_escala, $valor, $nivel_maximo, $descripcion, $activa);
    } else {
        $id = (int)$_POST["id"];
        $stmt = $conexion->prepare("UPDATE mejoras SET nombre = ?, tipo = ?, coste_base = ?, coste_escala = ?, valor = ?, nivel_maximo = ?, descripcion = ?, activa = ? WHERE id = ?");
        $stmt->bind_param("ssdddisii", $nombre, $tipo, $coste_base, $coste_escala, $valor, $nivel_maximo, $descripcion, $activa, $id);
    }

    $stmt->execute();
    $stmt->close();

} elseif ($accion === "eliminar") {
    $id = (int)$_GET["id"];
    if ($id <= 0) die("ID invalido");

    $stmt = $conexion->prepare("DELETE FROM jugador_mejoras WHERE mejora_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conexion->prepare("DELETE FROM mejoras WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

$conexion->close();
header("Location: ../ADMIN/tienda.php");
exit;
