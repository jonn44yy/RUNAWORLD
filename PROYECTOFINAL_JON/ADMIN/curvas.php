<?php
session_start();
if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php"); exit;
}
require_once "../PHP/conexion.php";
require_once "../PHP/calcular_pesos.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $rareza      = $_POST["rareza"];
    $peso_base   = floatval($_POST["peso_base"]);
    $suerte_pico = floatval($_POST["suerte_pico"]);
    $peso_pico   = floatval($_POST["peso_pico"]);
    $suerte_cero = floatval($_POST["suerte_cero"]);

    $stmt = $conexion->prepare("
        INSERT INTO rareza_curva (rareza, peso_base, suerte_pico, peso_pico, suerte_cero)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            peso_base=VALUES(peso_base), suerte_pico=VALUES(suerte_pico),
            peso_pico=VALUES(peso_pico), suerte_cero=VALUES(suerte_cero)
    ");
    $stmt->bind_param("sdddd", $rareza, $peso_base, $suerte_pico, $peso_pico, $suerte_cero);
    $stmt->execute(); $stmt->close();
    header("Location: curvas.php?ok=1"); exit;
}

$stmt = $conexion->prepare("SELECT * FROM rareza_curva ORDER BY FIELD(rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')");
$stmt->execute();
$curvas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calcular preview para varias suerte
$previews = [1, 1.5, 2, 3, 5, 8, 12, 20, 40, 100];
$tabla_preview = [];
foreach ($previews as $s) {
    $c = calcularPesosPorSuerte((float)$s, $conexion);
    $t = $c["total"];
    $row = ["suerte" => $s];
    foreach ($c["pesos"] as $r => $p) {
        $row[$r] = $t > 0 ? round($p / $t * 100, 3) : 0;
    }
    $tabla_preview[] = $row;
}

$conexion->close();

$colores = [
    'eterna'     => '#b882ff',
    'divina'     => '#fffff0',
    'mitica'     => '#ff2244',
    'legendaria' => '#ffaa00',
    'epica'      => '#cc44ff',
    'rara'       => '#3c78ff',
    'poco_comun' => '#44ff88',
    'comun'      => '#c8d8f0',
];
$rarezas_orden = ['eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>RunaWorld — Curvas de Rareza</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .preview-tabla { width:100%; border-collapse:collapse; font-size:0.78rem; }
        .preview-tabla th { padding:6px 10px; text-align:center; color:var(--silver-dim); font-family:var(--font-title); letter-spacing:1px; font-size:0.6rem; border-bottom:1px solid var(--border); }
        .preview-tabla td { padding:5px 10px; text-align:center; color:var(--silver); border-bottom:1px solid rgba(255,255,255,0.04); }
        .preview-tabla tr:hover td { background:rgba(255,255,255,0.02); }
        .pct-cero { color:rgba(255,255,255,0.15) !important; }
        .curva-form { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    </style>
</head>
<body>
<div id="admin-layout" class="visible">
    <aside id="admin-sidebar">
        <div id="sidebar-logo">
            <svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
                <circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/>
                <circle cx="200" cy="200" r="80"  fill="none" stroke="currentColor" stroke-width="1" opacity="0.8"/>
                <g stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
                    <line x1="200" y1="125" x2="200" y2="95"/><line x1="193" y1="110" x2="200" y2="95"/><line x1="207" y1="110" x2="200" y2="95"/>
                    <line x1="200" y1="275" x2="200" y2="305"/><line x1="193" y1="290" x2="207" y2="290"/>
                    <line x1="275" y1="200" x2="305" y2="200"/><line x1="290" y1="193" x2="305" y2="200"/><line x1="290" y1="207" x2="305" y2="200"/>
                    <line x1="125" y1="200" x2="95"  y2="200"/><line x1="110" y1="193" x2="95"  y2="200"/><line x1="110" y1="207" x2="95"  y2="200"/>
                    <line x1="254" y1="146" x2="275" y2="125"/><line x1="146" y1="146" x2="125" y2="125"/>
                    <line x1="254" y1="254" x2="275" y2="275"/><line x1="146" y1="254" x2="125" y2="275"/>
                </g>
                <g stroke="currentColor" stroke-width="1.5">
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
            <a href="runas.php"    class="admin-nav-btn active"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <a href="boosts.php"   class="admin-nav-btn"><span class="nav-icon">✦</span> Boosts</a>
            <a href="curvas.php"   class="admin-nav-btn active"><span class="nav-icon">〜</span> Curvas</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Curvas de Rareza</div>
        <div class="admin-page-sub">Sistema de campana — cada rareza sube hasta su pico y luego baja a 0</div>
        <div class="admin-separador"></div>

        <?php if (isset($_GET["ok"])): ?>
            <p class="admin-msg-ok">Curva guardada correctamente.</p>
        <?php endif; ?>

        <!-- PREVIEW TABLE -->
        <div class="admin-card" style="margin-bottom:28px; overflow-x:auto;">
            <div class="admin-card-titulo">Preview — Probabilidades por nivel de suerte</div>
            <table class="preview-tabla">
                <thead>
                    <tr>
                        <th>Suerte</th>
                        <?php foreach ($rarezas_orden as $r): ?>
                            <th style="color:<?= $colores[$r] ?>"><?= ucfirst(str_replace('_',' ',$r)) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tabla_preview as $row): ?>
                        <tr>
                            <td style="color:var(--gold);font-family:var(--font-title);">x<?= $row["suerte"] ?></td>
                            <?php foreach ($rarezas_orden as $r): ?>
                                <?php $v = $row[$r] ?? 0; ?>
                                <td class="<?= $v <= 0 ? 'pct-cero' : '' ?>" style="color:<?= $colores[$r] ?>">
                                    <?= $v > 0 ? number_format($v, $v >= 1 ? 2 : 3)."%" : "—" ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- EDITAR CURVAS -->
        <div class="admin-card">
            <div class="admin-card-titulo">Editar curvas</div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                <?php foreach ($curvas as $c): ?>
                <div style="border:1px solid var(--border); border-radius:4px; padding:16px; border-left:3px solid <?= $colores[$c['rareza']] ?? 'var(--border)' ?>">
                    <div style="font-family:var(--font-title);color:<?= $colores[$c['rareza']] ?? 'var(--silver)' ?>;letter-spacing:2px;margin-bottom:12px;font-size:0.85rem;">
                        <?= strtoupper(str_replace('_',' ',$c['rareza'])) ?>
                    </div>
                    <form method="POST" class="curva-form">
                        <input type="hidden" name="rareza" value="<?= $c['rareza'] ?>">
                        <div class="admin-form-grupo">
                            <label class="admin-form-label">Peso base (suerte x1)</label>
                            <input type="number" name="peso_base" class="admin-form-input"
                                   step="1" min="0" value="<?= $c['peso_base'] ?>">
                        </div>
                        <div class="admin-form-grupo">
                            <label class="admin-form-label">Suerte en el pico</label>
                            <input type="number" name="suerte_pico" class="admin-form-input"
                                   step="0.1" min="1" value="<?= $c['suerte_pico'] ?>">
                        </div>
                        <div class="admin-form-grupo">
                            <label class="admin-form-label">Peso máximo (en el pico)</label>
                            <input type="number" name="peso_pico" class="admin-form-input"
                                   step="1" min="0" value="<?= $c['peso_pico'] ?>">
                        </div>
                        <div class="admin-form-grupo">
                            <label class="admin-form-label">Suerte donde llega a 0</label>
                            <input type="number" name="suerte_cero" class="admin-form-input"
                                   step="0.5" min="1" value="<?= $c['suerte_cero'] ?>">
                        </div>
                        <div style="grid-column:1/-1;">
                            <button type="submit" class="btn-admin btn-admin-primary">Guardar</button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>
</div>
</body>
</html>
