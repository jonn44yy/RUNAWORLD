<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

$errores = [];
if (isset($_SESSION["errores"])) {
    $errores = $_SESSION["errores"];
    unset($_SESSION["errores"]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Crear Mejora</title>
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
            <a href="usuarios.php" class="admin-nav-btn"><span class="nav-icon">◈</span> Usuarios</a>
            <a href="runas.php"    class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn active"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Crear Mejora</div>
        <div class="admin-page-sub">Añadir una nueva mejora a la tienda</div>
        <div class="admin-separador"></div>

        <a href="tienda.php" class="btn-admin btn-admin-primary" style="margin-bottom:28px; display:inline-block;">← Volver a Tienda</a>

        <?php foreach ($errores as $e): ?>
            <p class="admin-msg-error"><?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>

        <form method="POST" action="../PHP/mejoras_action.php" class="admin-form">
            <input type="hidden" name="accion" value="crear">

            <div class="admin-form-grupo">
                <label class="admin-form-label">Nombre</label>
                <input type="text" name="nombre" class="admin-form-input" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Tipo</label>
                <select name="tipo" class="admin-form-select" required>
                    <option value="coins_seg">Coins/seg (+X por nivel)</option>
                    <option value="coins_seg_multi">Multiplicador Coins/seg (xN al total)</option>
                    <option value="points_seg">Points/seg (+X por nivel)</option>
                    <option value="points_seg_multi">Multiplicador Points/seg (xN al total)</option>
                    <option value="suerte">Suerte (+X por nivel)</option>
                    <option value="bulk">Bulk (+1 runa extra por tirada)</option>
                </select>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Coste base (en points)</label>
                <input type="text" name="coste_base" class="admin-form-input input-abbr" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Escala de coste (se multiplica cada nivel)</label>
                <input type="number" name="coste_escala" class="admin-form-input" min="1" step="0.01" value="2" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Valor por nivel</label>
                <input type="text" name="valor" class="admin-form-input input-abbr" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Nivel maximo</label>
                <input type="number" name="nivel_maximo" class="admin-form-input" min="1" value="10" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Descripcion</label>
                <input type="text" name="descripcion" class="admin-form-input" maxlength="255">
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Activa</label>
                <select name="activa" class="admin-form-select">
                    <option value="1">Si</option>
                    <option value="0">No</option>
                </select>
            </div>

            <button type="submit" class="admin-form-submit">Crear mejora</button>
        </form>
    </main>
</div>
<script src="../JS/abbr-input.js"></script>
</body>
</html>