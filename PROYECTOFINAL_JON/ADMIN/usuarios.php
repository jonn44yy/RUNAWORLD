<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

$buscar = isset($_GET["buscar"]) ? trim($_GET["buscar"]) : "";

if ($buscar !== "") {
    $stmt = $conexion->prepare("
        SELECT u.id, u.username, u.email, u.genero, u.fecha_registro,
               j.coins, j.points, j.coins_por_seg, j.points_por_seg
        FROM usuarios u
        LEFT JOIN jugadores j ON u.id = j.usuario_id
        WHERE u.rol = 'usuario' AND u.username LIKE ?
        ORDER BY u.id ASC
    ");
    $like = "%" . $buscar . "%";
    $stmt->bind_param("s", $like);
} else {
    $stmt = $conexion->prepare("
        SELECT u.id, u.username, u.email, u.genero, u.fecha_registro,
               j.coins, j.points, j.coins_por_seg, j.points_por_seg
        FROM usuarios u
        LEFT JOIN jugadores j ON u.id = j.usuario_id
        WHERE u.rol = 'usuario'
        ORDER BY u.id ASC
    ");
}

$stmt->execute();
$usuarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conexion->close();

function fmtNum($n) {
    if ($n >= 1e9)  return number_format($n/1e9, 2) . "B";
    if ($n >= 1e6)  return number_format($n/1e6, 2) . "M";
    if ($n >= 1e3)  return number_format($n/1e3, 2) . "k";
    return number_format($n, 0);
}

// Sidebar SVG reutilizable
$sidebar_svg = '
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
                <g font-size="13" fill="currentColor" opacity="0.9" font-family="serif" text-anchor="middle">
                    <text transform="rotate(0,200,200)   translate(200,22)">&#5792;</text>
                    <text transform="rotate(45,200,200)  translate(200,22)">&#5794;</text>
                    <text transform="rotate(90,200,200)  translate(200,22)">&#5798;</text>
                    <text transform="rotate(135,200,200) translate(200,22)">&#5800;</text>
                    <text transform="rotate(180,200,200) translate(200,22)">&#5809;</text>
                    <text transform="rotate(225,200,200) translate(200,22)">&#5810;</text>
                    <text transform="rotate(270,200,200) translate(200,22)">&#5815;</text>
                    <text transform="rotate(315,200,200) translate(200,22)">&#5817;</text>
                </g>
            </svg>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Admin Usuarios</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .admin-tabla-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        .admin-tabla { width: 100%; border-collapse: collapse; }

        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
        }

        .tab-btn {
            padding: 11px 26px;
            font-family: var(--font-title);
            font-size: 0.82rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--silver-dim);
            cursor: pointer;
            border-radius: 3px;
            transition: all 0.2s;
        }

        .tab-btn.active {
            border-color: var(--blue);
            color: var(--blue-bright);
            background: rgba(60,120,255,0.1);
        }

        .tabla-panel { display: none; }
        .tabla-panel.activa { display: block; }
    </style>
</head>
<body>
<!-- HAMBURGER -->
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>
<div id="admin-layout" class="visible">

    <aside id="admin-sidebar">
        <div id="sidebar-logo">
            <?= $sidebar_svg ?>
            <div id="sidebar-logo-titulo">RunaWorld</div>
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

        <div class="admin-page-titulo">Usuarios</div>
        <div class="admin-page-sub">Gestion de jugadores registrados — <?= count($usuarios) ?> usuarios</div>
        <div class="admin-separador"></div>

        <div class="admin-filtros">
            <form method="GET" action="">
                <input type="text" name="buscar" class="admin-search"
                       placeholder="Buscar por usuario..."
                       value="<?= htmlspecialchars($buscar) ?>">
                <button type="submit" class="btn-admin btn-admin-primary">Buscar</button>
                <?php if ($buscar !== ""): ?>
                    <a href="usuarios.php" class="btn-admin btn-admin-primary">Limpiar</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($usuarios)): ?>
            <p style="color:var(--silver-dim); font-size:1rem;">No hay usuarios registrados.</p>
        <?php else: ?>

            <!-- TABS -->
            <div class="tabs">
                <button class="tab-btn active" onclick="cambiarTab('cuenta', this)">Cuenta</button>
                <button class="tab-btn" onclick="cambiarTab('progreso', this)">Progreso del jugador</button>
            </div>

            <!-- TABLA CUENTA -->
            <div id="tab-cuenta" class="tabla-panel activa">
                <div class="admin-tabla-wrapper">
                    <table class="admin-tabla">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Email</th>
                                <th>Genero</th>
                                <th>Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <tr>
                                    <td style="color:var(--silver-dim);">#<?= $u["id"] ?></td>
                                    <td style="font-family:var(--font-title); color:var(--blue-bright);">
                                        <?= htmlspecialchars($u["username"]) ?>
                                    </td>
                                    <td style="color:var(--silver-dim);">
                                        <?= htmlspecialchars($u["email"]) ?>
                                    </td>
                                    <td><?= htmlspecialchars($u["genero"]) ?></td>
                                    <td style="color:var(--silver-dim);">
                                        <?= date("d/m/Y", strtotime($u["fecha_registro"])) ?>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                            <a href="editar_cuenta.php?id=<?= $u["id"] ?>"
                                               class="btn-admin btn-admin-primary">Editar</a>
                                            <a href="../PHP/borrar_usuario.php?id=<?= $u["id"] ?>"
                                               class="btn-admin btn-admin-danger"
                                               onclick="return confirm('Eliminar usuario <?= htmlspecialchars($u["username"]) ?>?')">
                                               Eliminar
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TABLA PROGRESO -->
            <div id="tab-progreso" class="tabla-panel">
                <div class="admin-tabla-wrapper">
                    <table class="admin-tabla">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Coins</th>
                                <th>Points</th>
                                <th>Coins/s</th>
                                <th>Points/s</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <tr>
                                    <td style="color:var(--silver-dim);">#<?= $u["id"] ?></td>
                                    <td style="font-family:var(--font-title); color:var(--blue-bright);">
                                        <?= htmlspecialchars($u["username"]) ?>
                                    </td>
                                    <td style="font-family:var(--font-title); color:var(--gold);">
                                        <?= fmtNum($u["coins"] ?? 0) ?>
                                    </td>
                                    <td style="font-family:var(--font-title); color:var(--silver);">
                                        <?= fmtNum($u["points"] ?? 0) ?>
                                    </td>
                                    <td style="color:var(--silver-dim);">
                                        <?= fmtNum($u["coins_por_seg"] ?? 0) ?>
                                    </td>
                                    <td style="color:var(--silver-dim);">
                                        <?= fmtNum($u["points_por_seg"] ?? 0) ?>
                                    </td>
                                    <td>
                                        <a href="editar_progreso.php?id=<?= $u["id"] ?>"
                                           class="btn-admin btn-admin-primary">Editar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php endif; ?>

    </main>
</div>

<script>
function cambiarTab(id, btn) {
    document.querySelectorAll(".tabla-panel").forEach(p => p.classList.remove("activa"));
    document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
    document.getElementById("tab-" + id).classList.add("activa");
    btn.classList.add("active");
}
</script>

<script src="../JS/admin-mobile.js"></script>
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
