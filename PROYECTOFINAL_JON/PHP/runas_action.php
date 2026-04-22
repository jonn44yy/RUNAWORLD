<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "conexion.php";

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";
// Cargar rarezas válidas desde BD
$stmt_r = $conexion->prepare("SELECT slug, orden FROM rarezas WHERE activa = 1");
$stmt_r->execute();
$rarezas_rows = $stmt_r->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_r->close();
$rarezas_validas = array_column($rarezas_rows, 'slug');
$rarezas_orden   = array_column($rarezas_rows, 'orden', 'slug');

if ($accion === "crear") {
    $grupo_id      = (int)$_POST["grupo_id"];
    $nombre        = trim(strip_tags($_POST["nombre"]));
    $rareza        = trim($_POST["rareza"]);
    $peso          = (int)$_POST["peso"];
    $multiplicador = floatval($_POST["multiplicador"]);
    $activa        = (int)$_POST["activa"];

    $errores = [];
    if ($grupo_id <= 0)                          $errores[] = "Selecciona una lista.";
    if ($nombre === "")                          $errores[] = "El nombre no puede estar vacio.";
    if (!in_array($rareza, $rarezas_validas))    $errores[] = "Rareza no valida.";
    if ($peso <= 0)                              $errores[] = "El peso debe ser mayor que 0.";
    if ($multiplicador < 0)                      $errores[] = "El multiplicador no puede ser negativo.";

    if (!empty($errores)) {
        $_SESSION["errores"] = $errores;
        header("Location: ../ADMIN/crear_runa.php?grupo_id=$grupo_id");
        exit;
    }

    $tier_crear = $rarezas_orden[$rareza] ?? 1;
    $stmt = $conexion->prepare("INSERT INTO runas (nombre, rareza, peso, multiplicador, activa, grupo_id, tier) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssidiii", $nombre, $rareza, $peso, $multiplicador, $activa, $grupo_id, $tier_crear);
    $stmt->execute();
    $stmt->close();

    header("Location: ../ADMIN/runas.php");
    exit;

} elseif ($accion === "editar") {
    $id            = (int)$_POST["id"];
    $grupo_id      = $_POST["grupo_id"] !== "" ? (int)$_POST["grupo_id"] : null;
    $nombre        = trim(strip_tags($_POST["nombre"]));
    $rareza        = trim($_POST["rareza"]);
    $peso          = (int)$_POST["peso"];
    $multiplicador = floatval($_POST["multiplicador"]);
    $activa        = (int)$_POST["activa"];

    $errores = [];
    if ($nombre === "")                          $errores[] = "El nombre no puede estar vacio.";
    if (!in_array($rareza, $rarezas_validas))    $errores[] = "Rareza no valida.";
    if ($peso <= 0)                              $errores[] = "El peso debe ser mayor que 0.";
    if ($multiplicador < 0)                      $errores[] = "El multiplicador no puede ser negativo.";

    if (!empty($errores)) {
        $_SESSION["errores"] = $errores;
        header("Location: ../ADMIN/editar_runa.php?id=$id");
        exit;
    }

    $tier = $rarezas_orden[$rareza] ?? 1;
    $stmt = $conexion->prepare("UPDATE runas SET nombre=?, rareza=?, peso=?, multiplicador=?, activa=?, grupo_id=?, tier=? WHERE id=?");
    $stmt->bind_param("ssidiiii", $nombre, $rareza, $peso, $multiplicador, $activa, $grupo_id, $tier, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: ../ADMIN/runas.php");
    exit;

} elseif ($accion === "eliminar") {
    $id = (int)$_GET["id"];
    if ($id <= 0) die("ID invalido");

    $stmt = $conexion->prepare("DELETE FROM jugador_runas WHERE runa_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conexion->prepare("DELETE FROM runas WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: ../ADMIN/runas.php");
    exit;
}

$conexion->close();
header("Location: ../ADMIN/runas.php");
exit;
