<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? '') !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fmtNum($n) {
    $n = (float)($n ?? 0);
    if ($n >= 1e12) return rtrim(rtrim(number_format($n / 1e12, 2, '.', ''), '0'), '.') . "T";
    if ($n >= 1e9) return rtrim(rtrim(number_format($n / 1e9, 2, '.', ''), '0'), '.') . "B";
    if ($n >= 1e6) return rtrim(rtrim(number_format($n / 1e6, 2, '.', ''), '0'), '.') . "M";
    if ($n >= 1e3) return rtrim(rtrim(number_format($n / 1e3, 2, '.', ''), '0'), '.') . "k";
    return number_format($n, 0, '.', '');
}
function fmtDateSafe($value) {
    if (!$value) return 'Sin fecha';
    $ts = strtotime($value);
    if (!$ts) return 'Sin fecha';
    return date('d/m/Y H:i', $ts);
}
function tableExists($conexion, $table) {
    $table = $conexion->real_escape_string($table);
    $res = $conexion->query("SHOW TABLES LIKE '$table'");
    return $res && $res->num_rows > 0;
}
function columnExists($conexion, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conexion->real_escape_string($column);
    $res = $conexion->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}
function selectCol($conexion, $table, $alias, $column, $as, $fallback = '0') {
    if (columnExists($conexion, $table, $column)) return "$alias.`$column` AS `$as`";
    return "$fallback AS `$as`";
}
function inputVal($v) {
    if ($v === null || $v === '') return '0';
    return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: usuarios.php?error=id_invalido");
    exit;
}

$errores = $_SESSION['errores'] ?? [];
$ok = $_SESSION['ok'] ?? '';
unset($_SESSION['errores'], $_SESSION['ok']);

$statsExists = tableExists($conexion, 'jugador_stats');
$runasExists = tableExists($conexion, 'jugador_runas');
$mejorasExists = tableExists($conexion, 'jugador_mejoras');

$select = [
    'u.id', 'u.username', selectCol($conexion, 'usuarios', 'u', 'email', 'email', "''"), selectCol($conexion, 'usuarios', 'u', 'rol', 'rol', "'usuario'"),
    'j.id AS jugador_id',
    selectCol($conexion, 'jugadores', 'j', 'coins', 'coins', '0'),
    selectCol($conexion, 'jugadores', 'j', 'points', 'points', '0'),
    selectCol($conexion, 'jugadores', 'j', 'coins_por_seg', 'coins_por_seg', '0'),
    selectCol($conexion, 'jugadores', 'j', 'points_por_seg', 'points_por_seg', '0'),
    selectCol($conexion, 'jugadores', 'j', 'bulk_total', 'bulk_total', '0'),
    selectCol($conexion, 'jugadores', 'j', 'suerte', 'suerte', '0'),
    selectCol($conexion, 'jugadores', 'j', 'ultima_actualizacion', 'ultima_actualizacion', 'NULL')
];

$joinStats = '';
if ($statsExists) {
    $joinStats = 'LEFT JOIN jugador_stats st ON st.jugador_id = j.id';
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_tiradas', 'total_tiradas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_runas_conseguidas', 'total_runas_conseguidas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_eternas', 'total_eternas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_divinas', 'total_divinas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_miticas', 'total_miticas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_legendarias', 'total_legendarias', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'boosts_clickados', 'boosts_clickados', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'fecha_primera_tirada', 'fecha_primera_tirada', 'NULL');
} else {
    foreach (['total_tiradas','total_runas_conseguidas','total_eternas','total_divinas','total_miticas','total_legendarias','boosts_clickados'] as $c) $select[] = "0 AS `$c`";
    $select[] = 'NULL AS fecha_primera_tirada';
}

if ($runasExists) {
    $select[] = '(SELECT COUNT(*) FROM jugador_runas jr WHERE jr.jugador_id = j.id) AS runas_distintas';
    $select[] = '(SELECT COALESCE(SUM(jr.cantidad), 0) FROM jugador_runas jr WHERE jr.jugador_id = j.id) AS runas_inventario';
} else {
    $select[] = '0 AS runas_distintas';
    $select[] = '0 AS runas_inventario';
}
if ($mejorasExists) {
    $select[] = '(SELECT COUNT(*) FROM jugador_mejoras jm WHERE jm.jugador_id = j.id AND COALESCE(jm.nivel,0) > 0) AS mejoras_compradas';
    $select[] = '(SELECT COALESCE(SUM(jm.nivel), 0) FROM jugador_mejoras jm WHERE jm.jugador_id = j.id) AS niveles_mejoras';
} else {
    $select[] = '0 AS mejoras_compradas';
    $select[] = '0 AS niveles_mejoras';
}

$sql = 'SELECT ' . implode(', ', $select) . "
        FROM usuarios u
        LEFT JOIN jugadores j ON u.id = j.usuario_id
        $joinStats
        WHERE u.id = ?";
$stmt = $conexion->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conexion->close();

if (!$usuario) {
    header("Location: usuarios.php?error=usuario_no_encontrado");
    exit;
}

$tieneJugador = !empty($usuario['jugador_id']);
$diagnosticos = [];
$rol = strtolower((string)($usuario['rol'] ?? 'usuario'));
if (!$tieneJugador && $rol === 'admin') $diagnosticos[] = ['info', 'Admin sin progreso jugable. Normal si esta cuenta solo administra.'];
if (!$tieneJugador && $rol !== 'admin') $diagnosticos[] = ['warn', 'Usuario sin fila en jugadores. Puedes crear progreso inicial.'];
if ($tieneJugador && (float)$usuario['coins'] < 0) $diagnosticos[] = ['danger', 'Coins negativos detectados.'];
if ($tieneJugador && (float)$usuario['points'] < 0) $diagnosticos[] = ['danger', 'Points negativos detectados.'];
if ($tieneJugador && (int)$usuario['total_tiradas'] === 0 && (float)$usuario['coins'] <= 0 && (float)$usuario['points'] <= 0) $diagnosticos[] = ['warn', 'Progreso vacío: puede ser cuenta nueva o progreso incompleto.'];
if ($tieneJugador && ((int)$usuario['total_eternas'] > 0 || (int)$usuario['total_divinas'] > 0)) $diagnosticos[] = ['ok', 'Jugador con rarezas máximas: revisar balance antes de editar manualmente.'];
if (!$diagnosticos) $diagnosticos[] = ['ok', 'Progreso sin incidencias detectadas.'];

$sidebar_svg = '<svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff"><circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/><circle cx="200" cy="200" r="145" fill="none" stroke="currentColor" stroke-width="0.7" opacity="0.7"/><circle cx="200" cy="200" r="80" fill="none" stroke="currentColor" stroke-width="1" opacity="0.8"/><g stroke="currentColor" stroke-width="2" opacity="1" stroke-linecap="round"><line x1="200" y1="125" x2="200" y2="95"/><line x1="200" y1="275" x2="200" y2="305"/><line x1="275" y1="200" x2="305" y2="200"/><line x1="125" y1="200" x2="95" y2="200"/></g><g stroke="currentColor" stroke-width="1.5" opacity="0.9"><line x1="200" y1="140" x2="200" y2="260"/><line x1="140" y1="200" x2="260" y2="200"/><circle cx="200" cy="200" r="25" fill="none"/><circle cx="200" cy="200" r="5" fill="currentColor"/></g></svg>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Editar Progreso</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .prog-hero { display:grid; grid-template-columns:minmax(0,1fr) minmax(360px,.8fr); gap:18px; margin-bottom:22px; }
        .prog-panel { border:1px solid var(--border); background:rgba(8,12,28,.72); border-radius:8px; padding:18px; }
        .prog-kicker { font-family:var(--font-title); letter-spacing:3px; text-transform:uppercase; color:var(--silver-dim); font-size:.68rem; margin-bottom:8px; }
        .prog-name { font-family:var(--font-title); letter-spacing:3px; text-transform:uppercase; color:var(--blue-bright); font-size:1.7rem; text-shadow:0 0 15px var(--blue-glow); }
        .prog-email { color:var(--silver-dim); margin-top:8px; font-size:1rem; word-break:break-word; }
        .prog-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:20px; }
        .prog-stat { border:1px solid rgba(60,120,255,.15); background:rgba(255,255,255,.025); border-radius:6px; padding:12px; }
        .prog-stat span { display:block; font-family:var(--font-title); color:var(--silver-dim); letter-spacing:2px; text-transform:uppercase; font-size:.62rem; margin-bottom:7px; }
        .prog-stat strong { font-family:var(--font-title); color:var(--silver); font-size:1.15rem; }
        .prog-layout { display:grid; grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr); gap:20px; align-items:start; }
        .prog-section-title { font-family:var(--font-title); color:var(--blue-bright); letter-spacing:3px; text-transform:uppercase; font-size:.9rem; margin-bottom:14px; }
        .prog-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .prog-form-grid .full { grid-column:1 / -1; }
        .prog-help { color:var(--silver-dim); font-size:.9rem; line-height:1.45; margin-top:6px; }
        .diag-list { display:flex; flex-direction:column; gap:8px; }
        .diag-item { border:1px solid rgba(60,120,255,.18); border-radius:5px; padding:10px 12px; font-size:.92rem; color:var(--silver); }
        .diag-item.ok { border-color:rgba(0,200,100,.28); color:#70ffaa; background:rgba(0,200,100,.05); }
        .diag-item.warn { border-color:rgba(255,211,92,.35); color:#ffd35c; background:rgba(255,211,92,.05); }
        .diag-item.info { border-color:rgba(60,120,255,.26); color:var(--blue-bright); background:rgba(60,120,255,.05); }
        .diag-item.danger { border-color:rgba(255,51,68,.38); color:#ff7788; background:rgba(255,51,68,.06); }
        .prog-actions { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:20px; }
        .prog-actions .btn-admin, .prog-actions button { width:100%; text-align:center; justify-content:center; }
        .no-player-box { border:1px solid rgba(255,211,92,.35); background:rgba(255,211,92,.06); color:#ffd35c; padding:14px; border-radius:8px; margin-bottom:18px; }
        @media (max-width:1100px){ .prog-hero, .prog-layout { grid-template-columns:1fr; } .prog-summary { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (max-width:700px){ .prog-summary, .prog-form-grid, .prog-actions { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>
<div id="admin-layout" class="visible">
    <aside id="admin-sidebar">
        <div id="sidebar-logo"><?= $sidebar_svg ?><div id="sidebar-logo-titulo">RunaWorld</div></div>
        <nav class="admin-nav">
            <a href="index.php" class="admin-nav-btn"><span class="nav-icon">⬡</span> Dashboard</a>
            <a href="usuarios.php" class="admin-nav-btn active"><span class="nav-icon">◈</span> Usuarios</a>
            <a href="runas.php" class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php" class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>
    <main id="admin-content">
        <div class="admin-page-titulo">Editar Progreso</div>
        <div class="admin-page-sub">Economía, estadísticas y valores jugables del usuario.</div>
        <div class="admin-separador"></div>

        <?php if ($ok): ?><p class="admin-msg-ok"><?= h($ok) ?></p><?php endif; ?>
        <?php foreach ($errores as $e): ?><p class="admin-msg-error"><?= h($e) ?></p><?php endforeach; ?>

        <section class="prog-hero">
            <div class="prog-panel">
                <div class="prog-kicker">Usuario #<?= (int)$usuario['id'] ?><?= $tieneJugador ? ' · Jugador #' . (int)$usuario['jugador_id'] : ' · Sin jugador' ?></div>
                <div class="prog-name"><?= h($usuario['username']) ?></div>
                <div class="prog-email"><?= h($usuario['email'] ?? '') ?></div>
            </div>
            <div class="prog-panel">
                <div class="prog-section-title">Estado</div>
                <div class="diag-list">
                    <?php foreach ($diagnosticos as $d): ?><div class="diag-item <?= h($d[0]) ?>"><?= h($d[1]) ?></div><?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="prog-summary">
            <div class="prog-stat"><span>Coins</span><strong><?= h(fmtNum($usuario['coins'])) ?></strong></div>
            <div class="prog-stat"><span>Points</span><strong><?= h(fmtNum($usuario['points'])) ?></strong></div>
            <div class="prog-stat"><span>Tiradas</span><strong><?= h(fmtNum($usuario['total_tiradas'])) ?></strong></div>
            <div class="prog-stat"><span>Inventario</span><strong><?= h(fmtNum($usuario['runas_inventario'])) ?></strong></div>
        </section>

        <?php if (!$tieneJugador): ?>
            <div class="no-player-box">
                Este usuario no tiene fila asociada en <strong>jugadores</strong>. Si debe poder jugar, crea un progreso inicial.
            </div>
            <form method="POST" action="../PHP/editar_progreso_action.php" class="prog-actions" style="max-width:620px;">
                <input type="hidden" name="id" value="<?= (int)$usuario['id'] ?>">
                <button type="submit" name="accion" value="crear_jugador" class="admin-form-submit">Crear progreso inicial</button>
                <a href="usuarios.php" class="btn-admin btn-admin-primary">Volver</a>
            </form>
        <?php else: ?>
            <form method="POST" action="../PHP/editar_progreso_action.php">
                <input type="hidden" name="id" value="<?= (int)$usuario['id'] ?>">
                <section class="prog-layout">
                    <div class="prog-panel">
                        <div class="prog-section-title">Economía y juego</div>
                        <div class="prog-form-grid">
                            <div class="admin-form-grupo"><label class="admin-form-label">Coins</label><input type="number" step="0.01" min="0" name="coins" class="admin-form-input" value="<?= h(inputVal($usuario['coins'])) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Points</label><input type="number" step="0.01" min="0" name="points" class="admin-form-input" value="<?= h(inputVal($usuario['points'])) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Coins/s</label><input type="number" step="0.0001" min="0" name="coins_por_seg" class="admin-form-input" value="<?= h(inputVal($usuario['coins_por_seg'])) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Points/s</label><input type="number" step="0.0001" min="0" name="points_por_seg" class="admin-form-input" value="<?= h(inputVal($usuario['points_por_seg'])) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Bulk total</label><input type="number" step="1" min="0" name="bulk_total" class="admin-form-input" value="<?= h(inputVal($usuario['bulk_total'])) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Suerte</label><input type="number" step="0.0001" min="0" name="suerte" class="admin-form-input" value="<?= h(inputVal($usuario['suerte'])) ?>"></div>
                            <div class="admin-form-grupo full"><label class="admin-form-label">Última actualización</label><input type="text" class="admin-form-input" value="<?= h(fmtDateSafe($usuario['ultima_actualizacion'])) ?>" disabled><div class="prog-help">Se actualiza automáticamente al guardar cambios.</div></div>
                        </div>
                    </div>
                    <aside class="prog-panel">
                        <div class="prog-section-title">Estadísticas</div>
                        <div class="prog-form-grid">
                            <div class="admin-form-grupo"><label class="admin-form-label">Total tiradas</label><input type="number" step="1" min="0" name="total_tiradas" class="admin-form-input" value="<?= h((int)$usuario['total_tiradas']) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Runas conseguidas</label><input type="number" step="1" min="0" name="total_runas_conseguidas" class="admin-form-input" value="<?= h((int)$usuario['total_runas_conseguidas']) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Eternas</label><input type="number" step="1" min="0" name="total_eternas" class="admin-form-input" value="<?= h((int)$usuario['total_eternas']) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Divinas</label><input type="number" step="1" min="0" name="total_divinas" class="admin-form-input" value="<?= h((int)$usuario['total_divinas']) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Míticas</label><input type="number" step="1" min="0" name="total_miticas" class="admin-form-input" value="<?= h((int)$usuario['total_miticas']) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Legendarias</label><input type="number" step="1" min="0" name="total_legendarias" class="admin-form-input" value="<?= h((int)$usuario['total_legendarias']) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Boosts clickados</label><input type="number" step="1" min="0" name="boosts_clickados" class="admin-form-input" value="<?= h((int)$usuario['boosts_clickados']) ?>"></div>
                            <div class="admin-form-grupo"><label class="admin-form-label">Runas distintas</label><input type="text" class="admin-form-input" value="<?= h((int)$usuario['runas_distintas']) ?>" disabled></div>
                        </div>
                        <div class="prog-help" style="margin-top:14px;">Editar estadísticas manualmente puede desincronizar logros o rankings si el juego los calcula desde inventario.</div>
                    </aside>
                </section>
                <div class="prog-actions">
                    <button type="submit" class="admin-form-submit">Guardar progreso</button>
                    <a href="usuarios.php" class="btn-admin btn-admin-primary">Cancelar</a>
                </div>
            </form>
        <?php endif; ?>
    </main>
</div>
<script src="../JS/admin-mobile.js?v=3"></script>
<script>
function toggleAdminNav(){var s=document.getElementById('admin-sidebar'),o=document.getElementById('admin-nav-overlay'),b=document.getElementById('admin-hamburger');var open=s.classList.toggle('open');o.classList.toggle('visible',open);b.innerHTML=open?'&#10005;':'&#9776;';}
function cerrarAdminNav(){document.getElementById('admin-sidebar').classList.remove('open');document.getElementById('admin-nav-overlay').classList.remove('visible');document.getElementById('admin-hamburger').innerHTML='&#9776;';}
</script>
</body>
</html>