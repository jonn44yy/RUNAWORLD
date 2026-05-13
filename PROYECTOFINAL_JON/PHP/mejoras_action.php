<?php
session_start();
if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit;
}
require_once "conexion.php";

function parse_num_admin($v) {
    $v = trim(str_replace(',', '.', (string)$v));
    if ($v === '') return 0.0;
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)(k|m|b|t)$/i', $v, $m)) {
        $n = (float)$m[1];
        $s = strtolower($m[2]);
        if ($s === 'k') $n *= 1000;
        if ($s === 'm') $n *= 1000000;
        if ($s === 'b') $n *= 1000000000;
        if ($s === 't') $n *= 1000000000000;
        return $n;
    }
    return (float)$v;
}

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";
$tipos_validos = [
    "coins_seg", "coins_seg_multi", "coins_seg_multi_eterno",
    "points_seg", "points_seg_multi", "points_seg_multi_eterno",
    "bulk", "bulk_normal", "bulk_extra",
    "desbloquear_boost_leg", "desbloquear_boost_div", "suerte"
];
$condiciones_validas = ["ninguna", "tirar_runa_x", "clickar_boost_x", "comprar_mejora_id"];

if ($accion === "crear" || $accion === "editar") {
    $nombre = trim(strip_tags($_POST["nombre"] ?? ""));
    $tipo = trim($_POST["tipo"] ?? "");
    $coste_base = parse_num_admin($_POST["coste_base"] ?? 0);
    $coste_escala = parse_num_admin($_POST["coste_escala"] ?? 1);
    $valor = parse_num_admin($_POST["valor"] ?? 0);
    $nivel_maximo = (int)($_POST["nivel_maximo"] ?? 0);
    $condicion_tipo = trim($_POST["condicion_tipo"] ?? "ninguna");
    $condicion_valor = trim(strip_tags($_POST["condicion_valor"] ?? ""));
    $orden = (int)($_POST["orden"] ?? 0);
    $descripcion = trim(strip_tags($_POST["descripcion"] ?? ""));
    $activa = (int)($_POST["activa"] ?? 0);

    $errores = [];
    if ($nombre === "") $errores[] = "El nombre no puede estar vacio.";
    if (!in_array($tipo, $tipos_validos, true)) $errores[] = "Tipo no valido.";
    if ($coste_base <= 0) $errores[] = "El coste base debe ser mayor que 0.";
    if ($coste_escala < 1) $errores[] = "La escala debe ser mayor o igual a 1.";
    if ($valor < 0) $errores[] = "El valor no puede ser negativo.";
    if ($nivel_maximo < 0) $errores[] = "El nivel maximo no puede ser negativo.";
    if (!in_array($condicion_tipo, $condiciones_validas, true)) $errores[] = "Condicion de desbloqueo no valida.";
    if ($condicion_tipo === "ninguna") $condicion_valor = null;

    if (!empty($errores)) {
        $_SESSION["errores"] = $errores;
        if ($accion === "crear") header("Location: ../ADMIN/crear_mejora.php");
        else header("Location: ../ADMIN/editar_mejora.php?id=" . (int)($_POST["id"] ?? 0));
        exit;
    }

    if ($accion === "crear") {
        $stmt = $conexion->prepare("INSERT INTO mejoras (nombre, tipo, coste_base, coste_escala, valor, nivel_maximo, condicion_tipo, condicion_valor, orden, descripcion, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdddissisi", $nombre, $tipo, $coste_base, $coste_escala, $valor, $nivel_maximo, $condicion_tipo, $condicion_valor, $orden, $descripcion, $activa);
    } else {
        $id = (int)($_POST["id"] ?? 0);
        $stmt = $conexion->prepare("UPDATE mejoras SET nombre=?, tipo=?, coste_base=?, coste_escala=?, valor=?, nivel_maximo=?, condicion_tipo=?, condicion_valor=?, orden=?, descripcion=?, activa=? WHERE id=?");
        $stmt->bind_param("ssdddissisii", $nombre, $tipo, $coste_base, $coste_escala, $valor, $nivel_maximo, $condicion_tipo, $condicion_valor, $orden, $descripcion, $activa, $id);
    }
    $stmt->execute();
    $stmt->close();
} elseif ($accion === "desactivar" || $accion === "activar" || $accion === "eliminar") {
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) die("ID invalido");
    $activa = ($accion === "activar") ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE mejoras SET activa = ? WHERE id = ?");
    $stmt->bind_param("ii", $activa, $id);
    $stmt->execute();
    $stmt->close();
}
$conexion->close();
header("Location: ../ADMIN/tienda.php");
exit;
