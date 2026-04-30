<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

$grupo_id = isset($_GET["grupo_id"]) ? (int)$_GET["grupo_id"] : 0;

$errores = [];
if (isset($_SESSION["errores"])) {
    $errores = $_SESSION["errores"];
    unset($_SESSION["errores"]);
}

$stmt = $conexion->prepare("SELECT * FROM grupos_runas ORDER BY id ASC");
$stmt->execute();
$grupos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conexion->prepare("SELECT slug, nombre, color FROM rarezas WHERE activa = 1 ORDER BY orden ASC");
$stmt->execute();
$rarezas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Crear Runa</title>
    <link rel="stylesheet" href="../CSS/admin.css">
</head>
<body>
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>
<div id="admin-layout" class="visible">

    <aside id="admin-sidebar">
        <div id="sidebar-logo">
            <svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
                <circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/>
                <circle cx="200" cy="200" r="145" fill="none" stroke="currentColor" stroke-width="0.7" opacity="0.7"/>
                <circle cx="200" cy="200" r="80"  fill="none" stroke="currentColor" stroke-width="1"   opacity="0.8"/>
                <g stroke="currentColor" stroke-width="2" opacity="1" stroke-linecap="round">
                    <line x1="200" y1="125" x2="200" y2="95"/><line x1="193" y1="110" x2="200" y2="95"/><line x1="207" y1="110" x2="200" y2="95"/>
                    <line x1="200" y1="275" x2="200" y2="305"/><line x1="193" y1="290" x2="207" y2="290"/>
                    <line x1="275" y1="200" x2="305" y2="200"/><line x1="290" y1="193" x2="305" y2="200"/><line x1="290" y1="207" x2="305" y2="200"/>
                    <line x1="125" y1="200" x2="95"  y2="200"/><line x1="110" y1="193" x2="95"  y2="200"/><line x1="110" y1="207" x2="95"  y2="200"/>
                    <line x1="254" y1="146" x2="275" y2="125"/><line x1="146" y1="146" x2="125" y2="125"/>
                    <line x1="254" y1="254" x2="275" y2="275"/><line x1="146" y1="254" x2="125" y2="275"/>
                </g>
                <g stroke="currentColor" stroke-width="1.5" opacity="0.9">
                    <line x1="200" y1="140" x2="200" y2="260"/>
                    <line x1="140" y1="200" x2="260" y2="200"/>
                    <circle cx="200" cy="200" r="25" fill="none"/>
                    <circle cx="200" cy="200" r="5"  fill="currentColor"/>
                </g>
            </svg>
            <div id="sidebar-logo-titulo">RunaWorld</div>
        </div>
        <nav class="admin-nav">
            <a href="index.php"    class="admin-nav-btn"><span class="nav-icon">⬡</span> Dashboard</a>
            <a href="usuarios.php" class="admin-nav-btn"><span class="nav-icon">◈</span> Usuarios</a>
            <a href="runas.php"    class="admin-nav-btn active"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <a href="rarezas.php"  class="admin-nav-btn"><span class="nav-icon">✦</span> Rarezas</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Crear Runa</div>
        <div class="admin-page-sub">Añadir una nueva runa al catalogo</div>
        <div class="admin-separador"></div>

        <a href="runas.php" class="btn-admin btn-admin-primary" style="margin-bottom:28px; display:inline-block;">← Volver a Runas</a>

        <?php foreach ($errores as $e): ?>
            <p class="admin-msg-error"><?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>

        <form method="POST" action="../PHP/runas_action.php" class="admin-form">
            <input type="hidden" name="accion" value="crear">

            <div class="admin-form-grupo">
                <label class="admin-form-label">Lista (grupo)</label>
                <select name="grupo_id" class="admin-form-select" required>
                    <option value="">-- Selecciona una lista --</option>
                    <?php foreach ($grupos as $g): ?>
                        <option value="<?= $g["id"] ?>" <?= $grupo_id === $g["id"] ? "selected" : "" ?>>
                            <?= htmlspecialchars($g["nombre"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Nombre</label>
                <input type="text" name="nombre" class="admin-form-input" placeholder="Ej: Runa de Fuego" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Rareza</label>
                <select name="rareza" class="admin-form-select" required>
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($rarezas as $r): ?>
                        <option value="<?= htmlspecialchars($r['slug']) ?>"
                            style="color:<?= htmlspecialchars($r['color']) ?>">
                            <?= htmlspecialchars($r['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Multiplicador (points/seg que aporta)</label>
                <input type="text" name="multiplicador" class="admin-form-input input-abbr" value="1.00" required>
            </div>

            <div class="admin-form-grupo">
                <label class="admin-form-label">Activa</label>
                <select name="activa" class="admin-form-select">
                    <option value="1">Si</option>
                    <option value="0">No</option>
                </select>
            </div>

            <button type="submit" class="admin-form-submit">Crear runa</button>
        </form>
    </main>
</div>
<script>
function toggleAdminNav(){var s=document.getElementById("admin-sidebar"),o=document.getElementById("admin-nav-overlay"),b=document.getElementById("admin-hamburger"),open=s.classList.toggle("open");o.classList.toggle("visible",open);b.innerHTML=open?"&#10005;":"&#9776;";}
function cerrarAdminNav(){document.getElementById("admin-sidebar").classList.remove("open");document.getElementById("admin-nav-overlay").classList.remove("visible");document.getElementById("admin-hamburger").innerHTML="&#9776;";}
</script>
<script src="../JS/abbr-input.js"></script>
</body>
</html>