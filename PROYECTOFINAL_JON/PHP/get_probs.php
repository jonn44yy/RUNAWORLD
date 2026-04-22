<?php
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false]);
    exit;
}

require_once "conexion.php";
require_once "calcular_pesos.php";

$datos  = json_decode(file_get_contents("php://input"), true);
$suerte = floatval($datos["suerte"] ?? 1.0);

$stmt = $conexion->prepare("SELECT id, rareza, peso FROM runas WHERE activa = 1");
$stmt->execute();
$runas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$campana_base   = calcularPesosPorSuerte(1.0,    $conexion);
$campana_suerte = calcularPesosPorSuerte($suerte, $conexion);

$peso_base_total     = $campana_base["total"];
$peso_efectivo_total = $campana_suerte["total"];
$conexion->close();

$prob_map = [];
foreach ($runas as $r) {
    $rareza = $r["rareza"];
    $pb = $peso_base_total   > 0 ? ($campana_base["pesos"][$rareza]   ?? 0) / $peso_base_total   * 100 : 0;
    $ps = $peso_efectivo_total > 0 ? ($campana_suerte["pesos"][$rareza] ?? 0) / $peso_efectivo_total * 100 : 0;
    $prob_map[$r["id"]] = ["base" => $pb, "suerte" => $ps, "peso" => $r["peso"]];
}

echo json_encode(["ok" => true, "prob_map" => $prob_map]);
