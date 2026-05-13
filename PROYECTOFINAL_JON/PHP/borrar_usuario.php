<?php
session_start();
require_once __DIR__ . '/conexion.php';

if (!isset($_SESSION['idUsuario']) || ($_SESSION['rol'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$idUsuario = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($idUsuario <= 0) {
    header('Location: /ADMIN/usuarios.php?error=id_invalido');
    exit;
}

if ($idUsuario === (int)$_SESSION['idUsuario']) {
    header('Location: /ADMIN/usuarios.php?error=no_puedes_borrarte');
    exit;
}

try {
    $conexion->begin_transaction();

    // Buscar jugador asociado al usuario
    $stmt = $conexion->prepare("SELECT id FROM jugadores WHERE usuario_id = ?");
    $stmt->bind_param("i", $idUsuario);
    $stmt->execute();
    $res = $stmt->get_result();
    $jugador = $res->fetch_assoc();
    $stmt->close();

    if ($jugador) {
        $idJugador = (int)$jugador['id'];

        $tablasJugador = [
            'jugador_runas',
            'jugador_mejoras',
            'jugador_stats',
            'packs_tiradas'
        ];

        foreach ($tablasJugador as $tabla) {
            $sql = "DELETE FROM $tabla WHERE jugador_id = ?";
            $stmt = $conexion->prepare($sql);
            $stmt->bind_param("i", $idJugador);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conexion->prepare("DELETE FROM jugadores WHERE id = ?");
        $stmt->bind_param("i", $idJugador);
        $stmt->execute();
        $stmt->close();
    }

    // Si tienes mensajes asociados al usuario
    $stmt = $conexion->prepare("DELETE FROM mensajes WHERE usuario_id = ?");
    $stmt->bind_param("i", $idUsuario);
    $stmt->execute();
    $stmt->close();

    // Borrar usuario final
    $stmt = $conexion->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $idUsuario);
    $stmt->execute();
    $stmt->close();

    $conexion->commit();

    header('Location: /ADMIN/usuarios.php?ok=usuario_borrado');
    exit;

} catch (Throwable $e) {
    $conexion->rollback();
    header('Location: /ADMIN/usuarios.php?error=borrado_fallido');
    exit;
}