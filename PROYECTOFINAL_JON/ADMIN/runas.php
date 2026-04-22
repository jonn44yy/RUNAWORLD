<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

// Toggle eliminacion rapida
if (isset($_GET["toggle_rapido"])) {
    $_SESSION["eliminar_rapido"] = !($_SESSION["eliminar_rapido"] ?? false);
    header("Location: runas.php");
    exit;
}

$eliminar_rapido = $_SESSION["eliminar_rapido"] ?? false;

// Cargar grupos con sus runas
$stmt = $conexion->prepare("SELECT * FROM grupos_runas ORDER BY id ASC");
$stmt->execute();
$grupos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$runas_por_grupo = [];
foreach ($grupos as $grupo) {
    $stmt = $conexion->prepare("
        SELECT * FROM runas WHERE grupo_id = ?
        ORDER BY FIELD(rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')
    ");
    $stmt->bind_param("i", $grupo["id"]);
    $stmt->execute();
    $runas_por_grupo[$grupo["id"]] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Peso total global (todas las runas activas) para % real
$stmt = $conexion->prepare("SELECT COALESCE(SUM(peso), 1) as total FROM runas WHERE activa = 1");
$stmt->execute();
$peso_total_global = (float)$stmt->get_result()->fetch_assoc()["total"];
$stmt->close();

$conexion->close();

$colores_rareza = [
    'eterna'     => '#b882ff',
    'divina'     => '#fffff0',
    'mitica'     => '#ff2244',
    'legendaria' => '#ffaa00',
    'epica'      => '#cc44ff',
    'rara'       => '#3c78ff',
    'poco_comun' => '#44ff88',
    'comun'      => '#c8d8f0',
];

// Formateo compacto de números: 500M, 1.5B, 25k — sin ceros sobrantes
function fmtNum($n) {
    $n = floatval($n);
    $strip = function($x) { return rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.'); };
    if ($n >= 1e12) return $strip($n/1e12) . 'T';
    if ($n >= 1e9)  return $strip($n/1e9)  . 'B';
    if ($n >= 1e6)  return $strip($n/1e6)  . 'M';
    if ($n >= 1e3)  return $strip($n/1e3)  . 'k';
    if ($n > 0 && $n < 1) return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    return number_format($n, 0);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Admin Runas</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .grupos-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 900px) {
            .grupos-grid { grid-template-columns: 1fr; }
        }

        .grupo-card {
            background: rgba(60,120,255,0.04);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 22px;
        }

        .grupo-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        .grupo-card-nombre {
            font-family: var(--font-title);
            font-size: 1rem;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: var(--blue-bright);
        }

        .rareza-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 2px;
            font-family: var(--font-title);
            font-size: 0.6rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: 1px solid currentColor;
        }

        .acciones-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: center;
        }

        .toggle-rapido {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: var(--font-title);
            font-size: 0.72rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--silver-dim);
            text-decoration: none;
            padding: 9px 16px;
            border: 1px solid var(--border);
            border-radius: 3px;
            transition: all 0.2s;
        }

        .toggle-rapido:hover { border-color: var(--blue); color: var(--blue-bright); }
        .toggle-rapido.on { border-color: #ff7788; color: #ff7788; }
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
            <a href="runas.php"    class="admin-nav-btn active"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">

        <div class="admin-page-titulo">Runas</div>
        <div class="admin-page-sub">Gestion del catalogo de runas por grupos</div>
        <div class="admin-separador"></div>

        <!-- BARRA DE ACCIONES -->
        <div class="acciones-bar">
            <a href="gestionar_grupos.php" class="btn-crear">+ Nueva lista de runas</a>
            <a href="runas.php?toggle_rapido=1"
               class="toggle-rapido <?= $eliminar_rapido ? 'on' : '' ?>">
                Eliminacion rapida: <strong><?= $eliminar_rapido ? 'ON' : 'OFF' ?></strong>
            </a>
        </div>

        <!-- GRID DE GRUPOS -->
        <?php if (empty($grupos)): ?>
            <p style="color:var(--silver-dim);">No hay listas de runas. Crea una primera.</p>
        <?php else: ?>
            <div class="grupos-grid">
                <?php foreach ($grupos as $grupo): ?>
                    <div class="grupo-card">
                        <div class="grupo-card-header">
                            <div class="grupo-card-nombre"><?= htmlspecialchars($grupo["nombre"]) ?></div>
                            <a href="crear_runa.php?grupo_id=<?= $grupo["id"] ?>"
                               class="btn-admin btn-admin-primary">+ Runa</a>
                        </div>

                        <?php if (empty($runas_por_grupo[$grupo["id"]])): ?>
                            <p style="color:var(--silver-dim); font-size:0.9rem;">No hay runas en este grupo.</p>
                        <?php else: ?>
                            <div class="admin-tabla-wrapper">
                                <table class="admin-tabla">
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>Rareza</th>
                                            <th>Prob. base</th>
                                            <th>Multi</th>
                                            <th>Activa</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($runas_por_grupo[$grupo["id"]] as $r): ?>
                                            <tr>
                                                <td style="font-family:var(--font-title); letter-spacing:1px;
                                                    color:<?= $colores_rareza[$r['rareza']] ?? 'var(--silver)' ?>;">
                                                    <?= htmlspecialchars($r["nombre"]) ?>
                                                </td>
                                                <td>
                                                    <span class="rareza-badge"
                                                          style="color:<?= $colores_rareza[$r['rareza']] ?? 'var(--silver)' ?>">
                                                        <?= htmlspecialchars($r["rareza"]) ?>
                                                    </span>
                                                </td>
                                                <td style="color:var(--silver-dim);" title="Peso: <?= $r["peso"] ?>">
                                                    <?php
                                                        $pct = $peso_total_global > 0 ? ($r["peso"] / $peso_total_global * 100) : 0;
                                                        if ($pct >= 1)       echo number_format($pct, 2) . "%";
                                                        elseif ($pct >= 0.01)    echo number_format($pct, 4) . "%";
                                                        elseif ($pct >= 0.0001)  echo number_format($pct, 6) . "%";
                                                        else                     echo number_format($pct, 7) . "%";
                                                    ?>
                                                </td>
                                                <td style="color:var(--gold);" title="Valor exacto: <?= $r["multiplicador"] ?>"><?= fmtNum($r["multiplicador"]) ?></td>
                                                <td>
                                                    <span class="badge <?= $r['activa'] ? 'badge-si' : 'badge-no' ?>">
                                                        <?= $r["activa"] ? "Si" : "No" ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div style="display:flex; gap:6px;">
                                                        <a href="editar_runa.php?id=<?= $r["id"] ?>"
                                                           class="btn-admin btn-admin-primary">Editar</a>
                                                        <?php if ($eliminar_rapido): ?>
                                                            <a href="../PHP/runas_action.php?accion=eliminar&id=<?= $r["id"] ?>"
                                                               class="btn-admin btn-admin-danger">Eliminar</a>
                                                        <?php else: ?>
                                                            <a href="../PHP/runas_action.php?accion=eliminar&id=<?= $r["id"] ?>"
                                                               class="btn-admin btn-admin-danger"
                                                               onclick="return confirm('Eliminar <?= htmlspecialchars($r["nombre"]) ?>?')">
                                                               Eliminar
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>
</div>

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
