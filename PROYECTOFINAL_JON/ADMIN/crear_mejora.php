<?php
session_start();
if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? "") !== "admin") { header("Location: ../index.php"); exit; }
require_once "../PHP/conexion.php";
$errores = $_SESSION["errores"] ?? [];
unset($_SESSION["errores"]);
$tipos = [
 "coins_seg"=>"Coins/seg", "points_seg"=>"Points/seg", "suerte"=>"Suerte",
 "coins_seg_multi"=>"Multiplicador coins/seg", "points_seg_multi"=>"Multiplicador points/seg",
 "bulk_normal"=>"Bulk normal", "bulk_extra"=>"Bulk extra", "desbloquear_boost_leg"=>"Desbloquear boost legendario", "desbloquear_boost_div"=>"Desbloquear boost divino",
 "coins_seg_multi_eterno"=>"Reactor coins eterno", "points_seg_multi_eterno"=>"Reactor points eterno", "bulk"=>"Bulk eterno"
];
$mejoras_ref = [];
$res = $conexion->query("SELECT id, nombre FROM mejoras ORDER BY orden ASC, id ASC");
if ($res) { while ($r = $res->fetch_assoc()) $mejoras_ref[] = $r; }
$conexion->close();
$m = ["id"=>0,"nombre"=>"","tipo"=>"coins_seg","coste_base"=>"100","coste_escala"=>"2","valor"=>"1","nivel_maximo"=>"10","condicion_tipo"=>"ninguna","condicion_valor"=>"","orden"=>"0","descripcion"=>"","activa"=>0];
include __DIR__ . "/_admin_sidebar_inline.php";
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>RunaWorld — Crear Mejora</title><link rel="stylesheet" href="../CSS/admin.css"><?php include __DIR__ . "/_tienda_admin_styles.php"; ?></head>
<body><div id="admin-layout" class="visible"><?php renderAdminSidebar('tienda'); ?><main id="admin-content">
<div class="admin-page-titulo">Crear Mejora</div><div class="admin-page-sub">Registrar una mejora nueva en el catalogo de tienda.</div><div class="admin-separador"></div>
<a href="tienda.php" class="btn-admin btn-admin-primary" style="margin-bottom:18px;display:inline-block;">← Volver a Tienda</a>
<?php foreach ($errores as $e): ?><p class="admin-msg-error"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
<form method="POST" action="../PHP/mejoras_action.php" class="admin-form-layout"><input type="hidden" name="accion" value="crear">
<section class="admin-form-panel"><div class="admin-form-panel-title">Datos de la mejora</div><div class="admin-form-grid">
<div class="admin-form-grupo"><label class="admin-form-label">Nombre</label><input type="text" name="nombre" class="admin-form-input" value="<?= htmlspecialchars($m['nombre']) ?>" required></div>
<div class="admin-form-grupo"><label class="admin-form-label">Tipo</label><select name="tipo" id="tipo" class="admin-form-select" required><?php foreach ($tipos as $v=>$label): ?><option value="<?= htmlspecialchars($v) ?>" <?= $m['tipo']===$v?'selected':'' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
<div class="admin-form-grupo"><label class="admin-form-label">Coste base</label><input type="text" name="coste_base" id="coste_base" class="admin-form-input input-abbr" value="<?= htmlspecialchars($m['coste_base']) ?>" required></div>
<div class="admin-form-grupo"><label class="admin-form-label">Escala de coste</label><input type="number" name="coste_escala" id="coste_escala" class="admin-form-input" min="1" step="0.01" value="<?= htmlspecialchars($m['coste_escala']) ?>" required></div>
<div class="admin-form-grupo"><label class="admin-form-label">Valor por nivel</label><input type="text" name="valor" class="admin-form-input input-abbr" value="<?= htmlspecialchars($m['valor']) ?>" required></div>
<div class="admin-form-grupo"><label class="admin-form-label">Nivel maximo</label><input type="number" name="nivel_maximo" id="nivel_maximo" class="admin-form-input" min="0" value="<?= htmlspecialchars($m['nivel_maximo']) ?>" required></div>
<div class="admin-form-grupo"><label class="admin-form-label">Orden</label><input type="number" name="orden" class="admin-form-input" value="<?= htmlspecialchars($m['orden']) ?>"></div>
<div class="admin-form-grupo"><label class="admin-form-label">Activa al crear</label><select name="activa" class="admin-form-select"><option value="0" selected>No, dejar en pruebas</option><option value="1">Si, publicar</option></select></div>
<div class="admin-form-grupo"><label class="admin-form-label">Condicion</label><select name="condicion_tipo" id="condicion_tipo" class="admin-form-select"><option value="ninguna">Ninguna</option><option value="tirar_runa_x">Tirar X runas</option><option value="comprar_mejora_id">Comprar mejora ID</option><option value="clickar_boost_x">Clickar boost X veces</option></select></div>
<div class="admin-form-grupo"><label class="admin-form-label">Valor condicion</label><input type="text" name="condicion_valor" id="condicion_valor" class="admin-form-input" value=""></div>
<div class="admin-form-grupo full"><label class="admin-form-label">Descripcion</label><textarea name="descripcion" class="admin-form-textarea" maxlength="255"></textarea></div>
</div><div class="admin-form-actions"><button type="submit" class="admin-form-submit">Crear mejora</button><a href="tienda.php" class="btn-admin btn-admin-danger">Cancelar</a></div></section>
<aside class="admin-side-panel"><div class="admin-form-panel-title">Preview y diagnostico</div><div class="admin-help-block" id="condicion_help">Sin condicion: visible desde el inicio.</div><div class="admin-help-block">Las mejoras nuevas se crean desactivadas por defecto para poder testearlas antes de publicarlas.</div><div class="admin-preview-costs"><div class="admin-metric"><span class="admin-metric-label">Coste nivel 1</span><span class="admin-metric-value" id="prev_n1">-</span></div><div class="admin-metric"><span class="admin-metric-label">Coste nivel 2</span><span class="admin-metric-value" id="prev_n2">-</span></div><div class="admin-metric"><span class="admin-metric-label">Coste max</span><span class="admin-metric-value" id="prev_max">-</span></div><div class="admin-metric"><span class="admin-metric-label">Categoria</span><span class="admin-metric-value" id="prev_cat">-</span></div></div></aside>
</form></main></div>
<script src="../JS/abbr-input.js"></script>
<script src="../JS/tienda-admin-form.js"></script>
<script>function toggleAdminNav(){var s=document.getElementById('admin-sidebar'),o=document.getElementById('admin-nav-overlay'),b=document.getElementById('admin-hamburger');var open=s.classList.toggle('open');o.classList.toggle('visible',open);b.innerHTML=open?'&#10005;':'&#9776;';}function cerrarAdminNav(){document.getElementById('admin-sidebar').classList.remove('open');document.getElementById('admin-nav-overlay').classList.remove('visible');document.getElementById('admin-hamburger').innerHTML='&#9776;';}</script>
</body></html>
