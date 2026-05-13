<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

const DIR_RUNAS       = __DIR__ . "/../RUNAS_HTML/RUNAS/";
const DIR_ANIMADAS    = __DIR__ . "/../RUNAS_HTML/RUNAS_ANIMADAS/";
const DIR_MARCOS      = __DIR__ . "/../RUNAS_HTML/MARCOS/";
const DIR_BACKGROUNDS = __DIR__ . "/../RUNAS_HTML/BACKGROUND/";
const FACTOR_CORRUPTA = 100;
const FACTOR_SUPREMA  = 100000;

function columnExists(mysqli $conexion, string $table, string $column): bool {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conexion->real_escape_string($column);
    $res = $conexion->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $res && $res->num_rows > 0;
}

function fmtNum($n): string {
    $n = (float)$n;
    $strip = static fn($x) => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
    if ($n >= 1e12) return $strip($n / 1e12) . 'T';
    if ($n >= 1e9)  return $strip($n / 1e9)  . 'B';
    if ($n >= 1e6)  return $strip($n / 1e6)  . 'M';
    if ($n >= 1e3)  return $strip($n / 1e3)  . 'k';
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

function fmtFraccion($denom): string {
    $denom = (int)$denom;
    if ($denom <= 1) return '1/1';
    return '1/' . fmtNum($denom);
}

function varianteRuna(array $r): string {
    // Prioridad real: tier/archivo/nombre. No confiar primero en la columna variante,
    // porque en la BD antigua algunas corruptas quedaron marcadas como normal.
    $tier = (int)($r['tier'] ?? 0);
    $nombre = strtolower((string)($r['nombre'] ?? ''));
    $archivo = strtolower(trim((string)($r['archivo_html'] ?? '')));
    if ($archivo === '') $archivo = strtolower(trim((string)($r['imagen'] ?? '')));

    if ($tier >= 200 && $tier < 300) return 'suprema';
    if ($tier >= 100 && $tier < 200) return 'corrupta';
    if (str_contains($nombre, 'suprema') || str_contains($archivo, '_suprema')) return 'suprema';
    if (str_contains($nombre, 'corrupt') || str_contains($archivo, '_corrupta')) return 'corrupta';

    if (!empty($r['variante'])) {
        $v = strtolower((string)$r['variante']);
        if (in_array($v, ['normal', 'corrupta', 'suprema'], true)) return $v;
    }
    return 'normal';
}

function factorVariante(string $variante): int {
    return match ($variante) {
        'corrupta' => FACTOR_CORRUPTA,
        'suprema'  => FACTOR_SUPREMA,
        default    => 1,
    };
}

function archivoEsperado(string $rareza, string $variante): string {
    $rareza = trim($rareza);
    if ($rareza === '') return '';
    if ($variante === 'normal') return $rareza . '.html';
    return $rareza . '_' . $variante . '.html';
}

function archivoRuna(array $r, bool $usarFallback = true): string {
    $archivo = trim((string)($r['archivo_html'] ?? ''));
    if ($archivo === '') $archivo = trim((string)($r['imagen'] ?? ''));
    if ($archivo === '' && $usarFallback) {
        $archivo = archivoEsperado((string)($r['rareza'] ?? ''), varianteRuna($r));
    }
    return basename($archivo);
}

function archivoExiste(?string $archivo, string $dir): bool {
    $archivo = trim((string)$archivo);
    if ($archivo === '') return false;
    return is_file(rtrim($dir, '/') . '/' . basename($archivo));
}

function animacionRuna(array $r): string {
    $rareza = (string)($r['rareza'] ?? '');
    $variante = varianteRuna($r);
    $archivo = archivoRuna($r);
    $base = preg_replace('/\.html?$/i', '', $archivo);
    $candidatos = array_filter([
        $base ? $base . '_animacion.html' : '',
        $rareza && $variante !== 'normal' ? $rareza . '_' . $variante . '_animacion.html' : '',
        $rareza ? $rareza . '_animacion.html' : '',
    ]);
    foreach ($candidatos as $c) {
        if (archivoExiste($c, DIR_ANIMADAS)) return basename($c);
    }
    return '';
}

function esEspecialAnimada(string $rareza): bool {
    return in_array($rareza, ['legendaria', 'mitica', 'divina', 'eterna'], true);
}

function diagnosticoRuna(array $r, array $normalesPorGrupoRareza): array {
    $avisos = [];
    $variante = varianteRuna($r);
    $archivo = archivoRuna($r);
    $rareza = (string)($r['rareza'] ?? '');
    $grupo = (int)($r['grupo_id'] ?? 0);

    if ($archivo === '' || !archivoExiste($archivo, DIR_RUNAS)) {
        $avisos[] = ['tipo' => 'warn', 'txt' => 'Archivo visual no encontrado o no asignado'];
    }

    if ($variante !== 'normal') {
        $baseKey = $grupo . '|' . $rareza;
        if (!isset($normalesPorGrupoRareza[$baseKey])) {
            $avisos[] = ['tipo' => 'warn', 'txt' => 'No existe version normal base para calcular balance'];
        } else {
            $base = (float)$normalesPorGrupoRareza[$baseKey]['multiplicador'];
            $esperado = $base * factorVariante($variante);
            $actual = (float)($r['multiplicador'] ?? 0);
            if (abs($actual - $esperado) > 0.001) {
                $avisos[] = ['tipo' => 'warn', 'txt' => 'Multiplicador esperado: ' . fmtNum($esperado)];
            }
        }
    }

    if (esEspecialAnimada($rareza) && animacionRuna($r) === '') {
        $avisos[] = ['tipo' => 'info', 'txt' => 'Especial sin animacion asignada'];
    }
    if (esEspecialAnimada($rareza) && empty($r['marco_html'])) {
        $avisos[] = ['tipo' => 'info', 'txt' => 'Especial sin marco'];
    }
    if (esEspecialAnimada($rareza) && empty($r['background_html'])) {
        $avisos[] = ['tipo' => 'info', 'txt' => 'Especial sin background'];
    }
    return $avisos;
}

$hasCatalogo   = columnExists($conexion, 'runas', 'catalogo');
$hasVariante   = columnExists($conexion, 'runas', 'variante');
$hasArchivo    = columnExists($conexion, 'runas', 'archivo_html');
$hasMarco      = columnExists($conexion, 'runas', 'marco_html');
$hasBg         = columnExists($conexion, 'runas', 'background_html');
$hasOrden      = columnExists($conexion, 'runas', 'orden_visual');

$select = "r.*";
$sql = "SELECT $select FROM runas r ORDER BY r.grupo_id ASC, COALESCE(r.orden_visual, r.tier) ASC, r.tier ASC, r.id ASC";
if (!$hasOrden) $sql = "SELECT $select FROM runas r ORDER BY r.grupo_id ASC, r.tier ASC, r.id ASC";
$res = $conexion->query($sql);
$runas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

$rarezas = [];
$resRarezas = $conexion->query("SELECT slug,nombre,denominador,color,orden FROM rarezas WHERE activa=1 ORDER BY orden ASC");
while ($row = $resRarezas->fetch_assoc()) $rarezas[$row['slug']] = $row;

$grupos = [];
$resGrupos = $conexion->query("SELECT id,nombre FROM grupos_runas ORDER BY id ASC");
while ($row = $resGrupos->fetch_assoc()) $grupos[(int)$row['id']] = $row['nombre'];

$normalesPorGrupoRareza = [];
foreach ($runas as $r) {
    if (varianteRuna($r) === 'normal') {
        $normalesPorGrupoRareza[(int)$r['grupo_id'] . '|' . $r['rareza']] = $r;
    }
}

$catalogos = [];
foreach ($runas as $r) {
    $catalogo = trim((string)($r['catalogo'] ?? 'basica')) ?: 'basica';
    $grupoId = (int)($r['grupo_id'] ?? 0);
    $catalogoNombre = $grupos[$grupoId] ?? ucfirst($catalogo);
    $variante = varianteRuna($r);
    $catalogos[$grupoId]['nombre'] = $catalogoNombre;
    $catalogos[$grupoId]['variantes'][$variante][] = $r;
}

$variantInfo = [
    'normal'   => ['titulo' => 'Normales',  'sub' => 'Version estable de la runa.', 'factor' => 1],
    'corrupta' => ['titulo' => 'Corruptas','sub' => 'Version fallida. x100 dificultad y x100 recompensa.', 'factor' => FACTOR_CORRUPTA],
    'suprema'  => ['titulo' => 'Supremas', 'sub' => 'Version perfecta. x100.000 dificultad y x100.000 recompensa.', 'factor' => FACTOR_SUPREMA],
];

$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RunaWorld — Manejo de Runas</title>
<link rel="stylesheet" href="../CSS/admin.css">
<style>
.rw-info-toggle{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px}.rw-info-panel{border:1px solid var(--border);background:rgba(60,120,255,.045);border-radius:10px;padding:18px;margin-bottom:18px}.rw-info-panel[hidden]{display:none}.rw-info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.rw-info-card{border:1px solid rgba(60,120,255,.15);background:rgba(0,0,0,.18);border-radius:8px;padding:14px}.rw-info-card strong{display:block;font-family:var(--font-title);letter-spacing:2px;text-transform:uppercase;color:var(--blue-bright);margin-bottom:6px}.rw-info-card span{font-size:.95rem;color:var(--silver-dim)}
.rw-suggestions-toggle{margin-bottom:18px}.rw-suggestions-hidden .rw-warnings{display:none!important}.rw-catalogo{border:1px solid rgba(60,120,255,.24);background:rgba(5,10,25,.62);border-radius:10px;margin-bottom:20px;overflow:hidden}.rw-catalogo-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px 20px;border-bottom:1px solid rgba(60,120,255,.18);cursor:pointer}.rw-catalogo-title{font-family:var(--font-title);font-size:1.15rem;letter-spacing:5px;text-transform:uppercase;color:var(--blue-bright)}.rw-catalogo-sub{font-size:.92rem;color:var(--silver-dim);font-style:italic;margin-top:4px}.rw-catalogo-body{padding:16px}.rw-catalogo.collapsed .rw-catalogo-body{display:none}.rw-open-btn{min-width:92px}.rw-variant{border:1px solid rgba(60,120,255,.18);border-radius:9px;margin-bottom:16px;background:rgba(0,0,0,.16);overflow:hidden}.rw-variant-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;cursor:pointer;border-bottom:1px solid rgba(60,120,255,.12)}.rw-variant-title{font-family:var(--font-title);font-size:1.12rem;letter-spacing:5px;text-transform:uppercase}.rw-variant-title.normal{color:var(--blue-bright)}.rw-variant-title.corrupta{color:#ff4d6d}.rw-variant-title.suprema{color:#ffd36a}.rw-variant-sub{font-style:italic;color:var(--silver-dim);font-size:.9rem;margin-top:4px}.rw-variant-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.rw-variant-body{padding:16px}.rw-variant.collapsed .rw-variant-body{display:none}.rw-runas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;align-items:stretch}.rw-runa-card{border:1px solid rgba(60,120,255,.25);border-radius:9px;background:rgba(3,7,18,.8);padding:14px;display:flex;flex-direction:column;gap:12px;min-height:245px;max-width:100%}.rw-runa-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}.rw-runa-name{font-family:var(--font-title);font-size:1rem;letter-spacing:3px;text-transform:uppercase;line-height:1.05;overflow-wrap:anywhere}.rw-status{font-family:var(--font-title);font-size:.68rem;letter-spacing:2px;text-transform:uppercase;padding:6px 10px;border-radius:3px;border:1px solid rgba(0,200,100,.35);color:#44ff88;background:rgba(0,200,100,.14);white-space:nowrap}.rw-status.off{border-color:rgba(255,51,68,.4);color:#ff7788;background:rgba(255,51,68,.12)}.rw-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px}.rw-stat{border:1px solid rgba(60,120,255,.14);background:rgba(255,255,255,.025);border-radius:6px;padding:9px;min-width:0}.rw-stat-label{display:block;font-family:var(--font-title);font-size:.6rem;letter-spacing:2px;text-transform:uppercase;color:var(--silver-dim);margin-bottom:5px}.rw-stat-value{font-weight:700;color:#dce8ff;overflow-wrap:anywhere}.rw-warnings{display:flex;flex-direction:column;gap:6px}.rw-warning{border:1px solid rgba(255,211,106,.32);background:rgba(255,211,106,.06);color:#ffd36a;border-radius:5px;padding:8px 9px;font-size:.82rem}.rw-warning.info{border-color:rgba(80,150,255,.25);background:rgba(80,150,255,.055);color:#9db8ff}.rw-card-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:4px}.rw-card-actions.preview{grid-template-columns:1fr}.rw-card-actions .btn-admin{width:100%;text-align:center;padding:10px 8px}.rw-empty{border:1px dashed rgba(60,120,255,.25);border-radius:9px;padding:22px;text-align:center;color:var(--silver-dim);font-style:italic}.runa-preview-modal{position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:20000;display:none;align-items:center;justify-content:center;padding:24px}.runa-preview-modal.open{display:flex}.runa-preview-shell{width:min(920px,96vw);height:min(620px,90vh);background:#050711;border:1px solid var(--border);border-radius:10px;display:flex;flex-direction:column;overflow:hidden}.runa-preview-head{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border)}.runa-preview-title{font-family:var(--font-title);letter-spacing:3px;text-transform:uppercase;color:var(--blue-bright)}.runa-preview-body{flex:1}.runa-preview-body iframe{width:100%;height:100%;border:0;background:#000}@media(max-width:760px){.rw-runas-grid{grid-template-columns:1fr}.rw-catalogo-head,.rw-variant-head{align-items:flex-start;flex-direction:column}.rw-variant-actions{width:100%;justify-content:stretch}.rw-variant-actions .btn-admin{flex:1}.rw-card-actions{grid-template-columns:1fr}.rw-runa-card{min-height:auto}}
</style>
</head>
<body>
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>
<div id="admin-layout" class="visible">
<aside id="admin-sidebar"><div id="sidebar-logo"><svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff"><circle cx="200" cy="200" r="185" fill="none" stroke="currentColor"/><circle cx="200" cy="200" r="80" fill="none" stroke="currentColor"/><line x1="200" y1="120" x2="200" y2="280" stroke="currentColor"/><line x1="120" y1="200" x2="280" y2="200" stroke="currentColor"/></svg><div id="sidebar-logo-titulo">RunaWorld</div></div><nav class="admin-nav"><a href="index.php" class="admin-nav-btn"><span class="nav-icon">⬡</span> Dashboard</a><a href="usuarios.php" class="admin-nav-btn"><span class="nav-icon">◈</span> Usuarios</a><a href="runas.php" class="admin-nav-btn active"><span class="nav-icon">◎</span> Runas</a><a href="tienda.php" class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a><a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a><a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a></nav></aside>
<main id="admin-content">
<div class="admin-page-titulo">Manejo de Runas</div>
<div class="admin-page-sub">Catalogo, variantes, archivos visuales, balance y estado de runas.</div>
<div class="admin-separador"></div>

<div class="rw-info-toggle">
    <button type="button" class="btn-admin btn-admin-primary" data-toggle-panel="rw-info-panel">Informacion general</button>
</div>
<section id="rw-info-panel" class="rw-info-panel" hidden>
    <div class="rw-info-grid">
        <div class="rw-info-card"><strong>Normal</strong><span>Version estable de una runa. Usa multiplicador base x1 y la probabilidad definida por su rareza.</span></div>
        <div class="rw-info-card"><strong>Corrupta</strong><span>Version fallida. Es x100 mas dificil y recompensa x100 respecto a la normal.</span></div>
        <div class="rw-info-card"><strong>Suprema</strong><span>Version perfecta. Es x100.000 mas dificil y recompensa x100.000 respecto a la normal.</span></div>
        <div class="rw-info-card"><strong>Animaciones</strong><span>Las runas comunes pueden no tener animacion. Legendarias, miticas, divinas y eternas pueden usar animaciones especiales.</span></div>
        <div class="rw-info-card"><strong>Listado</strong><span>El listado muestra datos y estado. La preview real se abre desde Editar para no cargar todas las runas a la vez.</span></div>
    </div>
</section>
<div class="rw-suggestions-toggle">
    <button type="button" class="btn-admin btn-admin-primary" id="toggleSuggestions">Ocultar sugerencias</button>
</div>

<div id="rwRunasRoot">
<?php foreach ($catalogos as $grupoId => $catalogo): ?>
    <section class="rw-catalogo" data-collapsible="catalogo">
        <header class="rw-catalogo-head">
            <div><div class="rw-catalogo-title"><?= htmlspecialchars($catalogo['nombre']) ?></div><div class="rw-catalogo-sub">Coleccion con variantes normales, corruptas y supremas.</div></div>
            <button type="button" class="btn-admin btn-admin-primary rw-open-btn">Abrir</button>
        </header>
        <div class="rw-catalogo-body">
        <?php foreach (['normal','corrupta','suprema'] as $variante): $items = $catalogo['variantes'][$variante] ?? []; $info = $variantInfo[$variante]; ?>
            <section class="rw-variant <?= $variante === 'suprema' && empty($items) ? 'collapsed' : '' ?>" data-collapsible="variant">
                <header class="rw-variant-head">
                    <div><div class="rw-variant-title <?= $variante ?>"><?= htmlspecialchars($info['titulo']) ?></div><div class="rw-variant-sub"><?= count($items) ?> runas · <?= htmlspecialchars($info['sub']) ?></div></div>
                    <div class="rw-variant-actions">
                        <a class="btn-admin btn-admin-primary" href="crear_runa.php?grupo_id=<?= (int)$grupoId ?>&variante=<?= urlencode($variante) ?>">+ Registrar runa</a>
                        <button type="button" class="btn-admin btn-admin-primary rw-open-btn">Abrir</button>
                    </div>
                </header>
                <div class="rw-variant-body">
                    <?php if (empty($items)): ?>
                        <div class="rw-empty">Falta por añadir / inexistentes para esta variante.</div>
                    <?php else: ?>
                    <div class="rw-runas-grid">
                        <?php foreach ($items as $r):
                            $rareza = (string)$r['rareza'];
                            $denomBase = (int)($rarezas[$rareza]['denominador'] ?? 1);
                            $denom = $denomBase * factorVariante($variante);
                            $archivo = archivoRuna($r);
                            $animacion = animacionRuna($r);
                            $color = $rarezas[$rareza]['color'] ?? 'var(--blue-bright)';
                            $avisos = diagnosticoRuna($r, $normalesPorGrupoRareza);
                        ?>
                        <article class="rw-runa-card">
                            <div class="rw-runa-top"><div class="rw-runa-name" style="color:<?= htmlspecialchars($color) ?>"><?= htmlspecialchars($r['nombre']) ?></div><span class="rw-status <?= ((int)$r['activa'] === 1) ? '' : 'off' ?>"><?= ((int)$r['activa'] === 1) ? 'Activa' : 'Inactiva' ?></span></div>
                            <div class="rw-stats"><div class="rw-stat"><span class="rw-stat-label">Rareza</span><span class="rw-stat-value"><?= htmlspecialchars($rareza) ?></span></div><div class="rw-stat"><span class="rw-stat-label">Probabilidad</span><span class="rw-stat-value"><?= fmtFraccion($denom) ?></span></div><div class="rw-stat"><span class="rw-stat-label">Recompensa</span><span class="rw-stat-value"><?= fmtNum($r['multiplicador'] ?? 0) ?></span></div><div class="rw-stat"><span class="rw-stat-label">Tier</span><span class="rw-stat-value"><?= (int)($r['tier'] ?? 0) ?></span></div></div>
                            <?php if (!empty($avisos)): ?><div class="rw-warnings"><?php foreach ($avisos as $a): ?><div class="rw-warning <?= htmlspecialchars($a['tipo']) ?>">⚠ <?= htmlspecialchars($a['txt']) ?></div><?php endforeach; ?></div><?php endif; ?>
                            <div class="rw-card-actions"><a class="btn-admin btn-admin-primary" href="editar_runa.php?id=<?= (int)$r['id'] ?>">Editar</a><a class="btn-admin btn-admin-danger" href="../PHP/runas_action.php?accion=toggle_activa&id=<?= (int)$r['id'] ?>" onclick="return confirm('¿Cambiar estado de esta runa?')"><?= ((int)$r['activa'] === 1) ? 'Desactivar' : 'Activar' ?></a></div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>
</div>
</main>
</div>

<script>
function toggleAdminNav(){var s=document.getElementById('admin-sidebar'),o=document.getElementById('admin-nav-overlay'),b=document.getElementById('admin-hamburger'),open=s.classList.toggle('open');o.classList.toggle('visible',open);b.innerHTML=open?'&#10005;':'&#9776;';}
function cerrarAdminNav(){document.getElementById('admin-sidebar').classList.remove('open');document.getElementById('admin-nav-overlay').classList.remove('visible');document.getElementById('admin-hamburger').innerHTML='&#9776;';}
document.addEventListener('click',function(e){var panelBtn=e.target.closest('[data-toggle-panel]'); if(panelBtn){var p=document.getElementById(panelBtn.dataset.togglePanel); if(p) p.hidden=!p.hidden; return;} var head=e.target.closest('.rw-catalogo-head,.rw-variant-head'); if(head && !e.target.closest('a')){var box=head.closest('.rw-catalogo,.rw-variant'); if(box){box.classList.toggle('collapsed'); var btn=head.querySelector('.rw-open-btn:last-child'); if(btn) btn.textContent=box.classList.contains('collapsed')?'Abrir':'Cerrar';}}});
var sug=document.getElementById('toggleSuggestions'),root=document.getElementById('rwRunasRoot');sug?.addEventListener('click',function(){root.classList.toggle('rw-suggestions-hidden');sug.textContent=root.classList.contains('rw-suggestions-hidden')?'Ver sugerencias':'Ocultar sugerencias';});
</script>
</body>
</html>
