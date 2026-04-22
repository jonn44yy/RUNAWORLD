<?php
// debug_forzar_runa.php — BORRAR antes de entregar!
// rellena la cola $_SESSION["debug_forzadas"] con rarezas que saldran en las
// proximas tiradas (en orden). no toca BD, solo sesion. al cerrar sesion se
// pierde todo, que es lo que queremos para debug
//
// uso desde consola del navegador:
//   fetch("PHP/debug_forzar_runa.php?rareza=eterna&cantidad=1")
//     .then(r => r.json()).then(d => console.log(d));
//
// o con reset: fetch("PHP/debug_forzar_runa.php?reset=1")

session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No logueado"]);
    exit;
}

// reset: vaciar la cola
if (isset($_GET["reset"])) {
    $_SESSION["debug_forzadas"] = [];
    echo json_encode(["ok" => true, "mensaje" => "Cola vaciada", "pendientes" => 0]);
    exit;
}

$rareza   = $_GET["rareza"]   ?? null;
$cantidad = (int)($_GET["cantidad"] ?? 1);

// validar rareza contra la lista que uso en el juego
$validas = ["eterna", "divina", "mitica", "legendaria", "epica", "rara", "poco_comun", "comun"];
if (!$rareza || !in_array($rareza, $validas)) {
    echo json_encode([
        "ok"    => false,
        "error" => "rareza invalida. validas: " . implode(", ", $validas)
    ]);
    exit;
}

if ($cantidad < 1 || $cantidad > 999) {
    echo json_encode(["ok" => false, "error" => "cantidad entre 1 y 999"]);
    exit;
}

// anadir a la cola (array de strings). el tirar_runa.php las va consumiendo
if (!isset($_SESSION["debug_forzadas"])) $_SESSION["debug_forzadas"] = [];
for ($i = 0; $i < $cantidad; $i++) {
    $_SESSION["debug_forzadas"][] = $rareza;
}

echo json_encode([
    "ok"         => true,
    "mensaje"    => "Anadidas {$cantidad} runas {$rareza} a la cola",
    "pendientes" => count($_SESSION["debug_forzadas"])
]);
