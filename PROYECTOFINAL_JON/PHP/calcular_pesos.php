<?php
// ============================================================
// calcular_pesos.php — Función compartida para la curva campana
// Incluir con: require_once "calcular_pesos.php";
// ============================================================

/**
 * Calcula el peso efectivo de una rareza dado el nivel de suerte
 * usando una curva en forma de campana.
 *
 * Fórmula:
 *   - Si suerte <= suerte_pico:
 *       peso = peso_base + (peso_pico - peso_base) * (suerte - 1) / (suerte_pico - 1)
 *       (sube linealmente de peso_base a peso_pico)
 *       Caso especial: si suerte_pico == 1, peso = peso_pico siempre
 *
 *   - Si suerte > suerte_pico:
 *       peso = peso_pico * (1 - (suerte - suerte_pico) / (suerte_cero - suerte_pico))
 *       (baja linealmente de peso_pico a 0)
 *
 *   - Si peso < 0: peso = 0 (la rareza no puede salir)
 *
 * @param float $suerte      Nivel de suerte actual del jugador (ej: 1.5, 2.0, 10.0)
 * @param float $peso_base   Peso con suerte x1
 * @param float $suerte_pico Suerte donde llega al máximo
 * @param float $peso_pico   Peso máximo
 * @param float $suerte_cero Suerte donde el peso llega a 0
 * @return float             Peso efectivo (>= 0)
 */
function calcularPesoCampana(float $suerte, float $peso_base, float $suerte_pico, float $peso_pico, float $suerte_cero): float {
    if ($suerte <= $suerte_pico) {
        // Subida: de peso_base a peso_pico
        if ($suerte_pico <= 1.0) {
            return $peso_pico; // ya está en el pico desde el inicio
        }
        $t = ($suerte - 1.0) / ($suerte_pico - 1.0);
        $t = max(0.0, min(1.0, $t));
        return $peso_base + ($peso_pico - $peso_base) * $t;
    } else {
        // Bajada: de peso_pico a 0
        $t = ($suerte - $suerte_pico) / ($suerte_cero - $suerte_pico);
        $t = max(0.0, min(1.0, $t));
        return $peso_pico * (1.0 - $t);
    }
}

/**
 * Dado un nivel de suerte y la tabla rareza_curva de la BD,
 * devuelve un array [rareza => peso_efectivo] y el peso total.
 *
 * @param float  $suerte   Suerte del jugador
 * @param mysqli $conexion Conexión activa
 * @return array           ["pesos" => [...], "total" => float, "curvas" => [...]]
 */
function calcularPesosPorSuerte(float $suerte, $conexion): array {
    $stmt = $conexion->prepare("SELECT * FROM rareza_curva");
    $stmt->execute();
    $curvas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $pesos = [];
    $total = 0.0;

    foreach ($curvas as $c) {
        $peso = calcularPesoCampana(
            $suerte,
            floatval($c["peso_base"]),
            floatval($c["suerte_pico"]),
            floatval($c["peso_pico"]),
            floatval($c["suerte_cero"])
        );
        $pesos[$c["rareza"]] = $peso;
        $total += $peso;
    }

    return ["pesos" => $pesos, "total" => $total, "curvas" => $curvas];
}
