<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? '') !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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
    if (columnExists($conexion, $table, $column)) {
        return "$alias.`$column` AS `$as`";
    }
    return "$fallback AS `$as`";
}

function fmtNum($n) {
    $n = (float)($n ?? 0);
    if ($n >= 1e12) return rtrim(rtrim(number_format($n / 1e12, 2, '.', ''), '0'), '.') . "T";
    if ($n >= 1e9)  return rtrim(rtrim(number_format($n / 1e9, 2, '.', ''), '0'), '.') . "B";
    if ($n >= 1e6)  return rtrim(rtrim(number_format($n / 1e6, 2, '.', ''), '0'), '.') . "M";
    if ($n >= 1e3)  return rtrim(rtrim(number_format($n / 1e3, 2, '.', ''), '0'), '.') . "k";
    return number_format($n, 0, '.', '');
}

function fmtDateSafe($value) {
    if (!$value) return 'Sin fecha';
    $ts = strtotime($value);
    if (!$ts) return 'Sin fecha';
    return date('d/m/Y', $ts);
}

function isRecent($value, $days = 14) {
    if (!$value) return false;
    $ts = strtotime($value);
    if (!$ts) return false;
    return $ts >= strtotime("-$days days");
}

function userScore($u) {
    return
        (float)($u['points'] ?? 0) +
        (float)($u['coins'] ?? 0) +
        ((float)($u['coins_por_seg'] ?? 0) * 1000) +
        ((float)($u['points_por_seg'] ?? 0) * 1000) +
        ((float)($u['total_tiradas'] ?? 0) * 10) +
        ((float)($u['total_runas_conseguidas'] ?? 0) * 4);
}

function diagnosticosUsuario($u) {
    $d = [];
    $rol = strtolower((string)($u['rol'] ?? 'usuario'));

    if ($rol !== 'usuario') {
        $d[] = ['info', 'Cuenta administrativa: revisar permisos antes de editar o eliminar.'];
    }

    if (empty($u['jugador_id'])) {
        $d[] = ['warn', 'Usuario sin fila asociada en jugadores. No tendrá progreso jugable completo.'];
    }

    $coins = (float)($u['coins'] ?? 0);
    $points = (float)($u['points'] ?? 0);
    $coinsPs = (float)($u['coins_por_seg'] ?? 0);
    $pointsPs = (float)($u['points_por_seg'] ?? 0);

    if ($coins < 0 || $points < 0 || $coinsPs < 0 || $pointsPs < 0) {
        $d[] = ['danger', 'Recursos negativos detectados. Conviene revisar progreso y lógica de sincronización.'];
    }

    if (!empty($u['jugador_id']) && (int)($u['total_tiradas'] ?? 0) === 0 && $coins <= 0 && $points <= 0) {
        $d[] = ['warn', 'Jugador sin progreso visible: puede ser cuenta nueva o jugador inicializado incompleto.'];
    }

    if (!empty($u['jugador_id']) && $u['total_tiradas'] === null) {
        $d[] = ['warn', 'Jugador sin registro en jugador_stats. Las estadísticas pueden estar incompletas.'];
    }

    if ((int)($u['total_eternas'] ?? 0) > 0 || (int)($u['total_divinas'] ?? 0) > 0) {
        $d[] = ['ok', 'Jugador con rarezas máximas obtenidas. Cuenta relevante para revisar balance.'];
    }

    if (empty($d)) {
        $d[] = ['ok', 'Sin incidencias detectadas.'];
    }

    return $d;
}

$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$filtroRol = isset($_GET['rol']) ? trim($_GET['rol']) : '';

$statsExists = tableExists($conexion, 'jugador_stats');
$runasExists = tableExists($conexion, 'jugador_runas');
$mejorasExists = tableExists($conexion, 'jugador_mejoras');

$select = [
    'u.id',
    'u.username',
    'u.email',
    selectCol($conexion, 'usuarios', 'u', 'genero', 'genero', "''"),
    selectCol($conexion, 'usuarios', 'u', 'fecha_registro', 'fecha_registro', 'NULL'),
    selectCol($conexion, 'usuarios', 'u', 'rol', 'rol', "'usuario'"),
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
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_tiradas', 'total_tiradas', 'NULL');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_runas_conseguidas', 'total_runas_conseguidas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_eternas', 'total_eternas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_divinas', 'total_divinas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_miticas', 'total_miticas', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_legendarias', 'total_legendarias', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'boosts_clickados', 'boosts_clickados', '0');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'fecha_primera_tirada', 'fecha_primera_tirada', 'NULL');
} else {
    $select[] = 'NULL AS total_tiradas';
    $select[] = '0 AS total_runas_conseguidas';
    $select[] = '0 AS total_eternas';
    $select[] = '0 AS total_divinas';
    $select[] = '0 AS total_miticas';
    $select[] = '0 AS total_legendarias';
    $select[] = '0 AS boosts_clickados';
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

$where = [];
$params = [];
$types = '';

if ($buscar !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.rol LIKE ?)';
    $like = '%' . $buscar . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($filtroRol !== '' && in_array($filtroRol, ['usuario', 'admin'], true)) {
    $where[] = 'u.rol = ?';
    $params[] = $filtroRol;
    $types .= 's';
}

$sql = 'SELECT ' . implode(",\n               ", $select) . "
        FROM usuarios u
        LEFT JOIN jugadores j ON u.id = j.usuario_id
        $joinStats";

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= " ORDER BY CASE WHEN u.rol = 'admin' THEN 0 ELSE 1 END, u.id ASC";

$stmt = $conexion->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$usuarios = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($usuarios as &$u) {
    $u['_score'] = userScore($u);
    $u['_diagnosticos'] = diagnosticosUsuario($u);
}
unset($u);

$admins = array_values(array_filter($usuarios, fn($u) => strtolower((string)($u['rol'] ?? 'usuario')) !== 'usuario'));
$jugadores = array_values(array_filter($usuarios, fn($u) => strtolower((string)($u['rol'] ?? 'usuario')) === 'usuario'));
$recientes = array_values(array_filter($usuarios, fn($u) => isRecent($u['fecha_registro'] ?? null, 14)));
$incidencias = array_values(array_filter($usuarios, function($u) {
    foreach ($u['_diagnosticos'] as $d) {
        if ($d[0] === 'warn' || $d[0] === 'danger') return true;
    }
    return false;
}));
$progresoAlto = $jugadores;
usort($progresoAlto, fn($a, $b) => $b['_score'] <=> $a['_score']);
$progresoAlto = array_values(array_filter(array_slice($progresoAlto, 0, 10), fn($u) => $u['_score'] > 0));

$totalUsuarios = count($usuarios);
$totalJugadores = count($jugadores);
$totalAdmins = count($admins);
$totalIncidencias = count($incidencias);
$totalCoins = array_sum(array_map(fn($u) => (float)($u['coins'] ?? 0), $usuarios));
$totalPoints = array_sum(array_map(fn($u) => (float)($u['points'] ?? 0), $usuarios));
$totalTiradas = array_sum(array_map(fn($u) => (float)($u['total_tiradas'] ?? 0), $usuarios));

$conexion->close();

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
</svg>';

function renderUsuarioCard($u, $modo = 'completo') {
    $rol = strtolower((string)($u['rol'] ?? 'usuario'));
    $isAdmin = $rol !== 'usuario';
    $hasJugador = !empty($u['jugador_id']);
    $estadoClass = 'ok';
    foreach ($u['_diagnosticos'] as $d) {
        if ($d[0] === 'danger') { $estadoClass = 'danger'; break; }
        if ($d[0] === 'warn') $estadoClass = 'warn';
    }

    $estadoTexto = $estadoClass === 'ok' ? 'Correcto' : ($estadoClass === 'danger' ? 'Crítico' : 'Revisar');
    ?>
    <article class="user-admin-card user-role-<?= h($rol) ?>">
        <header class="user-card-head">
            <div>
                <div class="user-id">#<?= h($u['id']) ?></div>
                <h3><?= h($u['username']) ?></h3>
                <p><?= h($u['email']) ?></p>
            </div>
            <div class="user-card-badges">
                <span class="user-badge <?= $isAdmin ? 'admin' : 'player' ?>"><?= $isAdmin ? 'Admin' : 'Jugador' ?></span>
                <span class="user-badge state-<?= h($estadoClass) ?>"><?= h($estadoTexto) ?></span>
            </div>
        </header>

        <div class="user-mini-grid">
            <div><span>Registro</span><strong><?= h(fmtDateSafe($u['fecha_registro'] ?? null)) ?></strong></div>
            <div><span>Género</span><strong><?= h($u['genero'] ?: 'No indicado') ?></strong></div>
            <div><span>Coins</span><strong><?= h(fmtNum($u['coins'] ?? 0)) ?></strong></div>
            <div><span>Points</span><strong><?= h(fmtNum($u['points'] ?? 0)) ?></strong></div>
        </div>

        <details class="user-card-details" <?= $modo === 'incidencia' ? 'open' : '' ?>>
            <summary>Ver datos completos</summary>
            <div class="user-detail-grid">
                <section>
                    <h4>Cuenta</h4>
                    <p><span>ID usuario</span><strong>#<?= h($u['id']) ?></strong></p>
                    <p><span>Rol</span><strong><?= h($u['rol'] ?? 'usuario') ?></strong></p>
                    <p><span>Email</span><strong><?= h($u['email']) ?></strong></p>
                    <p><span>Jugador asociado</span><strong><?= $hasJugador ? '#' . h($u['jugador_id']) : 'No' ?></strong></p>
                </section>
                <section>
                    <h4>Economía</h4>
                    <p><span>Coins</span><strong><?= h(fmtNum($u['coins'] ?? 0)) ?></strong></p>
                    <p><span>Points</span><strong><?= h(fmtNum($u['points'] ?? 0)) ?></strong></p>
                    <p><span>Coins/s</span><strong><?= h(fmtNum($u['coins_por_seg'] ?? 0)) ?></strong></p>
                    <p><span>Points/s</span><strong><?= h(fmtNum($u['points_por_seg'] ?? 0)) ?></strong></p>
                    <p><span>Bulk</span><strong><?= h(fmtNum($u['bulk_total'] ?? 0)) ?></strong></p>
                    <p><span>Suerte</span><strong><?= h($u['suerte'] ?? 0) ?></strong></p>
                </section>
                <section>
                    <h4>Estadísticas</h4>
                    <p><span>Tiradas</span><strong><?= h(fmtNum($u['total_tiradas'] ?? 0)) ?></strong></p>
                    <p><span>Runas totales</span><strong><?= h(fmtNum($u['total_runas_conseguidas'] ?? 0)) ?></strong></p>
                    <p><span>Inventario</span><strong><?= h(fmtNum($u['runas_inventario'] ?? 0)) ?></strong></p>
                    <p><span>Runas distintas</span><strong><?= h(fmtNum($u['runas_distintas'] ?? 0)) ?></strong></p>
                    <p><span>Mejoras</span><strong><?= h(fmtNum($u['mejoras_compradas'] ?? 0)) ?></strong></p>
                </section>
                <section>
                    <h4>Rarezas altas</h4>
                    <p><span>Eternas</span><strong><?= h(fmtNum($u['total_eternas'] ?? 0)) ?></strong></p>
                    <p><span>Divinas</span><strong><?= h(fmtNum($u['total_divinas'] ?? 0)) ?></strong></p>
                    <p><span>Míticas</span><strong><?= h(fmtNum($u['total_miticas'] ?? 0)) ?></strong></p>
                    <p><span>Legendarias</span><strong><?= h(fmtNum($u['total_legendarias'] ?? 0)) ?></strong></p>
                </section>
            </div>

            <div class="user-diagnostics" data-suggestions>
                <h4>Diagnóstico</h4>
                <?php foreach ($u['_diagnosticos'] as $d): ?>
                    <div class="diag diag-<?= h($d[0]) ?>"><?= h($d[1]) ?></div>
                <?php endforeach; ?>
            </div>
        </details>

        <div class="user-card-actions">
            <a href="editar_cuenta.php?id=<?= h($u['id']) ?>" class="btn-admin btn-admin-primary">Editar cuenta</a>
            <?php if ($hasJugador): ?>
                <a href="editar_progreso.php?id=<?= h($u['id']) ?>" class="btn-admin btn-admin-primary">Editar progreso</a>
            <?php else: ?>
                <span class="btn-admin btn-admin-disabled">Sin progreso</span>
            <?php endif; ?>
            <?php if ((int)$u['id'] !== (int)($_SESSION['idUsuario'] ?? 0)): ?>
                <a href="../PHP/borrar_usuario.php?id=<?= h($u['id']) ?>"
                   class="btn-admin btn-admin-danger"
                   onclick="return confirm('Eliminar usuario <?= h($u['username']) ?>? Esta acción borra cuenta y progreso asociado.');">
                   Eliminar
                </a>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

function renderSection($id, $titulo, $sub, $items, $open = false, $modo = 'completo') {
    ?>
    <section class="user-section" data-section="<?= h($id) ?>">
        <button class="user-section-head" type="button" data-section-toggle>
            <span>
                <strong><?= h($titulo) ?></strong>
                <em><?= h(count($items)) ?> registros · <?= h($sub) ?></em>
            </span>
            <b><?= $open ? 'Cerrar' : 'Abrir' ?>⌃</b>
        </button>
        <div class="user-section-body <?= $open ? 'open' : '' ?>">
            <?php if (empty($items)): ?>
                <div class="empty-state">No hay registros en este grupo.</div>
            <?php else: ?>
                <div class="user-card-grid">
                    <?php foreach ($items as $u) renderUsuarioCard($u, $modo); ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Manejo de Usuarios</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .users-admin-shell { display: flex; flex-direction: column; gap: 18px; }
        .info-panel, .user-section, .user-toolbar, .user-summary-card {
            border: 1px solid var(--border);
            background: rgba(8,12,28,0.72);
            border-radius: 8px;
        }
        .info-panel summary {
            list-style: none;
            cursor: pointer;
            padding: 18px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: var(--font-title);
            color: var(--blue-bright);
            letter-spacing: 3px;
            text-transform: uppercase;
            font-size: .82rem;
        }
        .info-panel summary::-webkit-details-marker { display:none; }
        .info-panel-content { padding: 0 20px 20px; color: var(--silver-dim); font-size: .98rem; line-height: 1.55; }
        .info-panel-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap:12px; margin-top:12px; }
        .info-panel-grid div { border:1px solid rgba(60,120,255,.14); padding:12px; border-radius:6px; background:rgba(0,0,0,.16); }
        .info-panel-grid strong { display:block; color:var(--silver); font-family:var(--font-title); letter-spacing:2px; text-transform:uppercase; font-size:.7rem; margin-bottom:6px; }

        .user-toolbar { padding: 16px; display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap; align-items:center; }
        .user-toolbar form { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .user-toolbar .admin-search, .user-toolbar select { min-height: 40px; }
        .user-toolbar select { background:rgba(255,255,255,.03); color:var(--silver); border:1px solid var(--border); border-radius:3px; padding:0 12px; font-family:var(--font-body); }
        .suggestions-toggle { display:flex; gap:10px; align-items:center; color:var(--silver-dim); font-size:.92rem; }
        .toggle-pill { border:1px solid var(--blue); color:var(--blue-bright); background:transparent; padding:9px 14px; border-radius:3px; font-family:var(--font-title); letter-spacing:2px; text-transform:uppercase; cursor:pointer; }
        body.hide-user-suggestions [data-suggestions] { display:none !important; }

        .user-summary-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap:12px; }
        .user-summary-card { padding:16px; }
        .user-summary-card strong { display:block; color:var(--blue-bright); font-family:var(--font-title); font-size:1.7rem; line-height:1; }
        .user-summary-card span { display:block; color:var(--silver-dim); margin-top:7px; font-family:var(--font-title); letter-spacing:2px; text-transform:uppercase; font-size:.62rem; }

        .user-section { overflow:hidden; }
        .user-section-head { width:100%; border:0; background:rgba(60,120,255,.045); color:var(--silver); cursor:pointer; display:flex; justify-content:space-between; align-items:center; gap:14px; padding:18px 20px; text-align:left; }
        .user-section-head strong { display:block; color:var(--blue-bright); font-family:var(--font-title); letter-spacing:5px; text-transform:uppercase; font-size:1rem; }
        .user-section-head em { display:block; color:var(--silver-dim); margin-top:4px; font-size:.9rem; }
        .user-section-head b { color:var(--blue-bright); border:1px solid var(--border); padding:8px 12px; border-radius:3px; font-family:var(--font-title); letter-spacing:2px; text-transform:uppercase; font-size:.68rem; white-space:nowrap; }
        .user-section-body { display:none; border-top:1px solid rgba(60,120,255,.14); padding:16px; }
        .user-section-body.open { display:block; }

        .user-card-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap:14px; align-items:start; }
        .user-admin-card { border:1px solid rgba(60,120,255,.22); background:rgba(3,8,20,.76); border-radius:8px; padding:14px; display:flex; flex-direction:column; gap:12px; min-width:0; }
        .user-card-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
        .user-id { color:var(--silver-dim); font-family:var(--font-title); font-size:.78rem; letter-spacing:2px; margin-bottom:3px; }
        .user-card-head h3 { color:var(--blue-bright); font-family:var(--font-title); letter-spacing:1.5px; font-size:1.1rem; margin:0; word-break:break-word; }
        .user-card-head p { color:var(--silver-dim); font-size:.92rem; margin-top:4px; word-break:break-word; }
        .user-card-badges { display:flex; flex-direction:column; gap:6px; align-items:flex-end; }
        .user-badge { font-family:var(--font-title); letter-spacing:1.6px; text-transform:uppercase; font-size:.6rem; border-radius:3px; padding:5px 8px; white-space:nowrap; border:1px solid var(--border); }
        .user-badge.player { color:#7fb1ff; background:rgba(60,120,255,.08); }
        .user-badge.admin { color:#ffd76a; background:rgba(255,215,0,.09); border-color:rgba(255,215,0,.25); }
        .state-ok { color:#44ff88; background:rgba(0,200,100,.12); border-color:rgba(0,200,100,.26); }
        .state-warn { color:#ffd76a; background:rgba(255,215,0,.10); border-color:rgba(255,215,0,.25); }
        .state-danger { color:#ff7788; background:rgba(255,51,68,.12); border-color:rgba(255,51,68,.28); }

        .user-mini-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
        .user-mini-grid div { background:rgba(255,255,255,.025); border:1px solid rgba(60,120,255,.12); border-radius:5px; padding:10px; }
        .user-mini-grid span, .user-detail-grid span { display:block; color:var(--silver-dim); font-family:var(--font-title); letter-spacing:2px; text-transform:uppercase; font-size:.58rem; margin-bottom:5px; }
        .user-mini-grid strong, .user-detail-grid strong { color:var(--silver); font-size:.98rem; word-break:break-word; }

        .user-card-details { border:1px solid rgba(60,120,255,.12); border-radius:6px; overflow:hidden; background:rgba(0,0,0,.15); }
        .user-card-details summary { cursor:pointer; color:var(--blue-bright); font-family:var(--font-title); letter-spacing:2px; text-transform:uppercase; font-size:.72rem; padding:11px 12px; list-style:none; }
        .user-card-details summary::-webkit-details-marker { display:none; }
        .user-card-details[open] summary { border-bottom:1px solid rgba(60,120,255,.12); }
        .user-detail-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; padding:12px; }
        .user-detail-grid section { border:1px solid rgba(60,120,255,.1); border-radius:5px; padding:10px; }
        .user-detail-grid h4, .user-diagnostics h4 { color:var(--blue-bright); font-family:var(--font-title); letter-spacing:2px; text-transform:uppercase; font-size:.68rem; margin-bottom:9px; }
        .user-detail-grid p { display:flex; justify-content:space-between; gap:12px; padding:5px 0; border-bottom:1px solid rgba(255,255,255,.04); }
        .user-detail-grid p:last-child { border-bottom:0; }

        .user-diagnostics { padding:0 12px 12px; display:flex; flex-direction:column; gap:7px; }
        .diag { border:1px solid rgba(60,120,255,.14); background:rgba(60,120,255,.045); padding:9px 10px; border-radius:5px; font-size:.88rem; color:var(--silver); }
        .diag-ok { border-color:rgba(0,200,100,.24); color:#9dffc4; }
        .diag-warn { border-color:rgba(255,215,0,.28); color:#ffd76a; }
        .diag-danger { border-color:rgba(255,51,68,.28); color:#ff8896; }
        .diag-info { border-color:rgba(60,120,255,.24); color:#9fc0ff; }

        .user-card-actions { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:auto; }
        .user-card-actions .btn-admin, .btn-admin-disabled { width:100%; text-align:center; min-height:37px; display:flex; align-items:center; justify-content:center; }
        .btn-admin-disabled { opacity:.45; border:1px solid var(--border); color:var(--silver-dim); font-family:var(--font-title); font-size:.7rem; letter-spacing:1.5px; text-transform:uppercase; border-radius:3px; }
        .empty-state { padding:18px; color:var(--silver-dim); border:1px dashed rgba(60,120,255,.18); border-radius:6px; }

        @media (max-width: 1000px) { .user-detail-grid { grid-template-columns:1fr; } }
        @media (max-width: 700px) {
            .user-toolbar, .user-toolbar form { flex-direction:column; align-items:stretch; }
            .user-toolbar .admin-search, .user-toolbar select, .user-toolbar .btn-admin, .toggle-pill { width:100%; }
            .user-card-grid { grid-template-columns:1fr; }
            .user-card-head { flex-direction:column; }
            .user-card-badges { flex-direction:row; align-items:flex-start; flex-wrap:wrap; }
            .user-card-actions { grid-template-columns:1fr; }
            .user-mini-grid { grid-template-columns:1fr; }
            .user-section-head { align-items:flex-start; flex-direction:column; }
            .user-section-head b { width:100%; text-align:center; }
        }
    </style>
</head>
<body>
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
        <div class="admin-page-titulo">Manejo de Usuarios</div>
        <div class="admin-page-sub">Gestion de cuentas, progreso, actividad e incidencias de jugadores.</div>
        <div class="admin-separador"></div>

        <div class="users-admin-shell">
            <details class="info-panel">
                <summary><span>Información general</span><span>ABRIR</span></summary>
                <div class="info-panel-content">
                    Este apartado separa la cuenta del jugador de su progreso. La cuenta contiene identidad, email, rol y fecha de registro; el progreso contiene economía, runas, mejoras y estadísticas acumuladas.
                    <div class="info-panel-grid">
                        <div><strong>Cuenta</strong>Datos de usuario, rol, email, género y registro.</div>
                        <div><strong>Progreso</strong>Coins, points, generación pasiva, bulk, suerte y estadísticas.</div>
                        <div><strong>Diagnóstico</strong>Detecta cuentas sin jugador, progreso vacío, datos negativos o estadísticas incompletas.</div>
                        <div><strong>Acciones</strong>Editar cuenta, editar progreso o eliminar usuario con su progreso asociado.</div>
                    </div>
                </div>
            </details>

            <div class="user-toolbar">
                <form method="GET" action="">
                    <input type="text" name="buscar" class="admin-search" placeholder="Buscar por usuario, email o rol..." value="<?= h($buscar) ?>">
                    <select name="rol" aria-label="Filtrar por rol">
                        <option value="" <?= $filtroRol === '' ? 'selected' : '' ?>>Todos los roles</option>
                        <option value="usuario" <?= $filtroRol === 'usuario' ? 'selected' : '' ?>>Usuarios</option>
                        <option value="admin" <?= $filtroRol === 'admin' ? 'selected' : '' ?>>Admins</option>
                    </select>
                    <button type="submit" class="btn-admin btn-admin-primary">Buscar</button>
                    <?php if ($buscar !== '' || $filtroRol !== ''): ?>
                        <a href="usuarios.php" class="btn-admin btn-admin-primary">Limpiar</a>
                    <?php endif; ?>
                </form>
                <div class="suggestions-toggle">
                    <span>Sugerencias de diagnóstico</span>
                    <button class="toggle-pill" type="button" id="toggleUserSuggestions">Mostrar/Ocultar</button>
                </div>
            </div>

            <div class="user-summary-grid">
                <div class="user-summary-card"><strong><?= h($totalUsuarios) ?></strong><span>Cuentas totales</span></div>
                <div class="user-summary-card"><strong><?= h($totalJugadores) ?></strong><span>Jugadores</span></div>
                <div class="user-summary-card"><strong><?= h($totalAdmins) ?></strong><span>Admins</span></div>
                <div class="user-summary-card"><strong><?= h($totalIncidencias) ?></strong><span>Revisiones</span></div>
                <div class="user-summary-card"><strong><?= h(fmtNum($totalTiradas)) ?></strong><span>Tiradas totales</span></div>
                <div class="user-summary-card"><strong><?= h(fmtNum($totalCoins)) ?></strong><span>Coins globales</span></div>
                <div class="user-summary-card"><strong><?= h(fmtNum($totalPoints)) ?></strong><span>Points globales</span></div>
            </div>

            <?php
                renderSection('jugadores', 'Usuarios jugadores', 'cuentas normales con rol usuario', $jugadores, true);
                renderSection('recientes', 'Usuarios recientes', 'registrados en los últimos 14 días', $recientes, false);
                renderSection('progreso-alto', 'Progreso alto', 'top de cuentas con mayor actividad/progreso', $progresoAlto, false);
                renderSection('incidencias', 'Incidencias / revisión', 'cuentas con datos incompletos o sospechosos', $incidencias, false, 'incidencia');
                renderSection('admins', 'Administradores', 'cuentas con permisos de panel', $admins, false);
            ?>
        </div>
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

    document.querySelectorAll('[data-section-toggle]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var body = btn.parentElement.querySelector('.user-section-body');
            var open = body.classList.toggle('open');
            var label = btn.querySelector('b');
            if (label) label.textContent = open ? 'Cerrar⌃' : 'Abrir⌄';
        });
    });

    var saved = localStorage.getItem('rw_user_suggestions_hidden');
    if (saved === '1') document.body.classList.add('hide-user-suggestions');

    var toggle = document.getElementById('toggleUserSuggestions');
    if (toggle) {
        toggle.addEventListener('click', function() {
            document.body.classList.toggle('hide-user-suggestions');
            localStorage.setItem('rw_user_suggestions_hidden', document.body.classList.contains('hide-user-suggestions') ? '1' : '0');
        });
    }
});
</script>
</body>
</html>
