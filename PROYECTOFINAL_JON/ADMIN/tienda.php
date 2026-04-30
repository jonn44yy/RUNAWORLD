<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

$stmt = $conexion->prepare("SELECT * FROM mejoras ORDER BY tipo ASC, coste_base ASC");
$stmt->execute();
$mejoras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conexion->close();

$etiquetas_tipo = [
    "coins_seg"        => "Coins/seg",
    "coins_seg_multi"  => "x Coins/seg",
    "points_seg"       => "Points/seg",
    "points_seg_multi" => "x Points/seg",
    "bulk"             => "Bulk"
];

$colores_tipo = [
    "coins_seg"        => "#ffd700",
    "coins_seg_multi"  => "#ffaa00",
    "points_seg"       => "#c8d8f0",
    "points_seg_multi" => "#6a9fff",
    "bulk"             => "#ff7788"
];

function fmtNum($n) {
    if ($n >= 1e9)  return number_format($n/1e9, 2) . "B";
    if ($n >= 1e6)  return number_format($n/1e6, 2) . "M";
    if ($n >= 1e3)  return number_format($n/1e3, 2) . "k";
    return number_format($n, 0);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Admin Tienda</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .mejoras-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px;
        }

        .mejora-admin-card {
            background: rgba(60,120,255,0.04);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }

        .mejora-admin-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--tipo-color, var(--blue)), transparent);
        }

        .mejora-admin-card:hover {
            border-color: rgba(60,120,255,0.4);
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.4);
        }

        .mejora-tipo-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 2px;
            font-family: var(--font-title);
            font-size: 0.62rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: 1px solid currentColor;
            align-self: flex-start;
        }

        .mejora-nombre {
            font-family: var(--font-title);
            font-size: 1.05rem;
            letter-spacing: 2px;
            color: var(--blue-bright);
        }

        .mejora-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            font-size: 0.85rem;
        }

        .mejora-meta-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .mejora-meta-label {
            font-family: var(--font-title);
            font-size: 0.6rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--silver-dim);
        }

        .mejora-meta-valor {
            color: var(--silver);
            font-family: var(--font-title);
            font-size: 0.9rem;
        }

        .mejora-desc {
            font-size: 0.88rem;
            color: var(--silver-dim);
            line-height: 1.5;
            flex: 1;
        }

        .mejora-acciones {
            display: flex;
            gap: 8px;
            margin-top: 4px;
        }
    </style>
</head>
<body>
<!-- HAMBURGER -->
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
            </svg>
            <div id="sidebar-logo-titulo">RunaWorld</div>
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
        <div class="admin-page-titulo">Tienda</div>
        <div class="admin-page-sub">Gestion de mejoras disponibles para los jugadores</div>
        <div class="admin-separador"></div>

        <a href="crear_mejora.php" class="btn-crear">+ Crear nueva mejora</a>

        <?php if (empty($mejoras)): ?>
            <p style="color:var(--silver-dim);">No hay mejoras creadas.</p>
        <?php else: ?>
            <div class="mejoras-grid">
                <?php foreach ($mejoras as $m):
                    $color = $colores_tipo[$m["tipo"]] ?? "var(--blue)";
                    $tipo_label = $etiquetas_tipo[$m["tipo"]] ?? $m["tipo"];
                ?>
                    <div class="mejora-admin-card" style="--tipo-color: <?= $color ?>;">
                        <span class="mejora-tipo-badge" style="color:<?= $color ?>;">
                            <?= $tipo_label ?>
                        </span>
                        <div class="mejora-nombre"><?= htmlspecialchars($m["nombre"]) ?></div>
                        <div class="mejora-desc"><?= htmlspecialchars($m["descripcion"] ?? "") ?></div>
                        <div class="mejora-meta">
                            <div class="mejora-meta-item">
                                <span class="mejora-meta-label">Coste base</span>
                                <span class="mejora-meta-valor" style="color:var(--gold);">
                                    <?= fmtNum($m["coste_base"]) ?> pts
                                </span>
                            </div>
                            <div class="mejora-meta-item">
                                <span class="mejora-meta-label">Escala</span>
                                <span class="mejora-meta-valor">x<?= $m["coste_escala"] ?></span>
                            </div>
                            <div class="mejora-meta-item">
                                <span class="mejora-meta-label">Valor</span>
                                <span class="mejora-meta-valor" style="color:<?= $color ?>;">+<?= rtrim(rtrim(number_format($m["valor"], 4), '0'), '.') ?></span>
                            </div>
                            <div class="mejora-meta-item">
                                <span class="mejora-meta-label">Nivel max</span>
                                <span class="mejora-meta-valor"><?= $m["nivel_maximo"] ?></span>
                            </div>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="badge <?= $m['activa'] ? 'badge-si' : 'badge-no' ?>">
                                <?= $m["activa"] ? "Activa" : "Inactiva" ?>
                            </span>
                        </div>
                        <div class="mejora-acciones">
                            <a href="editar_mejora.php?id=<?= $m["id"] ?>"
                               class="btn-admin btn-admin-primary">Editar</a>
                            <a href="../PHP/mejoras_action.php?accion=eliminar&id=<?= $m["id"] ?>"
                               class="btn-admin btn-admin-danger"
                               onclick="return confirm('Eliminar mejora <?= htmlspecialchars($m["nombre"]) ?>?')">
                               Eliminar
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

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
