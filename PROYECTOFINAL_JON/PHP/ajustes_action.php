<?php
session_start();

if (!isset($_SESSION["idUsuario"])) {
    echo json_encode(["ok" => false, "error" => "No autenticado"]);
    exit;
}

require_once "conexion.php";

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
        $nuevo_val = floatval($valor);
        if ($nuevo_val < 0) $nuevo_val = 1;

        // Obtener máximo del jugador
        $stmt = $conexion->prepare("SELECT coins_ps_max FROM jugadores WHERE usuario_id = ?");
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $maximo = floatval($row["coins_ps_max"] ?? 1);

        // Si supera el máximo, poner en 1
        if ($nuevo_val > $maximo) $nuevo_val = 1;
        if ($nuevo_val < 1 && $maximo > 0) $nuevo_val = 1;

        $stmt = $conexion->prepare("UPDATE jugadores SET coins_por_seg = ? WHERE usuario_id = ?");
        $stmt->bind_param("di", $nuevo_val, $id_usuario);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["ok" => true, "valor" => $nuevo_val]);
        break;

    // ---- CONFIGURAR PRODUCCIÓN POINTS ----
    case "produccion_points":
        $nuevo_val = floatval($valor);
        if ($nuevo_val < 0) $nuevo_val = 0;

        $stmt = $conexion->prepare("SELECT points_ps_max FROM jugadores WHERE usuario_id = ?");
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $maximo = floatval($row["points_ps_max"] ?? 0);

        if ($nuevo_val > $maximo) $nuevo_val = 1;
        if ($nuevo_val < 0) $nuevo_val = 0;

        $stmt = $conexion->prepare("UPDATE jugadores SET points_por_seg = ? WHERE usuario_id = ?");
        $stmt->bind_param("di", $nuevo_val, $id_usuario);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["ok" => true, "valor" => $nuevo_val]);
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
