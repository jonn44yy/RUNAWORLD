<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? '') !== "admin") {
    header("Location: ../index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../ADMIN/usuarios.php");
    exit;
}

require_once "conexion.php";

function tableExists($conexion, $table) {
    $table = $conexion->real_escape_string($table);
    $res = $conexion->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}

function columnExists($conexion, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conexion->real_escape_string($column);
    $res = $conexion->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function numPost($key, $default = 0) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') return $default;
    return (float)str_replace(',', '.', $_POST[$key]);
}

function intPost($key, $default = 0) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') return $default;
    return max(0, (int)$_POST[$key]);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$accion = $_POST['accion'] ?? 'guardar';
$errores = [];

if ($id <= 0) {
    $errores[] = "ID de usuario inválido.";
}

$stmt = $conexion->prepare("SELECT u.id, u.rol, j.id AS jugador_id FROM usuarios u LEFT JOIN jugadores j ON u.id = j.usuario_id WHERE u.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$base = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$base) {
    $errores[] = "Usuario no encontrado.";
}

if ($accion === 'crear_jugador' && empty($errores)) {
    if (!empty($base['jugador_id'])) {
        $_SESSION['ok'] = "El usuario ya tiene jugador asociado.";
        $conexion->close();
        header("Location: ../ADMIN/editar_progreso.php?id=$id");
        exit;
    }

    $cols = ['usuario_id'];
    $placeholders = ['?'];
    $types = 'i';
    $values = [$id];

    $defaults = [
        'coins' => 0,
        'points' => 0,
        'coins_por_seg' => 1,
        'points_por_seg' => 0,
        'bulk_total' => 1,
        'suerte' => 0,
        'ultima_actualizacion' => date('Y-m-d H:i:s')
    ];

    foreach ($defaults as $col => $val) {
        if (columnExists($conexion, 'jugadores', $col)) {
            $cols[] = "`$col`";
            $placeholders[] = '?';
            if ($col === 'ultima_actualizacion') {
                $types .= 's';
                $values[] = $val;
            } else {
                $types .= 'd';
                $values[] = (float)$val;
            }
        }
    }

    $sql = "INSERT INTO jugadores (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();

    $_SESSION['ok'] = "Progreso inicial creado correctamente.";
    $conexion->close();
    header("Location: ../ADMIN/editar_progreso.php?id=$id");
    exit;
}

if (empty($base['jugador_id'])) {
    $errores[] = "Este usuario no tiene jugador asociado. Crea primero el progreso inicial.";
}

$coins = max(0, numPost('coins'));
$points = max(0, numPost('points'));
$coinsPs = max(0, numPost('coins_por_seg'));
$pointsPs = max(0, numPost('points_por_seg'));
$bulk = max(0, numPost('bulk_total'));
$suerte = max(0, numPost('suerte'));

if (!empty($errores)) {
    $_SESSION['errores'] = $errores;
    $conexion->close();
    header("Location: ../ADMIN/editar_progreso.php?id=$id");
    exit;
}

$fields = [];
$types = '';
$values = [];

$map = [
    'coins' => $coins,
    'points' => $points,
    'coins_por_seg' => $coinsPs,
    'points_por_seg' => $pointsPs,
    'bulk_total' => $bulk,
    'suerte' => $suerte,
];

foreach ($map as $col => $val) {
    if (columnExists($conexion, 'jugadores', $col)) {
        $fields[] = "`$col` = ?";
        $types .= 'd';
        $values[] = $val;
    }
}

if (columnExists($conexion, 'jugadores', 'coins_ps_config')) {
    $fields[] = "coins_ps_config = ?";
    $types .= 'd';
    $values[] = $coinsPs;
}
if (columnExists($conexion, 'jugadores', 'points_ps_config')) {
    $fields[] = "points_ps_config = ?";
    $types .= 'd';
    $values[] = $pointsPs;
}
if (columnExists($conexion, 'jugadores', 'coins_ps_max')) {
    $fields[] = "coins_ps_max = GREATEST(COALESCE(coins_ps_max, 0), ?)";
    $types .= 'd';
    $values[] = $coinsPs;
}
if (columnExists($conexion, 'jugadores', 'points_ps_max')) {
    $fields[] = "points_ps_max = GREATEST(COALESCE(points_ps_max, 0), ?)";
    $types .= 'd';
    $values[] = $pointsPs;
}
if (columnExists($conexion, 'jugadores', 'ultima_actualizacion')) {
    $fields[] = "ultima_actualizacion = NOW()";
}

if (!empty($fields)) {
    $types .= 'i';
    $values[] = $id;
    $sql = "UPDATE jugadores SET " . implode(', ', $fields) . " WHERE usuario_id = ?";
    $stmt = $conexion->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

if (tableExists($conexion, 'jugador_stats') && !empty($base['jugador_id'])) {
    $jugadorId = (int)$base['jugador_id'];
    $statsMap = [
        'total_tiradas' => intPost('total_tiradas'),
        'total_runas_conseguidas' => intPost('total_runas_conseguidas'),
        'total_eternas' => intPost('total_eternas'),
        'total_divinas' => intPost('total_divinas'),
        'total_miticas' => intPost('total_miticas'),
        'total_legendarias' => intPost('total_legendarias'),
        'boosts_clickados' => intPost('boosts_clickados'),
    ];

    $statsFields = [];
    $statsTypes = '';
    $statsValues = [];
    foreach ($statsMap as $col => $val) {
        if (columnExists($conexion, 'jugador_stats', $col)) {
            $statsFields[] = "`$col` = ?";
            $statsTypes .= 'i';
            $statsValues[] = $val;
        }
    }

    if (!empty($statsFields)) {
        $check = $conexion->prepare("SELECT jugador_id FROM jugador_stats WHERE jugador_id = ? LIMIT 1");
        $check->bind_param("i", $jugadorId);
        $check->execute();
        $exists = (bool)$check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $statsTypes .= 'i';
            $statsValues[] = $jugadorId;
            $sql = "UPDATE jugador_stats SET " . implode(', ', $statsFields) . " WHERE jugador_id = ?";
            $stmt = $conexion->prepare($sql);
            $stmt->bind_param($statsTypes, ...$statsValues);
            $stmt->execute();
            $stmt->close();
        } else {
            $cols = ['jugador_id'];
            $placeholders = ['?'];
            $insertTypes = 'i';
            $insertValues = [$jugadorId];
            foreach ($statsMap as $col => $val) {
                if (columnExists($conexion, 'jugador_stats', $col)) {
                    $cols[] = "`$col`";
                    $placeholders[] = '?';
                    $insertTypes .= 'i';
                    $insertValues[] = $val;
                }
            }
            $sql = "INSERT INTO jugador_stats (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $conexion->prepare($sql);
            $stmt->bind_param($insertTypes, ...$insertValues);
            $stmt->execute();
            $stmt->close();
        }
    }
}

$conexion->close();
$_SESSION['ok'] = "Progreso actualizado correctamente.";
header("Location: ../ADMIN/editar_progreso.php?id=$id");
exit;