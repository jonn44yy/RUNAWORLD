<?php
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";
require_once "calcular_stats.php";

$datos      = json_decode(file_get_contents("php://input"), true);
$accion     = $datos["accion"] ?? "";
$valor      = $datos["valor"]  ?? "";
$id_usuario = $_SESSION["idUsuario"];

switch ($accion) {

    // ---- CAMBIAR USERNAME ----
    case "username":
        $username = trim(strip_tags($valor));
        if (strlen($username) < 3) {
            echo json_encode(["ok" => false, "error" => "Minimo 3 caracteres."]);
            exit;
        }
        // Comprobar que no existe
        $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE username = ? AND id != ?");
        $stmt->bind_param("si", $username, $id_usuario);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(["ok" => false, "error" => "Ese nombre ya esta en uso."]);
            exit;
        }
        $stmt->close();
        $stmt = $conexion->prepare("UPDATE usuarios SET username = ? WHERE id = ?");
        $stmt->bind_param("si", $username, $id_usuario);
        $stmt->execute();
        $stmt->close();
        $_SESSION["username"] = $username;
        echo json_encode(["ok" => true]);
        break;

    // ---- CAMBIAR EMAIL ----
    case "email":
        $email = trim($valor);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(["ok" => false, "error" => "Email no valido."]);
            exit;
        }
        $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
        $stmt->bind_param("si", $email, $id_usuario);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            echo json_encode(["ok" => false, "error" => "Ese email ya esta en uso."]);
            exit;
        }
        $stmt->close();
        $stmt = $conexion->prepare("UPDATE usuarios SET email = ? WHERE id = ?");
        $stmt->bind_param("si", $email, $id_usuario);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["ok" => true]);
        break;

    // ---- CAMBIAR PASSWORD ----
    case "password":
        $pw = $valor;
        if (strlen($pw) < 6) {
            echo json_encode(["ok" => false, "error" => "Minimo 6 caracteres."]);
            exit;
        }
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $stmt = $conexion->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hash, $id_usuario);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["ok" => true]);
        break;

    // ---- CONFIGURAR PRODUCCIÓN COINS ----
    case "produccion_coins":
        echo json_encode(["ok" => false, "error" => "Configuracion manual de produccion desactivada temporalmente."]);
        break;

    case "produccion_coins_old_disabled":
        $nuevo_val = floatval($valor);

        $conexion->begin_transaction();
        try {
            $stmt = $conexion->prepare("SELECT id FROM jugadores WHERE usuario_id = ? FOR UPDATE");
            $stmt->bind_param("i", $id_usuario);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $conexion->rollback();
                echo json_encode(["ok" => false, "error" => "Jugador no encontrado."]);
                exit;
            }

            $jugador_id = (int)$row["id"];
            list($coins_ps_max_real, $points_ps_max_real) = calcularStatsJugador($conexion, $jugador_id);
            $coins_ps_max_real = max(1.0, floatval($coins_ps_max_real));

            // 0 = reset al maximo real actual.
            if ($nuevo_val <= 0) {
                $config_sql = null;
                $valor_actual = $coins_ps_max_real;
                $stmt = $conexion->prepare("
                    UPDATE jugadores
                    SET coins_por_seg = ?, coins_ps_config = NULL,
                        coins_ps_max = GREATEST(coins_ps_max, ?)
                    WHERE id = ?
                ");
                $stmt->bind_param("ddi", $valor_actual, $coins_ps_max_real, $jugador_id);
            } else {
                if ($nuevo_val > $coins_ps_max_real) $nuevo_val = 1.0;
                if ($nuevo_val < 1.0) $nuevo_val = 1.0;
                $valor_actual = min($coins_ps_max_real, $nuevo_val);
                $stmt = $conexion->prepare("
                    UPDATE jugadores
                    SET coins_por_seg = ?, coins_ps_config = ?,
                        coins_ps_max = GREATEST(coins_ps_max, ?)
                    WHERE id = ?
                ");
                $stmt->bind_param("dddi", $valor_actual, $valor_actual, $coins_ps_max_real, $jugador_id);
            }

            $stmt->execute();
            $stmt->close();
            $conexion->commit();

            echo json_encode([
                "ok" => true,
                "valor" => $valor_actual,
                "maximo" => $coins_ps_max_real,
                "configurado" => $nuevo_val > 0
            ]);
        } catch (Exception $e) {
            @$conexion->rollback();
            error_log("ajustes produccion_coins: " . $e->getMessage());
            echo json_encode(["ok" => false, "error" => "Error interno."]);
        }
        break;

    // ---- CONFIGURAR PRODUCCIÓN POINTS ----
    case "produccion_points":
        echo json_encode(["ok" => false, "error" => "Configuracion manual de produccion desactivada temporalmente."]);
        break;

    case "produccion_points_old_disabled":
        $nuevo_val = floatval($valor);

        $conexion->begin_transaction();
        try {
            $stmt = $conexion->prepare("SELECT id FROM jugadores WHERE usuario_id = ? FOR UPDATE");
            $stmt->bind_param("i", $id_usuario);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $conexion->rollback();
                echo json_encode(["ok" => false, "error" => "Jugador no encontrado."]);
                exit;
            }

            $jugador_id = (int)$row["id"];
            list($coins_ps_max_real, $points_ps_max_real) = calcularStatsJugador($conexion, $jugador_id);
            $points_ps_max_real = max(0.0, floatval($points_ps_max_real));

            // 0 = reset al maximo real actual.
            if ($nuevo_val <= 0) {
                $valor_actual = $points_ps_max_real;
                $stmt = $conexion->prepare("
                    UPDATE jugadores
                    SET points_por_seg = ?, points_ps_config = NULL,
                        points_ps_max = GREATEST(points_ps_max, ?)
                    WHERE id = ?
                ");
                $stmt->bind_param("ddi", $valor_actual, $points_ps_max_real, $jugador_id);
            } else {
                if ($nuevo_val > $points_ps_max_real) $nuevo_val = 1.0;
                if ($nuevo_val < 0.0) $nuevo_val = 0.0;
                $valor_actual = min($points_ps_max_real, $nuevo_val);
                $stmt = $conexion->prepare("
                    UPDATE jugadores
                    SET points_por_seg = ?, points_ps_config = ?,
                        points_ps_max = GREATEST(points_ps_max, ?)
                    WHERE id = ?
                ");
                $stmt->bind_param("dddi", $valor_actual, $valor_actual, $points_ps_max_real, $jugador_id);
            }

            $stmt->execute();
            $stmt->close();
            $conexion->commit();

            echo json_encode([
                "ok" => true,
                "valor" => $valor_actual,
                "maximo" => $points_ps_max_real,
                "configurado" => $nuevo_val > 0
            ]);
        } catch (Exception $e) {
            @$conexion->rollback();
            error_log("ajustes produccion_points: " . $e->getMessage());
            echo json_encode(["ok" => false, "error" => "Error interno."]);
        }
        break;

    // ---- DISPLAY MODE (porcentaje / peso) ----
    case "display_mode":
        $modo = in_array($valor, ["porcentaje", "peso"]) ? $valor : "porcentaje";
        $stmt = $conexion->prepare("UPDATE jugadores SET display_mode = ? WHERE usuario_id = ?");
        $stmt->bind_param("si", $modo, $id_usuario);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["ok" => true, "display_mode" => $modo]);
        break;

    default:
        echo json_encode(["ok" => false, "error" => "Accion desconocida."]);
        break;
}

$conexion->close();
