<?php
// eliminar_cuenta.php — elimina la cuenta completa del usuario logueado.
// Borra datos del jugador, destruye la sesión y devuelve JSON.
// IMPORTANTE: este endpoint debe llamarse por POST desde ajustes.js.

session_start();
header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";

$id_usuario = (int)$_SESSION["idUsuario"];

function rw_table_exists(mysqli $conexion, string $table): bool {
    $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
    $stmt = $conexion->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function rw_column_exists(mysqli $conexion, string $table, string $column): bool {
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $conexion->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function rw_delete_by_int(mysqli $conexion, string $table, string $column, int $value): void {
    if (!rw_table_exists($conexion, $table) || !rw_column_exists($conexion, $table, $column)) {
        return;
    }

    $sql = "DELETE FROM `$table` WHERE `$column` = ?";
    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        throw new Exception("No se pudo preparar DELETE en $table.$column");
    }
    $stmt->bind_param("i", $value);
    $stmt->execute();
    $stmt->close();
}

$conexion->begin_transaction();

try {
    $jugador_id = 0;

    if (rw_table_exists($conexion, "jugadores") && rw_column_exists($conexion, "jugadores", "usuario_id")) {
        $stmt = $conexion->prepare("SELECT id FROM jugadores WHERE usuario_id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception("No se pudo buscar jugador");
        }
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $jugador_id = (int)$row["id"];
        }
    }

    if ($jugador_id > 0) {
        // Datos directos del jugador. Se comprueba existencia de tabla/columna
        // para que el endpoint no explote si alguna tabla no existe en local/hosting.
        rw_delete_by_int($conexion, "jugador_runas", "jugador_id", $jugador_id);
        rw_delete_by_int($conexion, "jugador_mejoras", "jugador_id", $jugador_id);
        rw_delete_by_int($conexion, "jugador_stats", "jugador_id", $jugador_id);
        rw_delete_by_int($conexion, "jugador_bonus", "jugador_id", $jugador_id);
        rw_delete_by_int($conexion, "batches_procesados", "jugador_id", $jugador_id);
        rw_delete_by_int($conexion, "packs_tiradas", "jugador_id", $jugador_id);
        rw_delete_by_int($conexion, "packs_tiradas_unidades", "jugador_id", $jugador_id);
        rw_delete_by_int($conexion, "mensajes_admin", "jugador_id", $jugador_id);
        rw_delete_by_int($conexion, "mensajes", "jugador_id", $jugador_id);

        // Finalmente el jugador.
        rw_delete_by_int($conexion, "jugadores", "id", $jugador_id);
    }

    // Datos ligados al usuario/login.
    rw_delete_by_int($conexion, "mensajes_admin", "usuario_id", $id_usuario);
    rw_delete_by_int($conexion, "mensajes", "usuario_id", $id_usuario);

    // Tabla de usuarios. En tu proyecto lo normal es `usuarios`, pero dejo
    // fallback a `users` por si cambiaste nombre en alguna instalación.
    if (rw_table_exists($conexion, "usuarios") && rw_column_exists($conexion, "usuarios", "id")) {
        rw_delete_by_int($conexion, "usuarios", "id", $id_usuario);
    } elseif (rw_table_exists($conexion, "users") && rw_column_exists($conexion, "users", "id")) {
        rw_delete_by_int($conexion, "users", "id", $id_usuario);
    }

    $conexion->commit();

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"] ?? "/",
            $params["domain"] ?? "",
            $params["secure"] ?? false,
            $params["httponly"] ?? true
        );
    }

    session_destroy();

    echo json_encode(["ok" => true]);

} catch (Throwable $e) {
    @$conexion->rollback();
    error_log("eliminar_cuenta: " . $e->getMessage());
    echo json_encode(["ok" => false, "error" => "Error interno al eliminar la cuenta"]);
}

$conexion->close();
