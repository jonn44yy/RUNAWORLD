<?php
require_once __DIR__ . "/PHP/conexion.php";

$sql = "
DELETE FROM packs_tiradas
WHERE 
    (estado = 'consumido' AND confirmado_en < NOW() - INTERVAL 1 HOUR)
    OR
    (estado = 'parcial' AND creado_en < NOW() - INTERVAL 1 DAY)
    OR
    ((estado IS NULL OR estado = 'pendiente') AND creado_en < NOW() - INTERVAL 1 HOUR)
";

if (!$conexion->query($sql)) {
    error_log("Error limpiando packs_tiradas: " . $conexion->error);
}