<?php
// runas_action.php — Manejo de Runas
// Filosofia: el admin registra/gestiona catalogo y balance, no dibuja la runa.
// La columna `imagen` se usa como archivo HTML de la runa en RUNAS_HTML/RUNAS/.

session_start();

if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once __DIR__ . "/conexion.php";

const FACTOR_CORRUPTA = 100;
const FACTOR_SUPREMA  = 100000;

const DIR_RUNAS      = __DIR__ . "/../RUNAS_HTML/RUNAS/";
const DIR_MARCOS     = __DIR__ . "/../RUNAS_HTML/MARCOS/";
const DIR_BACKGROUNDS= __DIR__ . "/../RUNAS_HTML/BACKGROUND/";

$accion = $_POST["accion"] ?? $_GET["accion"] ?? "";

function redirectRunas(string $qs = ""): never {
    $url = "../ADMIN/runas.php" . ($qs !== "" ? (str_starts_with($qs, "?") ? $qs : "?" . $qs) : "");
    header("Location: " . $url);
    exit;
}

function guardarErrores(array $errores, string $url): never {
    $_SESSION["errores"] = $errores;
    header("Location: " . $url);
    exit;
}

function columnExists(mysqli $conexion, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conexion->real_escape_string($column);
    $res = $conexion->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function limpiarTexto(string $txt): string {
    return trim(strip_tags($txt));
}

function slugArchivo(string $nombre): string {
    $nombre = strtolower(trim($nombre));
    $nombre = str_replace(['á','é','í','ó','ú','ñ','ü'], ['a','e','i','o','u','n','u'], $nombre);
    $nombre = preg_replace('/[^a-z0-9._-]+/', '_', $nombre);
    $nombre = preg_replace('/_+/', '_', $nombre);
    return trim($nombre, '._-');
}

function archivoSeguro(?string $nombre): string {
    $nombre = slugArchivo((string)$nombre);
    return basename($nombre);
}

function extensionPermitida(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ["html", "htm", "svg"], true);
}

function subirArchivo(string $field, string $destino, string $nombrePreferido = ""): array {
    if (!isset($_FILES[$field]) || ($_FILES[$field]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ["ok" => true, "archivo" => ""];
    }

    if ($_FILES[$field]["error"] !== UPLOAD_ERR_OK) {
        return ["ok" => false, "error" => "Error subiendo el archivo $field."];
    }

    $original = archivoSeguro($_FILES[$field]["name"] ?? "");
    if ($original === "" || !extensionPermitida($original)) {
        return ["ok" => false, "error" => "Archivo no valido en $field. Solo se permite html, htm o svg."];
    }

    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $final = $nombrePreferido !== "" ? archivoSeguro($nombrePreferido) : $original;
    if (pathinfo($final, PATHINFO_EXTENSION) === "") {
        $final .= "." . $ext;
    }
    if (!extensionPermitida($final)) {
        return ["ok" => false, "error" => "Nombre final no valido para $field."];
    }

    if (!is_dir($destino)) {
        @mkdir($destino, 0755, true);
    }

    $rutaFinal = rtrim($destino, "/") . "/" . $final;
    if (!move_uploaded_file($_FILES[$field]["tmp_name"], $rutaFinal)) {
        return ["ok" => false, "error" => "No se pudo guardar el archivo $final."];
    }

    return ["ok" => true, "archivo" => $final];
}

function factorVariante(string $variante): int {
    return match ($variante) {
        "corrupta" => FACTOR_CORRUPTA,
        "suprema"  => FACTOR_SUPREMA,
        default    => 1,
    };
}

function baseTierVariante(string $variante): int {
    return match ($variante) {
        "corrupta" => 100,
        "suprema"  => 200,
        default    => 0,
    };
}

function normalizarVariante(string $variante): string {
    $variante = strtolower(trim($variante));
    return in_array($variante, ["normal", "corrupta", "suprema"], true) ? $variante : "normal";
}

function cargarRarezas(mysqli $conexion): array {
    $stmt = $conexion->prepare("SELECT slug, orden FROM rarezas WHERE activa = 1 ORDER BY orden ASC");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $validas = [];
    $orden = [];
    foreach ($rows as $r) {
        $validas[] = $r["slug"];
        $orden[$r["slug"]] = (int)$r["orden"];
    }
    return [$validas, $orden];
}

function multiplicadorNormalBase(mysqli $conexion, int $grupo_id, string $rareza): ?float {
    $stmt = $conexion->prepare("SELECT multiplicador FROM runas WHERE grupo_id = ? AND rareza = ? AND tier < 100 ORDER BY id ASC LIMIT 1");
    $stmt->bind_param("is", $grupo_id, $rareza);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row["multiplicador"] : null;
}

function nombreEsperadoArchivo(string $rareza, string $variante): string {
    if ($variante === "normal") return "";
    return $rareza . "_" . $variante . ".html";
}

[$rarezas_validas, $rarezas_orden] = cargarRarezas($conexion);
$hasMarco      = columnExists($conexion, "runas", "marco_html");
$hasBackground = columnExists($conexion, "runas", "background_html");

if ($accion === "crear" || $accion === "editar") {
    $id            = (int)($_POST["id"] ?? 0);
    $grupo_id      = (int)($_POST["grupo_id"] ?? 0);
    $nombre        = limpiarTexto((string)($_POST["nombre"] ?? ""));
    $rareza        = trim((string)($_POST["rareza"] ?? ""));
    $variante      = normalizarVariante((string)($_POST["variante"] ?? "normal"));
    $activa        = (int)($_POST["activa"] ?? 0);
    $archivoManual = archivoSeguro((string)($_POST["archivo_html"] ?? ""));
    $marcoManual   = archivoSeguro((string)($_POST["marco_html"] ?? ""));
    $bgManual      = archivoSeguro((string)($_POST["background_html"] ?? ""));

    $errores = [];
    if ($accion === "editar" && $id <= 0)             $errores[] = "ID de runa invalido.";
    if ($grupo_id <= 0)                                $errores[] = "Selecciona una coleccion/lista.";
    if ($nombre === "")                                $errores[] = "El nombre no puede estar vacio.";
    if (!in_array($rareza, $rarezas_validas, true))     $errores[] = "Rareza no valida.";

    $tier = baseTierVariante($variante) + ($rarezas_orden[$rareza] ?? 1);

    if ($variante === "normal") {
        $multiplicador = (float)($_POST["multiplicador"] ?? 0);
        if ($multiplicador < 0) $errores[] = "El multiplicador no puede ser negativo.";
    } else {
        $base = multiplicadorNormalBase($conexion, $grupo_id, $rareza);
        if ($base === null) {
            $errores[] = "No existe una runa normal base para esta coleccion y rareza. Crea primero la version normal.";
            $multiplicador = 0;
        } else {
            $multiplicador = $base * factorVariante($variante);
        }
    }

    // Subida / seleccion del archivo visual principal.
    $nombrePreferido = $archivoManual !== "" ? $archivoManual : nombreEsperadoArchivo($rareza, $variante);
    $upload = subirArchivo("archivo_upload", DIR_RUNAS, $nombrePreferido);
    if (!$upload["ok"]) $errores[] = $upload["error"];
    $archivoFinal = $upload["archivo"] !== "" ? $upload["archivo"] : $archivoManual;

    if ($archivoFinal !== "" && !extensionPermitida($archivoFinal)) {
        $errores[] = "El archivo visual debe ser .html, .htm o .svg.";
    }

    // Marco/background opcionales. Solo se guardan si las columnas existen.
    if ($hasMarco) {
        $upMarco = subirArchivo("marco_upload", DIR_MARCOS, $marcoManual);
        if (!$upMarco["ok"]) $errores[] = $upMarco["error"];
        if ($upMarco["archivo"] !== "") $marcoManual = $upMarco["archivo"];
    }
    if ($hasBackground) {
        $upBg = subirArchivo("background_upload", DIR_BACKGROUNDS, $bgManual);
        if (!$upBg["ok"]) $errores[] = $upBg["error"];
        if ($upBg["archivo"] !== "") $bgManual = $upBg["archivo"];
    }

    $urlError = $accion === "crear"
        ? "../ADMIN/crear_runa.php?grupo_id=$grupo_id&variante=" . urlencode($variante)
        : "../ADMIN/editar_runa.php?id=$id";

    if (!empty($errores)) {
        guardarErrores($errores, $urlError);
    }

    if ($accion === "crear") {
        if ($hasMarco && $hasBackground) {
            $stmt = $conexion->prepare("INSERT INTO runas (grupo_id, nombre, rareza, tier, multiplicador, imagen, marco_html, background_html, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issidsssi", $grupo_id, $nombre, $rareza, $tier, $multiplicador, $archivoFinal, $marcoManual, $bgManual, $activa);
        } else {
            $stmt = $conexion->prepare("INSERT INTO runas (grupo_id, nombre, rareza, tier, multiplicador, imagen, activa) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issidsi", $grupo_id, $nombre, $rareza, $tier, $multiplicador, $archivoFinal, $activa);
        }
        $stmt->execute();
        $stmt->close();
        redirectRunas("ok=runa_registrada");
    }

    if ($hasMarco && $hasBackground) {
        $stmt = $conexion->prepare("UPDATE runas SET grupo_id=?, nombre=?, rareza=?, tier=?, multiplicador=?, imagen=?, marco_html=?, background_html=?, activa=? WHERE id=?");
        $stmt->bind_param("issidsssii", $grupo_id, $nombre, $rareza, $tier, $multiplicador, $archivoFinal, $marcoManual, $bgManual, $activa, $id);
    } else {
        $stmt = $conexion->prepare("UPDATE runas SET grupo_id=?, nombre=?, rareza=?, tier=?, multiplicador=?, imagen=?, activa=? WHERE id=?");
        $stmt->bind_param("issidsii", $grupo_id, $nombre, $rareza, $tier, $multiplicador, $archivoFinal, $activa, $id);
    }
    $stmt->execute();
    $stmt->close();
    redirectRunas("ok=runa_actualizada");
}

if ($accion === "desactivar" || $accion === "eliminar") {
    // Eliminar queda convertido en desactivar para no romper inventarios antiguos.
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) redirectRunas("error=id_invalido");

    $stmt = $conexion->prepare("UPDATE runas SET activa = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    redirectRunas("ok=runa_desactivada");
}

if ($accion === "activar") {
    $id = (int)($_GET["id"] ?? 0);
    if ($id <= 0) redirectRunas("error=id_invalido");

    $stmt = $conexion->prepare("UPDATE runas SET activa = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    redirectRunas("ok=runa_activada");
}

redirectRunas();
