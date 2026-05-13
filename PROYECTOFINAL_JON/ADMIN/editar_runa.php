<?php
session_start();
if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit;
}
require_once "../PHP/conexion.php";

const DIR_RUNAS       = __DIR__ . "/../RUNAS_HTML/RUNAS/";
const DIR_ANIMADAS    = __DIR__ . "/../RUNAS_HTML/RUNAS_ANIMADAS/";

function columnExists($conexion, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = $conexion->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
    $res = $conexion->query($sql);
    return $res && $res->num_rows > 0;
}
function archivoEsperado(string $rareza, string $variante): string {
    if ($rareza === '') return '';
    return $variante === 'normal' ? $rareza . '.html' : $rareza . '_' . $variante . '.html';
}
function archivoRuna(array $r, bool $fallback = true): string {
    $archivo = trim((string)($r['archivo_html'] ?? ''));
    if ($archivo === '') $archivo = trim((string)($r['imagen'] ?? ''));
    if ($archivo === '' && $fallback) $archivo = archivoEsperado((string)($r['rareza'] ?? ''), varianteRuna($r));
    return basename($archivo);
}
function varianteRuna(array $r): string {
    $tier = (int)($r['tier'] ?? 0);
    $n = strtolower((string)($r['nombre'] ?? ''));
    $a = strtolower(trim((string)($r['archivo_html'] ?? '')));
    if ($a === '') $a = strtolower(trim((string)($r['imagen'] ?? '')));

    if ($tier >= 200 && $tier < 300) return 'suprema';
    if ($tier >= 100 && $tier < 200) return 'corrupta';
    if (str_contains($n, 'suprema') || str_contains($a, '_suprema')) return 'suprema';
    if (str_contains($n, 'corrupt') || str_contains($a, '_corrupta')) return 'corrupta';

    if (!empty($r['variante'])) {
        $v = strtolower((string)$r['variante']);
        if (in_array($v, ['normal','corrupta','suprema'], true)) return $v;
    }
    return 'normal';
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
    foreach ($candidatos as $c) if (archivoExiste($c, DIR_ANIMADAS)) return basename($c);
    return '';
}
function fmtNum($n): string {
    $n=(float)$n; $strip=static fn($x)=>rtrim(rtrim(number_format($x,2,'.',''),'0'),'.');
    if($n>=1e12)return $strip($n/1e12).'T'; if($n>=1e9)return $strip($n/1e9).'B'; if($n>=1e6)return $strip($n/1e6).'M'; if($n>=1e3)return $strip($n/1e3).'k'; return rtrim(rtrim(number_format($n,2,'.',''),'0'),'.');
}
function fmtFraccion($d): string { $d=(int)$d; return $d<=1?'1/1':'1/'.fmtNum($d); }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die('ID invalido');
$errores = $_SESSION['errores'] ?? [];
unset($_SESSION['errores']);

$hasMarco = columnExists($conexion, 'runas', 'marco_html');
$hasBg = columnExists($conexion, 'runas', 'background_html');

$stmt = $conexion->prepare("SELECT * FROM runas WHERE id=?");
$stmt->bind_param('i', $id);
$stmt->execute();
$runa = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$runa) { $conexion->close(); die('Runa no encontrada'); }

$variante = varianteRuna($runa);
$archivo = archivoRuna($runa);
$animacion = animacionRuna($runa);
$animacionUrl = $animacion ? '../RUNAS_HTML/RUNAS_ANIMADAS/' . rawurlencode($animacion) : '';

$stmt = $conexion->prepare("SELECT * FROM grupos_runas ORDER BY id ASC");
$stmt->execute();
$grupos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conexion->prepare("SELECT slug,nombre,color,denominador FROM rarezas WHERE activa=1 ORDER BY orden ASC");
$stmt->execute();
$rarezas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$denominadorBase = 1;
foreach ($rarezas as $rz) if ($rz['slug'] === $runa['rareza']) $denominadorBase = (int)$rz['denominador'];
$factor = $variante === 'corrupta' ? 100 : ($variante === 'suprema' ? 100000 : 1);
$probabilidad = $denominadorBase * $factor;

$conexion->close();
$titulos = ['normal'=>'Normal','corrupta'=>'Corrupta','suprema'=>'Suprema'];
$factorTxt = ['normal'=>'x1 manual','corrupta'=>'x100 heredado desde la normal','suprema'=>'x100.000 heredado desde la normal'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RunaWorld — Editar Runa</title>
<link rel="stylesheet" href="../CSS/admin.css">
<style>
.edit-runa-layout{display:grid;grid-template-columns:minmax(0,.95fr) minmax(0,1.25fr);gap:24px;align-items:start;max-width:1500px}.edit-panel{border:1px solid var(--border);background:rgba(60,120,255,.04);border-radius:10px;padding:20px;min-width:0}.edit-panel-title{font-family:var(--font-title);letter-spacing:4px;text-transform:uppercase;color:var(--blue-bright);margin-bottom:16px}.edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.edit-grid .full{grid-column:1/-1}.form-help{font-size:.86rem;color:var(--silver-dim);font-style:italic}.preview-box{height:520px;border:1px solid var(--border);border-radius:10px;background:#050711;display:flex;align-items:center;justify-content:center;overflow:hidden}.preview-box iframe{width:100%;height:100%;border:0;background:#000}.preview-empty{color:var(--silver-dim);font-style:italic}.variant-pill{display:inline-block;padding:8px 13px;border:1px solid var(--border);border-radius:4px;font-family:var(--font-title);letter-spacing:2px;text-transform:uppercase;color:var(--blue-bright);margin-bottom:16px}.info-box{border:1px solid rgba(60,120,255,.18);background:rgba(0,0,0,.18);color:var(--silver);padding:11px 12px;border-radius:6px;font-size:.95rem}.info-box strong{font-family:var(--font-title);letter-spacing:2px;text-transform:uppercase;color:var(--blue-bright);display:block;margin-bottom:4px}.edit-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px;width:100%;max-width:none}.edit-actions .admin-form-submit,.edit-actions .btn-admin{width:100%;text-align:center}.edit-actions .admin-form-submit{align-self:stretch;margin:0}.preview-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.preview-actions .btn-admin{width:100%;text-align:center}.edit-info-card{border:1px solid rgba(60,120,255,.16);border-radius:6px;background:rgba(0,0,0,.18);padding:12px;margin-bottom:12px}.edit-info-card strong{display:block;font-family:var(--font-title);letter-spacing:2px;text-transform:uppercase;color:var(--blue-bright);margin-bottom:6px}.edit-info-card span{font-size:.92rem;color:var(--silver-dim)}.optional-title{grid-column:1/-1;font-family:var(--font-title);letter-spacing:4px;text-transform:uppercase;color:var(--blue-bright);margin-top:4px;border-top:1px solid rgba(60,120,255,.18);padding-top:16px}.admin-form-input[type=file]{padding:0;border:1px solid var(--border);background:rgba(255,255,255,.03);color:var(--silver-dim);cursor:pointer}.admin-form-input[type=file]::file-selector-button{margin-right:12px;padding:12px 16px;border:0;border-right:1px solid var(--blue);background:transparent;color:var(--blue-bright);font-family:var(--font-title);letter-spacing:2px;text-transform:uppercase;cursor:pointer}.admin-form-input[type=file]:hover::file-selector-button{background:var(--blue);color:#fff}.preview-empty{padding:20px;text-align:center}@media(max-width:1100px){.edit-runa-layout{grid-template-columns:1fr}.preview-box{height:340px}}@media(max-width:700px){.edit-grid,.preview-actions,.edit-actions{grid-template-columns:1fr}.edit-panel{padding:14px}}
</style>
</head>
<body>
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>
<div id="admin-layout" class="visible">
<aside id="admin-sidebar"><div id="sidebar-logo"><svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff"><circle cx="200" cy="200" r="185" fill="none" stroke="currentColor"/><circle cx="200" cy="200" r="80" fill="none" stroke="currentColor"/><line x1="200" y1="120" x2="200" y2="280" stroke="currentColor"/><line x1="120" y1="200" x2="280" y2="200" stroke="currentColor"/></svg><div id="sidebar-logo-titulo">RunaWorld</div></div><nav class="admin-nav"><a href="index.php" class="admin-nav-btn"><span class="nav-icon">⬡</span> Dashboard</a><a href="usuarios.php" class="admin-nav-btn"><span class="nav-icon">◈</span> Usuarios</a><a href="runas.php" class="admin-nav-btn active"><span class="nav-icon">◎</span> Runas</a><a href="tienda.php" class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a><a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a><a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a></nav></aside>
<main id="admin-content">
<div class="admin-page-titulo">Editar Runa</div>
<div class="admin-page-sub">Modificando: <strong style="color:var(--blue-bright)"><?= htmlspecialchars($runa['nombre']) ?></strong></div>
<div class="admin-separador"></div>
<a href="runas.php" class="btn-admin btn-admin-primary" style="margin-bottom:24px">← Volver</a>
<div class="variant-pill">Variante detectada: <?= htmlspecialchars($titulos[$variante]) ?> · <?= htmlspecialchars($factorTxt[$variante]) ?></div>
<?php foreach($errores as $e): ?><p class="admin-msg-error"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
<form method="POST" action="../PHP/runas_action.php" enctype="multipart/form-data" id="runaForm">
<input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int)$runa['id'] ?>"><input type="hidden" name="variante" value="<?= htmlspecialchars($variante) ?>">
<div class="edit-runa-layout">
<section class="edit-panel"><div class="edit-panel-title">Datos principales</div><div class="edit-grid">
<div class="admin-form-grupo"><label class="admin-form-label">Coleccion / lista</label><select name="grupo_id" class="admin-form-select" required><?php foreach($grupos as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)$runa['grupo_id']===(int)$g['id']?'selected':'' ?>><?= htmlspecialchars($g['nombre']) ?></option><?php endforeach; ?></select></div>
<div class="admin-form-grupo"><label class="admin-form-label">Estado</label><select name="activa" class="admin-form-select"><option value="1" <?= (int)$runa['activa']===1?'selected':'' ?>>Activa</option><option value="0" <?= (int)$runa['activa']!==1?'selected':'' ?>>Inactiva / pruebas</option></select></div>
<div class="admin-form-grupo full"><label class="admin-form-label">Nombre visible</label><input type="text" name="nombre" class="admin-form-input" value="<?= htmlspecialchars($runa['nombre']) ?>" required></div>
<div class="admin-form-grupo"><label class="admin-form-label">Rareza base</label><select name="rareza" class="admin-form-select" required><?php foreach($rarezas as $rz): ?><option value="<?= htmlspecialchars($rz['slug']) ?>" style="color:<?= htmlspecialchars($rz['color']) ?>" <?= $runa['rareza']===$rz['slug']?'selected':'' ?>><?= htmlspecialchars($rz['nombre']) ?></option><?php endforeach; ?></select><div class="form-help">La probabilidad pertenece a la rareza, no a cada runa individual.</div></div>
<div class="admin-form-grupo"><label class="admin-form-label">Probabilidad calculada</label><input type="text" class="admin-form-input" value="<?= htmlspecialchars(fmtFraccion($probabilidad)) ?>" readonly><div class="form-help">Para cambiar probabilidades globales hay que editar la tabla de rarezas.</div></div>
<?php if ($variante === 'normal'): ?><div class="admin-form-grupo"><label class="admin-form-label">Multiplicador base</label><input type="text" name="multiplicador" class="admin-form-input input-abbr" value="<?= htmlspecialchars($runa['multiplicador']) ?>" required></div><?php else: ?><input type="hidden" name="multiplicador" value="0"><div class="info-box full"><strong>Recompensa heredada</strong>Esta variante usa el multiplicador de su version normal como base y aplica <?= htmlspecialchars($factorTxt[$variante]) ?> al guardar.</div><?php endif; ?>
<div class="admin-form-grupo full"><label class="admin-form-label">Archivo HTML</label><input type="text" name="archivo_html" id="archivo_html" class="admin-form-input" value="<?= htmlspecialchars($archivo) ?>"><div class="form-help">Archivo ubicado en RUNAS_HTML/RUNAS/.</div></div>
<div class="admin-form-grupo full"><label class="admin-form-label">Subir reemplazo visual</label><input type="file" name="archivo_upload" id="archivo_upload" class="admin-form-input" accept=".html,.htm,.svg"></div>
<div class="optional-title">Opcional</div>
<div class="admin-form-grupo"><label class="admin-form-label">Marco</label><input type="text" name="marco_html" class="admin-form-input" value="<?= $hasMarco ? htmlspecialchars((string)($runa['marco_html'] ?? '')) : '' ?>" placeholder="marco_mitico.html"><input type="file" name="marco_upload" class="admin-form-input" accept=".html,.htm,.svg"></div>
<div class="admin-form-grupo"><label class="admin-form-label">Background</label><input type="text" name="background_html" class="admin-form-input" value="<?= $hasBg ? htmlspecialchars((string)($runa['background_html'] ?? '')) : '' ?>" placeholder="background_mitico.html"><input type="file" name="background_upload" class="admin-form-input" accept=".html,.htm,.svg"></div>
</div></section>
<aside class="edit-panel"><div class="edit-panel-title">Preview y avisos</div><div class="edit-info-card"><strong>Preview real</strong><span>Previsualización del archivo visual asignado a esta runa.</span></div><div class="preview-box" id="previewBox"><?php if($archivo && archivoExiste($archivo, DIR_RUNAS)): ?><iframe src="../RUNAS_HTML/RUNAS/<?= rawurlencode($archivo) ?>"></iframe><?php else: ?><span class="preview-empty">Sin preview disponible.</span><?php endif; ?></div><?php if($animacion): ?><div class="preview-actions"><button type="button" class="btn-admin btn-admin-primary" onclick="cargarPreview('<?= htmlspecialchars($animacionUrl, ENT_QUOTES) ?>')">Ver animación</button><a class="btn-admin btn-admin-primary" href="<?= htmlspecialchars($animacionUrl) ?>" target="_blank" rel="noopener">Abrir animación</a></div><?php endif; ?><div class="edit-info-card" style="margin-top:12px"><strong>Animacion</strong><span><?= htmlspecialchars($animacion ?: 'Sin animación asignada') ?></span></div></aside>
</div><div class="edit-actions"><button type="submit" class="admin-form-submit">Guardar cambios</button><a class="btn-admin btn-admin-primary" href="runas.php">Cancelar</a></div></form>
</main></div>
<script>
function toggleAdminNav(){var s=document.getElementById('admin-sidebar'),o=document.getElementById('admin-nav-overlay'),b=document.getElementById('admin-hamburger'),open=s.classList.toggle('open');o.classList.toggle('visible',open);b.innerHTML=open?'&#10005;':'&#9776;';}
function cerrarAdminNav(){document.getElementById('admin-sidebar').classList.remove('open');document.getElementById('admin-nav-overlay').classList.remove('visible');document.getElementById('admin-hamburger').innerHTML='&#9776;';}
const input=document.getElementById('archivo_upload'),txt=document.getElementById('archivo_html'),box=document.getElementById('previewBox');function cargarPreview(src){box.innerHTML='<iframe src="'+src+'"></iframe>';}function showBlob(file){const url=URL.createObjectURL(file);cargarPreview(url);}input?.addEventListener('change',e=>{const f=e.target.files[0]; if(f) showBlob(f);});txt?.addEventListener('input',()=>{const v=txt.value.trim();box.innerHTML=v?'<iframe src="../RUNAS_HTML/RUNAS/'+encodeURIComponent(v)+'"></iframe>':'<span class="preview-empty">Sin archivo visual.</span>';});
</script>
<script src="../JS/abbr-input.js"></script>
</body></html>
