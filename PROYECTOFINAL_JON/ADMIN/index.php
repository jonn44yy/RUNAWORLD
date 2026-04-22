<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

$mostrar_intro = !isset($_SESSION["admin_intro_visto"]);
$_SESSION["admin_intro_visto"] = true;

$stmt = $conexion->prepare("SELECT COUNT(*) as total FROM usuarios WHERE rol = 'usuario'");
$stmt->execute();
$total_usuarios = $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$stmt = $conexion->prepare("SELECT COUNT(*) as total FROM mensajes WHERE leido = 0");
$stmt->execute();
$mensajes_nuevos = $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$stmt = $conexion->prepare("SELECT COUNT(*) as total FROM runas WHERE activa = 1");
$stmt->execute();
$total_runas = $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$stmt = $conexion->prepare("SELECT COUNT(*) as total FROM jugadores WHERE ultima_actualizacion > NOW() - INTERVAL 2 MINUTE");
$stmt->execute();
$activos = $stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$stmt = $conexion->prepare("SELECT u.username, j.points FROM jugadores j INNER JOIN usuarios u ON j.usuario_id = u.id ORDER BY j.points DESC LIMIT 1");
$stmt->execute();
$top = $stmt->get_result()->fetch_assoc();
$stmt->close();

$conexion->close();

$runa_svg = '
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
<g font-size="13" fill="currentColor" opacity="0.9" font-family="serif" text-anchor="middle">
    <text transform="rotate(0,200,200)   translate(200,22)">ᚠ</text>
    <text transform="rotate(45,200,200)  translate(200,22)">ᚢ</text>
    <text transform="rotate(90,200,200)  translate(200,22)">ᚦ</text>
    <text transform="rotate(135,200,200) translate(200,22)">ᚨ</text>
    <text transform="rotate(180,200,200) translate(200,22)">ᚱ</text>
    <text transform="rotate(225,200,200) translate(200,22)">ᚲ</text>
    <text transform="rotate(270,200,200) translate(200,22)">ᚷ</text>
    <text transform="rotate(315,200,200) translate(200,22)">ᚹ</text>
</g>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Admin</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        /* Canvas de la onda — solo visible durante intro */
        #wave-canvas {
            position: fixed;
            inset: 0;
            z-index: 99998;
            pointer-events: none;
            display: <?= $mostrar_intro ? 'block' : 'none' ?>;
        }

        /* Runa y título del intro */
        #intro-runa-svg {
            position: fixed;
            z-index: 99999;
            pointer-events: none;
            opacity: 0;
            filter:
                drop-shadow(0 0 20px rgba(60,120,255,0.9))
                drop-shadow(0 0 60px rgba(60,120,255,0.5));
            display: <?= $mostrar_intro ? 'block' : 'none' ?>;
        }

        #intro-titulo {
            position: fixed;
            z-index: 99999;
            font-family: 'Oswald', sans-serif;
            font-size: clamp(2rem, 6vw, 5rem);
            letter-spacing: 12px;
            text-transform: uppercase;
            color: #6a9fff;
            text-shadow: 0 0 25px rgba(60,120,255,0.5);
            opacity: 0;
            pointer-events: none;
            display: <?= $mostrar_intro ? 'block' : 'none' ?>;
        }
    </style>
</head>
<body>

<!-- HAMBURGER -->
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>
<!-- CANVAS ONDA -->
<canvas id="wave-canvas"></canvas>

<!-- RUNA INTRO -->
<svg id="intro-runa-svg" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
    <?= $runa_svg ?>
</svg>

<!-- TÍTULO INTRO -->
<div id="intro-titulo">RunaWorld</div>

<!-- LAYOUT ADMIN -->
<div id="admin-layout" class="visible">

    <aside id="admin-sidebar">
        <div id="sidebar-logo">
            <svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
                <?= $runa_svg ?>
            </svg>
            <div id="sidebar-logo-titulo">RunaWorld</div>
        </div>

        <nav class="admin-nav">
            <a href="index.php"    class="admin-nav-btn active"><span class="nav-icon">⬡</span> Dashboard</a>
            <a href="usuarios.php" class="admin-nav-btn"><span class="nav-icon">◈</span> Usuarios</a>
            <a href="runas.php"    class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn">
                <span class="nav-icon">✉</span> Mensajes
                <?php if ($mensajes_nuevos > 0): ?>
                    <span class="badge badge-new" style="margin-left:auto;"><?= $mensajes_nuevos ?></span>
                <?php endif; ?>
            </a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Dashboard</div>
        <div class="admin-page-sub">Bienvenido, <?= htmlspecialchars($_SESSION["username"]) ?> — Panel de administracion</div>
        <div class="admin-separador"></div>

        <div class="dashboard-grid">
            <div class="dash-card">
                <div class="dash-card-valor"><?= $total_usuarios ?></div>
                <div class="dash-card-label">Usuarios registrados</div>
                <a href="usuarios.php" class="dash-card-link">Ver todos →</a>
            </div>
            <div class="dash-card">
                <div class="dash-card-valor" id="activos-count"><?= $activos ?></div>
                <div class="dash-card-label">Jugadores activos ahora</div>
            </div>
            <div class="dash-card">
                <div class="dash-card-valor"><?= $mensajes_nuevos ?></div>
                <div class="dash-card-label">Mensajes sin leer</div>
                <a href="mensajes.php" class="dash-card-link">Ver mensajes →</a>
            </div>
            <div class="dash-card">
                <div class="dash-card-valor"><?= $total_runas ?></div>
                <div class="dash-card-label">Runas activas</div>
                <a href="runas.php" class="dash-card-link">Gestionar →</a>
            </div>
            <?php if ($top): ?>
            <div class="dash-card">
                <div class="dash-card-valor" style="font-size:1.5rem;"><?= htmlspecialchars($top["username"]) ?></div>
                <div class="dash-card-label">Top jugador por points</div>
            </div>
            <?php endif; ?>
        </div>
    </main>

</div>

<script src="../JS/animaciones.js"></script>
<?php if ($mostrar_intro): ?>
<script>iniciarAnimacionAdmin();</script>
<?php endif; ?>

<script>
function toggleAdminNav() {
    var s = document.getElementById("admin-sidebar");
    var o = document.getElementById("admin-nav-overlay");
    var b = document.getElementById("admin-hamburger");
    var open = s.classList.toggle("open");
    o.classList.toggle("visible", open);
    b.innerHTML = open ? "&#10005;" : "&#9776;";
}
function cerrarAdminNav() {
    document.getElementById("admin-sidebar").classList.remove("open");
    document.getElementById("admin-nav-overlay").classList.remove("visible");
    document.getElementById("admin-hamburger").innerHTML = "&#9776;";
}
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".admin-nav-btn").forEach(function(a) {
        a.addEventListener("click", function() {
            if (window.innerWidth <= 700) cerrarAdminNav();
        });
    });
});
</script>
</body>
</html>
