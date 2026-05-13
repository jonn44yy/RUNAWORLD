<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

$mostrar_intro = !isset($_SESSION["admin_intro_visto"]);
$_SESSION["admin_intro_visto"] = true;

function qOne($conexion, $sql, $key = "total") {
    $stmt = $conexion->prepare($sql);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row[$key] ?? 0;
}

function qAll($conexion, $sql) {
    $stmt = $conexion->prepare($sql);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$total_usuarios = qOne($conexion, "SELECT COUNT(*) as total FROM usuarios WHERE rol = 'usuario'");
$total_admins = qOne($conexion, "SELECT COUNT(*) as total FROM usuarios WHERE rol = 'admin'");
$mensajes_nuevos = qOne($conexion, "SELECT COUNT(*) as total FROM mensajes WHERE leido = 0");
$total_runas = qOne($conexion, "SELECT COUNT(*) as total FROM runas WHERE activa = 1");
$total_runas_all = qOne($conexion, "SELECT COUNT(*) as total FROM runas");
$total_jugadores = qOne($conexion, "SELECT COUNT(*) as total FROM jugadores");
$activos = qOne($conexion, "SELECT COUNT(*) as total FROM jugadores WHERE ultima_actualizacion > NOW() - INTERVAL 2 MINUTE");
$nuevos_7d = qOne($conexion, "SELECT COUNT(*) as total FROM usuarios WHERE fecha_registro >= NOW() - INTERVAL 7 DAY");
$total_points = qOne($conexion, "SELECT COALESCE(SUM(points),0) as total FROM jugadores");
$avg_points = qOne($conexion, "SELECT COALESCE(AVG(points),0) as total FROM jugadores");
$avg_pps = qOne($conexion, "SELECT COALESCE(AVG(points_por_seg),0) as total FROM jugadores");

$top = qAll($conexion, "
    SELECT u.username, j.points
    FROM jugadores j
    INNER JOIN usuarios u ON j.usuario_id = u.id
    ORDER BY j.points DESC
    LIMIT 5
");

$top_pps = qAll($conexion, "
    SELECT u.username, j.points_por_seg
    FROM jugadores j
    INNER JOIN usuarios u ON j.usuario_id = u.id
    ORDER BY j.points_por_seg DESC
    LIMIT 5
");

$runas_rareza = qAll($conexion, "
    SELECT rareza, COUNT(*) as total
    FROM runas
    WHERE activa = 1
    GROUP BY rareza
    ORDER BY FIELD(rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')
");

$usuarios_dias = qAll($conexion, "
    SELECT DATE(fecha_registro) as dia, COUNT(*) as total
    FROM usuarios
    WHERE fecha_registro >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(fecha_registro)
    ORDER BY dia ASC
");

$conexion->close();

$actividad_pct = $total_jugadores > 0 ? round(($activos / $total_jugadores) * 100, 1) : 0;
$runas_activas_pct = $total_runas_all > 0 ? round(($total_runas / $total_runas_all) * 100, 1) : 0;

function fmtNumDash($n) {
    $n = floatval($n);
    if ($n >= 1e12) return rtrim(rtrim(number_format($n / 1e12, 2, '.', ''), '0'), '.') . 'T';
    if ($n >= 1e9)  return rtrim(rtrim(number_format($n / 1e9, 2, '.', ''), '0'), '.') . 'B';
    if ($n >= 1e6)  return rtrim(rtrim(number_format($n / 1e6, 2, '.', ''), '0'), '.') . 'M';
    if ($n >= 1e3)  return rtrim(rtrim(number_format($n / 1e3, 2, '.', ''), '0'), '.') . 'k';
    if ($n > 0 && $n < 1) return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    return number_format($n, 0);
}

$chartData = [
    "resumen" => [
        "usuarios" => (int)$total_usuarios,
        "admins" => (int)$total_admins,
        "jugadores" => (int)$total_jugadores,
        "activos" => (int)$activos,
        "actividad_pct" => (float)$actividad_pct,
        "mensajes" => (int)$mensajes_nuevos,
        "runas_activas" => (int)$total_runas,
        "runas_total" => (int)$total_runas_all,
        "runas_activas_pct" => (float)$runas_activas_pct,
        "nuevos_7d" => (int)$nuevos_7d,
        "points_total" => (float)$total_points,
        "points_avg" => (float)$avg_points,
        "avg_pps" => (float)$avg_pps
    ],
    "top" => $top,
    "top_pps" => $top_pps,
    "runas_rareza" => $runas_rareza,
    "usuarios_dias" => $usuarios_dias
];

$runa_svg = '
<circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/>
<circle cx="200" cy="200" r="145" fill="none" stroke="currentColor" stroke-width="0.7" opacity="0.7"/>
<circle cx="200" cy="200" r="80"  fill="none" stroke="currentColor" stroke-width="1" opacity="0.8"/>
<g stroke="currentColor" stroke-width="2" opacity="1" stroke-linecap="round">
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
    <circle cx="200" cy="200" r="25" fill="none"/>
    <circle cx="200" cy="200" r="5" fill="currentColor"/>
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
#wave-canvas {
    position: fixed;
    inset: 0;
    z-index: 99998;
    pointer-events: none;
    display: <?= $mostrar_intro ? 'block' : 'none' ?>;
}

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

/* ===== NUEVO ESTILO DASHBOARD ===== */

.admin-hero {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(60,120,255,0.20);
    background:
        radial-gradient(circle at 12% 0%, rgba(106,159,255,0.16), transparent 34%),
        radial-gradient(circle at 85% 100%, rgba(60,120,255,0.10), transparent 38%),
        linear-gradient(135deg, rgba(8,12,28,0.98), rgba(4,6,14,0.96));
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 18px 42px rgba(0,0,0,0.35);
}

.admin-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(60,120,255,0.055) 1px, transparent 1px),
        linear-gradient(90deg, rgba(60,120,255,0.055) 1px, transparent 1px);
    background-size: 42px 42px;
    mask-image: radial-gradient(circle at center, black, transparent 75%);
    pointer-events: none;
}

.admin-hero-title {
    position: relative;
    z-index: 2;
    font-family: var(--font-title);
    font-size: clamp(1.6rem, 3vw, 2.4rem);
    letter-spacing: 5px;
    text-transform: uppercase;
    color: var(--blue-bright);
    text-shadow: 0 0 18px rgba(60,120,255,0.55);
}

.admin-hero-sub {
    position: relative;
    z-index: 2;
    margin-top: 8px;
    color: var(--silver-dim);
    font-size: 1rem;
    font-style: italic;
}

.hero-kpis {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-top: 22px;
}

.hero-kpi {
    border: 1px solid rgba(60,120,255,0.18);
    background: rgba(0,0,0,0.22);
    border-radius: 10px;
    padding: 16px;
    min-height: 96px;
}

.hero-kpi-value {
    font-family: var(--font-title);
    font-size: 1.9rem;
    line-height: 1;
    color: var(--blue-bright);
    text-shadow: 0 0 14px rgba(60,120,255,0.45);
}

.hero-kpi-label {
    margin-top: 9px;
    font-family: var(--font-title);
    font-size: 0.66rem;
    letter-spacing: 2.4px;
    text-transform: uppercase;
    color: var(--silver-dim);
}

.admin-section-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(320px, 0.75fr);
    gap: 18px;
    margin-top: 18px;
}

.admin-section-grid.equal {
    grid-template-columns: 1fr 1fr;
}

.admin-panel {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(60,120,255,0.18);
    background:
        radial-gradient(circle at 0% 0%, rgba(60,120,255,0.10), transparent 34%),
        linear-gradient(180deg, rgba(8,12,28,0.94), rgba(3,5,12,0.96));
    border-radius: 14px;
    padding: 18px;
    box-shadow: 0 14px 34px rgba(0,0,0,0.30);
}

.admin-panel::before {
    content: '';
    position: absolute;
    left: 18px;
    right: 18px;
    top: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(106,159,255,0.85), transparent);
}

.panel-title {
    position: relative;
    z-index: 2;
    font-family: var(--font-title);
    font-size: 0.9rem;
    letter-spacing: 3px;
    text-transform: uppercase;
    color: var(--blue-bright);
    margin-bottom: 7px;
}

.panel-sub {
    position: relative;
    z-index: 2;
    color: var(--silver-dim);
    font-size: 0.92rem;
    font-style: italic;
    margin-bottom: 16px;
}

.panel-canvas {
    position: relative;
    z-index: 2;
    width: 100%;
    height: 280px;
    display: block;
}

.panel-canvas.small {
    height: 210px;
}

.panel-canvas.tall {
    height: 330px;
}

.mini-stats {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-top: 14px;
}

.mini-stat {
    background: rgba(0,0,0,0.22);
    border: 1px solid rgba(60,120,255,0.13);
    border-radius: 10px;
    padding: 13px;
}

.mini-stat-value {
    font-family: var(--font-title);
    font-size: 1.35rem;
    color: var(--blue-bright);
    line-height: 1;
}

.mini-stat-label {
    margin-top: 8px;
    font-family: var(--font-title);
    font-size: 0.6rem;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--silver-dim);
}

.rank-list {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.rank-row {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    background: rgba(0,0,0,0.22);
    border: 1px solid rgba(60,120,255,0.12);
    border-radius: 10px;
    padding: 11px 12px;
}

.rank-num {
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(60,120,255,0.12);
    border: 1px solid rgba(60,120,255,0.24);
    font-family: var(--font-title);
    color: var(--gold);
    font-size: 0.78rem;
}

.rank-name {
    min-width: 0;
    font-family: var(--font-title);
    letter-spacing: 1.4px;
    color: var(--silver);
    font-size: 0.9rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.rank-value {
    font-family: var(--font-title);
    font-size: 0.82rem;
    color: var(--blue-bright);
    letter-spacing: 1px;
    white-space: nowrap;
}

.empty-panel-text {
    color: var(--silver-dim);
    position: relative;
    z-index: 2;
    font-size: 0.95rem;
    font-style: italic;
}

@media (max-width: 1200px) {
    .hero-kpis {
        grid-template-columns: repeat(2, 1fr);
    }

    .admin-section-grid,
    .admin-section-grid.equal {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .hero-kpis,
    .mini-stats {
        grid-template-columns: 1fr;
    }

    .admin-hero {
        padding: 18px;
    }

    .panel-canvas {
        height: 230px;
    }

    .panel-canvas.small {
        height: 190px;
    }

    .panel-canvas.tall {
        height: 360px;
    }
}
</style>
</head>

<body>

<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>

<canvas id="wave-canvas"></canvas>

<svg id="intro-runa-svg" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
    <?= $runa_svg ?>
</svg>

<div id="intro-titulo">RunaWorld</div>

<div id="admin-layout" class="visible">

    <aside id="admin-sidebar">
        <div id="sidebar-logo">
            <svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
                <?= $runa_svg ?>
            </svg>
            <div id="sidebar-logo-titulo">RunaWorld</div>
        </div>

        <nav class="admin-nav">
            <a href="index.php" class="admin-nav-btn active"><span class="nav-icon">⬡</span> Dashboard</a>
            <a href="usuarios.php" class="admin-nav-btn"><span class="nav-icon">◈</span> Usuarios</a>
            <a href="runas.php" class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php" class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
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

        <section class="admin-hero">
            <div class="admin-hero-title">Dashboard</div>
            <div class="admin-hero-sub">
                Bienvenido, <?= htmlspecialchars($_SESSION["username"]) ?> — control visual del estado actual de RunaWorld.
            </div>

            <div class="hero-kpis">
                <div class="hero-kpi">
                    <div class="hero-kpi-value"><?= $actividad_pct ?>%</div>
                    <div class="hero-kpi-label">Actividad actual</div>
                </div>

                <div class="hero-kpi">
                    <div class="hero-kpi-value"><?= fmtNumDash($total_points) ?></div>
                    <div class="hero-kpi-label">Points globales</div>
                </div>

                <div class="hero-kpi">
                    <div class="hero-kpi-value"><?= $total_runas ?>/<?= $total_runas_all ?></div>
                    <div class="hero-kpi-label">Catálogo activo</div>
                </div>

                <div class="hero-kpi">
                    <div class="hero-kpi-value"><?= $mensajes_nuevos ?></div>
                    <div class="hero-kpi-label">Mensajes pendientes</div>
                </div>
            </div>
        </section>

        <div class="admin-section-grid">
            <section class="admin-panel">
                <div class="panel-title">Núcleo de actividad</div>
                <div class="panel-sub">Usuarios, jugadores, activos, administradores y mensajes sin leer.</div>

                <canvas id="canvasActividad" class="panel-canvas"></canvas>

                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?= $total_usuarios ?></div>
                        <div class="mini-stat-label">Usuarios</div>
                    </div>

                    <div class="mini-stat">
                        <div class="mini-stat-value"><?= $total_jugadores ?></div>
                        <div class="mini-stat-label">Jugadores</div>
                    </div>

                    <div class="mini-stat">
                        <div class="mini-stat-value"><?= $activos ?></div>
                        <div class="mini-stat-label">Activos ahora</div>
                    </div>
                </div>
            </section>

            <section class="admin-panel">
                <div class="panel-title">Ranking de jugadores</div>
                <div class="panel-sub">Top 5 por points acumulados.</div>

                <?php if (empty($top)): ?>
                    <p class="empty-panel-text">Todavía no hay jugadores con progreso.</p>
                <?php else: ?>
                    <div class="rank-list">
                        <?php foreach ($top as $i => $player): ?>
                            <div class="rank-row">
                                <div class="rank-num">#<?= $i + 1 ?></div>
                                <div class="rank-name"><?= htmlspecialchars($player["username"]) ?></div>
                                <div class="rank-value"><?= fmtNumDash($player["points"]) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <canvas id="canvasTopPlayers" class="panel-canvas small"></canvas>
            </section>
        </div>

        <div class="admin-section-grid equal">
            <section class="admin-panel">
                <div class="panel-title">Catálogo de runas</div>
                <div class="panel-sub">Runas activas agrupadas por rareza.</div>
                <canvas id="canvasRarezas" class="panel-canvas tall"></canvas>
            </section>

            <section class="admin-panel">
                <div class="panel-title">Registros recientes</div>
                <div class="panel-sub">Usuarios creados durante los últimos 7 días.</div>
                <canvas id="canvasUsuariosDias" class="panel-canvas tall"></canvas>
            </section>
        </div>

        <div class="admin-section-grid">
            <section class="admin-panel">
                <div class="panel-title">Top generación pasiva</div>
                <div class="panel-sub">Jugadores con mayor producción de points/s.</div>

                <?php if (empty($top_pps)): ?>
                    <p class="empty-panel-text">Todavía no hay datos de generación pasiva.</p>
                <?php else: ?>
                    <div class="rank-list">
                        <?php foreach ($top_pps as $i => $player): ?>
                            <div class="rank-row">
                                <div class="rank-num">#<?= $i + 1 ?></div>
                                <div class="rank-name"><?= htmlspecialchars($player["username"]) ?></div>
                                <div class="rank-value"><?= fmtNumDash($player["points_por_seg"]) ?>/s</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="admin-panel">
                <div class="panel-title">Resumen económico</div>
                <div class="panel-sub">Solo con datos existentes: points acumulados y media por jugador.</div>

                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="mini-stat-value"><?= fmtNumDash($total_points) ?></div>
                        <div class="mini-stat-label">Points totales</div>
                    </div>

                    <div class="mini-stat">
                        <div class="mini-stat-value"><?= fmtNumDash($avg_points) ?></div>
                        <div class="mini-stat-label">Media points</div>
                    </div>

                    <div class="mini-stat">
                        <div class="mini-stat-value"><?= fmtNumDash($avg_pps) ?></div>
                        <div class="mini-stat-label">Media points/s</div>
                    </div>
                </div>
            </section>
        </div>

    </main>
</div>

<script>
const DASHBOARD_DATA = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;

function setupCanvas(canvas) {
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();

    canvas.width = Math.max(1, Math.floor(rect.width * dpr));
    canvas.height = Math.max(1, Math.floor(rect.height * dpr));

    const ctx = canvas.getContext("2d");
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    return { ctx, w: rect.width, h: rect.height };
}

function clear(ctx, w, h) {
    ctx.clearRect(0, 0, w, h);
}

function fmtMetric(n) {
    n = Number(n) || 0;

    if (n >= 1e12) return (n / 1e12).toFixed(1).replace(".0", "") + "T";
    if (n >= 1e9) return (n / 1e9).toFixed(1).replace(".0", "") + "B";
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(".0", "") + "M";
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace(".0", "") + "k";

    return String(Math.round(n));
}

function drawPanelGrid(ctx, w, h) {
    ctx.save();

    ctx.strokeStyle = "rgba(60,120,255,0.07)";
    ctx.lineWidth = 1;

    for (let x = 0; x < w; x += 44) {
        ctx.beginPath();
        ctx.moveTo(x, 0);
        ctx.lineTo(x, h);
        ctx.stroke();
    }

    for (let y = 0; y < h; y += 38) {
        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(w, y);
        ctx.stroke();
    }

    ctx.restore();
}

function drawBarChart(canvasId, labels, values) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const { ctx, w, h } = setupCanvas(canvas);
    clear(ctx, w, h);
    drawPanelGrid(ctx, w, h);

    const max = Math.max(...values, 1);
    const pad = 38;
    const chartW = w - pad * 2;
    const chartH = h - pad * 2;
    const gap = 12;
    const barW = Math.max(9, (chartW - gap * (values.length - 1)) / Math.max(values.length, 1));

    ctx.font = "12px Oswald, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "top";

    values.forEach((value, i) => {
        const x = pad + i * (barW + gap);
        const barH = (value / max) * chartH;
        const y = pad + chartH - barH;

        const grad = ctx.createLinearGradient(0, y, 0, y + barH);
        grad.addColorStop(0, "rgba(106,159,255,0.95)");
        grad.addColorStop(0.55, "rgba(60,120,255,0.42)");
        grad.addColorStop(1, "rgba(60,120,255,0.12)");

        ctx.fillStyle = "rgba(60,120,255,0.10)";
        ctx.fillRect(x, pad, barW, chartH);

        ctx.fillStyle = grad;
        ctx.shadowColor = "rgba(60,120,255,0.48)";
        ctx.shadowBlur = 14;
        ctx.fillRect(x, y, barW, barH);

        ctx.shadowBlur = 0;
        ctx.fillStyle = "rgba(208,220,240,0.94)";
        ctx.fillText(fmtMetric(value), x + barW / 2, Math.max(4, y - 18));

        ctx.fillStyle = "rgba(122,138,170,0.95)";
        ctx.fillText(labels[i], x + barW / 2, pad + chartH + 8);
    });
}

function drawDonut(canvasId, parts) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    const { ctx, w, h } = setupCanvas(canvas);
    clear(ctx, w, h);
    drawPanelGrid(ctx, w, h);

    const total = parts.reduce((sum, p) => sum + Number(p.value || 0), 0) || 1;

    const colorsByRareza = {
        eterna: "#b882ff",
        divina: "#fffff0",
        mitica: "#ff2244",
        legendaria: "#ffaa00",
        epica: "#cc44ff",
        rara: "#3c78ff",
        poco_comun: "#44ff88",
        comun: "#c8d8f0"
    };

    const ordered = ["eterna", "divina", "mitica", "legendaria", "epica", "rara", "poco_comun", "comun"];

    parts.sort((a, b) => {
        return ordered.indexOf(String(a.label)) - ordered.indexOf(String(b.label));
    });

    const cx = w * 0.34;
    const cy = h / 2;
    const r = Math.min(w, h) * 0.27;
    const line = Math.max(18, r * 0.26);

    let start = -Math.PI / 2;

    ctx.lineCap = "round";
    ctx.lineWidth = line;

    ctx.beginPath();
    ctx.strokeStyle = "rgba(60,120,255,0.10)";
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.stroke();

    parts.forEach((p) => {
        const value = Number(p.value || 0);
        if (value <= 0) return;

        const label = String(p.label);
        const color = colorsByRareza[label] || "#6a9fff";
        const end = start + (value / total) * Math.PI * 2;

        ctx.beginPath();
        ctx.strokeStyle = color;
        ctx.shadowColor = color;
        ctx.shadowBlur = 14;
        ctx.arc(cx, cy, r, start, end);
        ctx.stroke();

        start = end + 0.025;
    });

    ctx.shadowBlur = 0;

    ctx.fillStyle = "rgba(208,220,240,0.96)";
    ctx.font = "30px Oswald, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText(fmtMetric(total), cx, cy - 10);

    ctx.fillStyle = "rgba(122,138,170,0.95)";
    ctx.font = "11px Oswald, sans-serif";
    ctx.fillText("RUNAS", cx, cy + 20);

    const lx = w * 0.62;
    let ly = Math.max(28, cy - 72);

    ctx.textAlign = "left";

    parts.forEach((p) => {
        const label = String(p.label);
        const value = Number(p.value || 0);
        const color = colorsByRareza[label] || "#6a9fff";

        ctx.fillStyle = color;
        ctx.shadowColor = color;
        ctx.shadowBlur = 8;
        ctx.fillRect(lx, ly - 5, 9, 9);

        ctx.shadowBlur = 0;
        ctx.fillStyle = "rgba(208,220,240,0.92)";
        ctx.font = "11px Oswald, sans-serif";
        ctx.fillText(label.toUpperCase(), lx + 16, ly);

        ctx.fillStyle = "rgba(122,138,170,0.95)";
        ctx.fillText(`${value}`, lx + 125, ly);

        ly += 18;
    });
}

function drawActivityCore() {
    const r = DASHBOARD_DATA.resumen;

    drawBarChart(
        "canvasActividad",
        ["Usuarios", "Jugadores", "Activos", "Admins", "Mensajes"],
        [r.usuarios, r.jugadores, r.activos, r.admins, r.mensajes]
    );
}

function drawTopPlayers() {
    const top = DASHBOARD_DATA.top || [];

    drawBarChart(
        "canvasTopPlayers",
        top.map(p => String(p.username).slice(0, 8)),
        top.map(p => Number(p.points || 0))
    );
}

function drawRarezas() {
    const rows = DASHBOARD_DATA.runas_rareza || [];

    drawDonut(
        "canvasRarezas",
        rows.map(r => ({
            label: r.rareza,
            value: Number(r.total || 0)
        }))
    );
}

function drawUsuariosDias() {
    const rows = DASHBOARD_DATA.usuarios_dias || [];

    drawBarChart(
        "canvasUsuariosDias",
        rows.map(r => {
            const d = String(r.dia || "");
            return d.slice(5);
        }),
        rows.map(r => Number(r.total || 0))
    );
}

function drawAllDashboardCanvas() {
    drawActivityCore();
    drawRarezas();
    drawTopPlayers();
    drawUsuariosDias();
}

window.addEventListener("load", drawAllDashboardCanvas);
window.addEventListener("resize", () => {
    clearTimeout(window.__dashResize);
    window.__dashResize = setTimeout(drawAllDashboardCanvas, 120);
});

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

<script src="../JS/animaciones.js"></script>

<?php if ($mostrar_intro): ?>
<script>
if (typeof iniciarAnimacionAdmin === "function") {
    iniciarAnimacionAdmin();
}
</script>
<?php endif; ?>

</body>
</html>