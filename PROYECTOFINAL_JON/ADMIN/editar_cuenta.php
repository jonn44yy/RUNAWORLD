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

function fmtDateSafe($value) {
    if (!$value) return 'Sin fecha';
    $ts = strtotime($value);
    if (!$ts) return 'Sin fecha';
    return date('d/m/Y', $ts);
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

function selectCol($conexion, $table, $alias, $column, $as, $fallback = 'NULL') {
    if (columnExists($conexion, $table, $column)) {
        return "$alias.`$column` AS `$as`";
    }
    return "$fallback AS `$as`";
}

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) {
    header("Location: usuarios.php?error=id_invalido");
    exit;
}

$errores = $_SESSION["errores"] ?? [];
$ok = $_SESSION["ok"] ?? '';
unset($_SESSION["errores"], $_SESSION["ok"]);

$select = [
    'u.id',
    'u.username',
    'u.email',
    selectCol($conexion, 'usuarios', 'u', 'genero', 'genero', "''"),
    selectCol($conexion, 'usuarios', 'u', 'rol', 'rol', "'usuario'"),
    selectCol($conexion, 'usuarios', 'u', 'fecha_registro', 'fecha_registro', 'NULL'),
    'j.id AS jugador_id',
    selectCol($conexion, 'jugadores', 'j', 'coins', 'coins', '0'),
    selectCol($conexion, 'jugadores', 'j', 'points', 'points', '0'),
    selectCol($conexion, 'jugadores', 'j', 'coins_por_seg', 'coins_por_seg', '0'),
    selectCol($conexion, 'jugadores', 'j', 'points_por_seg', 'points_por_seg', '0')
];

if (tableExists($conexion, 'jugador_stats')) {
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_tiradas', 'total_tiradas', 'NULL');
    $select[] = selectCol($conexion, 'jugador_stats', 'st', 'total_runas_conseguidas', 'total_runas_conseguidas', '0');
    $joinStats = 'LEFT JOIN jugador_stats st ON st.jugador_id = j.id';
} else {
    $select[] = 'NULL AS total_tiradas';
    $select[] = '0 AS total_runas_conseguidas';
    $joinStats = '';
}

$sql = 'SELECT ' . implode(', ', $select) . "
        FROM usuarios u
        LEFT JOIN jugadores j ON u.id = j.usuario_id
        $joinStats
        WHERE u.id = ?";

$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conexion->close();

if (!$usuario) {
    header("Location: usuarios.php?error=usuario_no_encontrado");
    exit;
}

$rol = strtolower((string)($usuario['rol'] ?? 'usuario'));
$esAdmin = $rol === 'admin';
$diagnosticos = [];
if ($esAdmin) {
    $diagnosticos[] = ['info', 'Cuenta administrativa: evita cambiar el rol si es una cuenta necesaria para gestionar el panel.'];
}
if (empty($usuario['jugador_id']) && !$esAdmin) {
    $diagnosticos[] = ['warn', 'Usuario jugador sin fila asociada en jugadores. El progreso no estará completo.'];
}
if (empty($usuario['jugador_id']) && $esAdmin) {
    $diagnosticos[] = ['info', 'Admin sin perfil de jugador. Esto es normal si la cuenta solo sirve para administrar.'];
}
if (!$diagnosticos) {
    $diagnosticos[] = ['ok', 'Cuenta sin incidencias detectadas.'];
}

$sidebar_svg = '
<svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
    <circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/>
    <circle cx="200" cy="200" r="145" fill="none" stroke="currentColor" stroke-width="0.7" opacity="0.7"/>
    <circle cx="200" cy="200" r="80"  fill="none" stroke="currentColor" stroke-width="1" opacity="0.8"/>
    <g stroke="currentColor" stroke-width="2" opacity="1" stroke-linecap="round">
        <line x1="200" y1="125" x2="200" y2="95"/><line x1="193" y1="110" x2="200" y2="95"/><line x1="207" y1="110" x2="200" y2="95"/>
        <line x1="200" y1="275" x2="200" y2="305"/><line x1="193" y1="290" x2="207" y2="290"/>
        <line x1="275" y1="200" x2="305" y2="200"/><line x1="290" y1="193" x2="305" y2="200"/><line x1="290" y1="207" x2="305" y2="200"/>
        <line x1="125" y1="200" x2="95" y2="200"/><line x1="110" y1="193" x2="95" y2="200"/><line x1="110" y1="207" x2="95" y2="200"/>
    </g>
    <g stroke="currentColor" stroke-width="1.5" opacity="0.9">
        <line x1="200" y1="140" x2="200" y2="260"/><line x1="140" y1="200" x2="260" y2="200"/>
        <circle cx="200" cy="200" r="25" fill="none"/><circle cx="200" cy="200" r="5" fill="currentColor"/>
    </g>
</svg>';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Editar Cuenta</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .edit-hero { display:grid; grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr); gap:18px; margin-bottom:22px; }
        .edit-panel { border:1px solid var(--border); background:rgba(8,12,28,.72); border-radius:8px; padding:18px; }
        .edit-kicker { font-family:var(--font-title); letter-spacing:3px; text-transform:uppercase; color:var(--silver-dim); font-size:.68rem; margin-bottom:8px; }
        .edit-name { font-family:var(--font-title); letter-spacing:3px; text-transform:uppercase; color:var(--blue-bright); font-size:1.7rem; line-height:1.05; text-shadow:0 0 15px var(--blue-glow); }
        .edit-email { color:var(--silver-dim); margin-top:8px; font-size:1rem; word-break:break-word; }
        .edit-badges { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
        .edit-badge { font-family:var(--font-title); font-size:.65rem; letter-spacing:2px; text-transform:uppercase; padding:5px 9px; border-radius:3px; border:1px solid var(--border); color:var(--blue-bright); background:rgba(60,120,255,.08); }
        .edit-badge.admin { color:var(--gold); border-color:rgba(255,215,0,.35); background:rgba(255,215,0,.08); }
        .edit-badge.warn { color:#ffd35c; border-color:rgba(255,211,92,.35); background:rgba(255,211,92,.08); }
        .edit-stats-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .edit-stat { border:1px solid rgba(60,120,255,.14); background:rgba(255,255,255,.025); border-radius:6px; padding:12px; }
        .edit-stat span { display:block; font-family:var(--font-title); color:var(--silver-dim); font-size:.62rem; letter-spacing:2px; text-transform:uppercase; margin-bottom:6px; }
        .edit-stat strong { font-family:var(--font-title); color:var(--silver); font-size:1.05rem; }
        .edit-layout { display:grid; grid-template-columns:minmax(0,1fr) minmax(320px,.8fr); gap:20px; align-items:start; }
        .edit-section-title { font-family:var(--font-title); color:var(--blue-bright); letter-spacing:3px; text-transform:uppercase; font-size:.9rem; margin-bottom:14px; }
        .edit-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .edit-form-grid .full { grid-column:1 / -1; }
        .edit-help { color:var(--silver-dim); font-size:.9rem; line-height:1.45; margin-top:6px; }
        .diag-list { display:flex; flex-direction:column; gap:8px; }
        .diag-item { border:1px solid rgba(60,120,255,.18); border-radius:5px; padding:10px 12px; font-size:.92rem; color:var(--silver); }
        .diag-item.ok { border-color:rgba(0,200,100,.28); color:#70ffaa; background:rgba(0,200,100,.05); }
        .diag-item.warn { border-color:rgba(255,211,92,.35); color:#ffd35c; background:rgba(255,211,92,.05); }
        .diag-item.info { border-color:rgba(60,120,255,.26); color:var(--blue-bright); background:rgba(60,120,255,.05); }
        .edit-actions { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:20px; }
        .edit-actions .btn-admin, .edit-actions button { width:100%; text-align:center; justify-content:center; }
        @media (max-width: 1050px) { .edit-hero, .edit-layout { grid-template-columns:1fr; } }
        @media (max-width: 700px) { .edit-form-grid, .edit-stats-grid, .edit-actions { grid-template-columns:1fr; } }
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
        <div class="admin-page-titulo">Editar Cuenta</div>
        <div class="admin-page-sub">Datos principales, permisos y seguridad de la cuenta.</div>
        <div class="admin-separador"></div>

        <?php if ($ok): ?><p class="admin-msg-ok"><?= h($ok) ?></p><?php endif; ?>
        <?php foreach ($errores as $e): ?><p class="admin-msg-error"><?= h($e) ?></p><?php endforeach; ?>

        <section class="edit-hero">
            <div class="edit-panel">
                <div class="edit-kicker">Usuario #<?= (int)$usuario['id'] ?></div>
                <div class="edit-name"><?= h($usuario['username']) ?></div>
                <div class="edit-email"><?= h($usuario['email']) ?></div>
                <div class="edit-badges">
                    <span class="edit-badge <?= $esAdmin ? 'admin' : '' ?>"><?= h($rol) ?></span>
                    <span class="edit-badge">Registro <?= h(fmtDateSafe($usuario['fecha_registro'] ?? null)) ?></span>
                    <span class="edit-badge <?= empty($usuario['jugador_id']) ? 'warn' : '' ?>"><?= empty($usuario['jugador_id']) ? 'Sin jugador' : 'Jugador asociado' ?></span>
                </div>
            </div>
            <div class="edit-panel">
                <div class="edit-section-title">Resumen rápido</div>
                <div class="edit-stats-grid">
                    <div class="edit-stat"><span>Coins</span><strong><?= h(number_format((float)($usuario['coins'] ?? 0), 0, '.', '')) ?></strong></div>
                    <div class="edit-stat"><span>Points</span><strong><?= h(number_format((float)($usuario['points'] ?? 0), 0, '.', '')) ?></strong></div>
                    <div class="edit-stat"><span>Tiradas</span><strong><?= $usuario['total_tiradas'] === null ? 'Sin stats' : h((int)$usuario['total_tiradas']) ?></strong></div>
                    <div class="edit-stat"><span>Runas</span><strong><?= h((int)($usuario['total_runas_conseguidas'] ?? 0)) ?></strong></div>
                </div>
            </div>
        </section>

        <form method="POST" action="../PHP/editar_cuenta_action.php">
            <input type="hidden" name="id" value="<?= (int)$usuario['id'] ?>">
            <section class="edit-layout">
                <div class="edit-panel">
                    <div class="edit-section-title">Datos principales</div>
                    <div class="edit-form-grid">
                        <div class="admin-form-grupo">
                            <label class="admin-form-label">Nombre de usuario</label>
                            <input type="text" name="username" class="admin-form-input" value="<?= h($usuario['username']) ?>" required minlength="3">
                        </div>
                        <div class="admin-form-grupo">
                            <label class="admin-form-label">Email</label>
                            <input type="email" name="email" class="admin-form-input" value="<?= h($usuario['email']) ?>" required>
                        </div>
                        <div class="admin-form-grupo">
                            <label class="admin-form-label">Género</label>
                            <select name="genero" class="admin-form-select">
                                <option value="masculino" <?= ($usuario['genero'] ?? '') === "masculino" ? "selected" : "" ?>>Masculino</option>
                                <option value="femenino" <?= ($usuario['genero'] ?? '') === "femenino" ? "selected" : "" ?>>Femenino</option>
                                <option value="otro" <?= ($usuario['genero'] ?? '') === "otro" ? "selected" : "" ?>>Otro</option>
                            </select>
                        </div>
                        <div class="admin-form-grupo">
                            <label class="admin-form-label">Rol</label>
                            <select name="rol" class="admin-form-select">
                                <option value="usuario" <?= $rol === "usuario" ? "selected" : "" ?>>Usuario</option>
                                <option value="admin" <?= $rol === "admin" ? "selected" : "" ?>>Admin</option>
                            </select>
                            <div class="edit-help">Cambiar rol afecta al acceso al panel. No te podrás quitar admin a ti mismo.</div>
                        </div>
                        <div class="admin-form-grupo full">
                            <label class="admin-form-label">Nueva contraseña</label>
                            <input type="password" name="password" class="admin-form-input" placeholder="Dejar vacío para no cambiar">
                            <div class="edit-help">Solo se actualiza si escribes una contraseña nueva. Recomendado mínimo 6 caracteres.</div>
                        </div>
                    </div>
                </div>

                <aside class="edit-panel">
                    <div class="edit-section-title">Diagnóstico</div>
                    <div class="diag-list">
                        <?php foreach ($diagnosticos as $d): ?>
                            <div class="diag-item <?= h($d[0]) ?>"><?= h($d[1]) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="edit-section-title" style="margin-top:18px;">Acciones</div>
                    <div class="edit-help">Guardar solo modifica la cuenta. Los recursos y estadísticas se cambian desde Editar progreso.</div>
                    <div class="edit-actions">
                        <button type="submit" class="admin-form-submit">Guardar cambios</button>
                        <a href="usuarios.php" class="btn-admin btn-admin-primary">Cancelar</a>
                    </div>
                </aside>
            </section>
        </form>
    </main>
</div>
<script src="../JS/admin-mobile.js?v=3"></script>
<script>
function toggleAdminNav(){var s=document.getElementById('admin-sidebar'),o=document.getElementById('admin-nav-overlay'),b=document.getElementById('admin-hamburger');var open=s.classList.toggle('open');o.classList.toggle('visible',open);b.innerHTML=open?'&#10005;':'&#9776;';}
function cerrarAdminNav(){document.getElementById('admin-sidebar').classList.remove('open');document.getElementById('admin-nav-overlay').classList.remove('visible');document.getElementById('admin-hamburger').innerHTML='&#9776;';}
</script>
</body>
</html>