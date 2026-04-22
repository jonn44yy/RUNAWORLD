<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) die("ID invalido");

$errores = [];
if (isset($_SESSION["errores"])) {
    $errores = $_SESSION["errores"];
    unset($_SESSION["errores"]);
}

$stmt = $conexion->prepare("SELECT id, username, email, genero FROM usuarios WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conexion->close();

if (!$usuario) die("Usuario no encontrado");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Editar Cuenta</title>
    <link rel="stylesheet" href="../CSS/admin.css">
</head>
<body>

<div id="admin-layout" class="visible">

    <aside id="admin-sidebar">
        <div id="sidebar-logo">
            <svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
                <circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/>
                <circle cx="200" cy="200" r="80"  fill="none" stroke="currentColor" stroke-width="1" opacity="0.8"/>
                <g stroke="currentColor" stroke-width="2.5" opacity="1" stroke-linecap="round">
                    <line x1="200" y1="125" x2="200" y2="95"/><line x1="193" y1="110" x2="200" y2="95"/><line x1="207" y1="110" x2="200" y2="95"/>
                    <line x1="200" y1="275" x2="200" y2="305"/><line x1="193" y1="290" x2="207" y2="290"/>
                    <line x1="275" y1="200" x2="305" y2="200"/><line x1="290" y1="193" x2="305" y2="200"/><line x1="290" y1="207" x2="305" y2="200"/>
                    <line x1="125" y1="200" x2="95" y2="200"/><line x1="110" y1="193" x2="95" y2="200"/><line x1="110" y1="207" x2="95" y2="200"/>
                    <line x1="254" y1="146" x2="275" y2="125"/><line x1="146" y1="146" x2="125" y2="125"/>
                    <line x1="254" y1="254" x2="275" y2="275"/><line x1="146" y1="254" x2="125" y2="275"/>
                </g>
                <g stroke="currentColor" stroke-width="1.5" opacity="0.9">
                    <line x1="200" y1="140" x2="200" y2="260"/>
                    <line x1="140" y1="200" x2="260" y2="200"/>
                    <circle cx="200" cy="200" r="20" fill="none"/>
                    <circle cx="200" cy="200" r="4" fill="currentColor"/>
                </g>
            </svg>
            <div id="sidebar-logo-titulo">RunaWorld</div>
            <div id="sidebar-logo-sub">Admin Panel</div>
        </div>
        <nav class="admin-nav">
            <a href="index.php"    class="admin-nav-btn"><span class="nav-icon">⬡</span> Dashboard</a>
            <a href="usuarios.php" class="admin-nav-btn active"><span class="nav-icon">◈</span> Usuarios</a>
            <a href="runas.php"    class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">

        <div class="admin-page-titulo">Editar Cuenta</div>
        <div class="admin-page-sub">
            Datos de cuenta de <strong style="color:var(--blue-bright);"><?= htmlspecialchars($usuario["username"]) ?></strong>
        </div>
        <div class="admin-separador"></div>

        <a href="usuarios.php" class="btn-admin btn-admin-primary" style="margin-bottom:28px; display:inline-block;">
            ← Volver a usuarios
        </a>

        <?php foreach ($errores as $e): ?>
            <p class="admin-msg-error"><?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>

        <form method="POST" action="../PHP/editar_cuenta_action.php" class="admin-form">
            <input type="hidden" name="id" value="<?= $usuario["id"] ?>">

            <div class="admin-form-grupo">
                <label class="admin-form-label">Nombre de usuario</label>
                <input type="text" name="username" class="admin-form-input"
                       value="<?= htmlspecialchars($usuario["username"]) ?>" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Email</label>
                <input type="email" name="email" class="admin-form-input"
                       value="<?= htmlspecialchars($usuario["email"]) ?>" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Genero</label>
                <select name="genero" class="admin-form-select">
                    <option value="masculino" <?= $usuario["genero"] === "masculino" ? "selected" : "" ?>>Masculino</option>
                    <option value="femenino"  <?= $usuario["genero"] === "femenino"  ? "selected" : "" ?>>Femenino</option>
                    <option value="otro"      <?= $usuario["genero"] === "otro"      ? "selected" : "" ?>>Otro</option>
                </select>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Nueva contrasena (dejar vacio para no cambiar)</label>
                <input type="password" name="password" class="admin-form-input"
                       placeholder="Nueva contrasena">
            </div>

            <button type="submit" class="admin-form-submit">Guardar cambios</button>
        </form>

    </main>
</div>

</body>
</html>
