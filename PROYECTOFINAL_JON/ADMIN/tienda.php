<?php
session_start();
if (!isset($_SESSION["idUsuario"]) || ($_SESSION["rol"] ?? "") !== "admin") {
    header("Location: ../index.php");
    exit;
}
require_once "../PHP/conexion.php";

$stmt = $conexion->prepare("SELECT * FROM mejoras ORDER BY activa DESC, orden ASC, id ASC");
$stmt->execute();
$mejoras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conexion->close();

function fmtAdmin($n) {
    $n = (float)$n;
    if ($n >= 1e12) return rtrim(rtrim(number_format($n/1e12, 2), '0'), '.') . "T";
    if ($n >= 1e9) return rtrim(rtrim(number_format($n/1e9, 2), '0'), '.') . "B";
    if ($n >= 1e6) return rtrim(rtrim(number_format($n/1e6, 2), '0'), '.') . "M";
    if ($n >= 1e3) return rtrim(rtrim(number_format($n/1e3, 2), '0'), '.') . "k";
    return rtrim(rtrim(number_format($n, 4), '0'), '.');
}
function tipoMeta($tipo) {
    $map = [
        "coins_seg" => ["label" => "Coins/seg", "cat" => "normal", "color" => "#ffd700"],
        "points_seg" => ["label" => "Points/seg", "cat" => "normal", "color" => "#d0dcf0"],
        "suerte" => ["label" => "Suerte", "cat" => "normal", "color" => "#44ff88"],
        "coins_seg_multi" => ["label" => "Multi coins", "cat" => "dorada", "color" => "#ffd700"],
        "points_seg_multi" => ["label" => "Multi points", "cat" => "dorada", "color" => "#6a9fff"],
        "bulk_normal" => ["label" => "Bulk normal", "cat" => "dorada", "color" => "#ffcc66"],
        "bulk_extra" => ["label" => "Bulk extra", "cat" => "dorada", "color" => "#ff7788"],
        "desbloquear_boost_leg" => ["label" => "Boost legendario", "cat" => "dorada", "color" => "#ffd700"],
        "desbloquear_boost_div" => ["label" => "Boost divino", "cat" => "dorada", "color" => "#fffbeb"],
        "coins_seg_multi_eterno" => ["label" => "Reactor coins", "cat" => "morada", "color" => "#c080ff"],
        "points_seg_multi_eterno" => ["label" => "Reactor points", "cat" => "morada", "color" => "#c080ff"],
        "bulk" => ["label" => "Bulk eterno", "cat" => "morada", "color" => "#c080ff"],
    ];
    return $map[$tipo] ?? ["label" => $tipo, "cat" => "desconocida", "color" => "#6a9fff"];
}
function condicionLabel($tipo, $valor) {
    $valor = trim((string)$valor);
    if ($tipo === "ninguna" || $tipo === "" || $tipo === null) return "Sin condicion";
    if ($tipo === "tirar_runa_x") return "Tirar " . ($valor !== "" ? $valor : "X") . " runas";
    if ($tipo === "clickar_boost_x") return "Clickar boost " . ($valor !== "" ? $valor : "X") . " veces";
    if ($tipo === "comprar_mejora_id") return "Comprar mejora ID " . ($valor !== "" ? $valor : "?");
    return $tipo . ($valor !== "" ? " = " . $valor : "");
}
function costeNivel($base, $escala, $nivel) {
    if ($nivel <= 1) return (float)$base;
    return (float)$base * pow((float)$escala, $nivel - 1);
}
$ordenCounts = [];
foreach ($mejoras as $m) {
    $ord = (string)($m["orden"] ?? 0);
    $ordenCounts[$ord] = ($ordenCounts[$ord] ?? 0) + 1;
}
function diagnosticos($m, $ordenCounts) {
    $d = [];
    $meta = tipoMeta($m["tipo"] ?? "");
    $condValidas = ["ninguna", "tirar_runa_x", "clickar_boost_x", "comprar_mejora_id"];
    if ($meta["cat"] === "desconocida") $d[] = "Tipo desconocido para el admin.";
    if (trim((string)($m["descripcion"] ?? "")) === "") $d[] = "Falta descripcion visible.";
    if ((float)($m["coste_base"] ?? 0) <= 0) $d[] = "Coste base invalido.";
    if ((float)($m["coste_escala"] ?? 0) < 1) $d[] = "Escala inferior a 1.";
    if ((int)($m["nivel_maximo"] ?? 0) === 0) $d[] = "Nivel maximo 0: revisar si significa nivel unico o sin limite.";
    if (!in_array(($m["condicion_tipo"] ?? "ninguna"), $condValidas, true)) $d[] = "Condicion no reconocida.";
    if (($m["condicion_tipo"] ?? "ninguna") !== "ninguna" && trim((string)($m["condicion_valor"] ?? "")) === "") $d[] = "Condicion con valor vacio.";
    $ord = (string)($m["orden"] ?? 0);
    if (($ordenCounts[$ord] ?? 0) > 1) $d[] = "Orden repetido con otras mejoras.";
    if (!(int)($m["activa"] ?? 0)) $d[] = "Mejora desactivada: no aparece en la tienda del jugador.";
    return $d;
}
$grupos = [
    "normal" => ["titulo" => "Mejoras normales", "sub" => "Economia base, generacion pasiva y suerte.", "items" => []],
    "dorada" => ["titulo" => "Mejoras especiales", "sub" => "Bulk, multiplicadores y desbloqueos dorados.", "items" => []],
    "morada" => ["titulo" => "Mejoras eternas", "sub" => "Reactores y mejoras de alto impacto.", "items" => []],
    "desactivada" => ["titulo" => "Desactivadas", "sub" => "Conservadas en BD pero no disponibles.", "items" => []],
    "desconocida" => ["titulo" => "Tipos desconocidos", "sub" => "Revisar antes de publicar.", "items" => []],
];
foreach ($mejoras as $m) {
    $meta = tipoMeta($m["tipo"] ?? "");
    $cat = !(int)($m["activa"] ?? 0) ? "desactivada" : $meta["cat"];
    $grupos[$cat]["items"][] = $m;
}
include __DIR__ . "/_admin_sidebar_inline.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RunaWorld — Manejo de Tienda</title>
<link rel="stylesheet" href="../CSS/admin.css">
<?php include __DIR__ . "/_tienda_admin_styles.php"; ?>
</head>
<body>
<div id="admin-layout" class="visible">
<?php renderAdminSidebar('tienda'); ?>
<main id="admin-content">
    <div class="admin-page-titulo">Manejo de Tienda</div>
    <div class="admin-page-sub">Gestion de mejoras, balance, condiciones y estado de publicacion.</div>
    <div class="admin-separador"></div>

    <section class="admin-info-panel">
        <div class="admin-info-head" data-toggle-section="info-tienda">
            <div><div class="admin-info-title">Informacion general</div><div class="admin-info-sub">Resumen de campos tecnicos y como afectan al juego.</div></div>
            <button type="button" class="admin-small-btn">Abrir</button>
        </div>
        <div id="info-tienda" class="admin-info-body" style="display:none;">
            <div class="admin-info-card"><h4>Coste y escala</h4><p><strong>coste_base</strong> es el precio inicial. <strong>coste_escala</strong> multiplica el coste en cada nivel.</p></div>
            <div class="admin-info-card"><h4>Valor y nivel maximo</h4><p><strong>valor</strong> define el efecto por nivel. <strong>nivel_maximo</strong> limita la progresion. El valor 0 se marca como dato a revisar.</p></div>
            <div class="admin-info-card"><h4>Condiciones</h4><ul><li><strong>ninguna</strong>: visible desde inicio.</li><li><strong>tirar_runa_x</strong>: tras X tiradas.</li><li><strong>comprar_mejora_id</strong>: depende de otra mejora.</li><li><strong>clickar_boost_x</strong>: depende de boosts.</li></ul></div>
            <div class="admin-info-card"><h4>Categorias</h4><p>El admin agrupa por tipo: normales, especiales/doradas, eternas/moradas y desactivadas. No requiere migracion SQL.</p></div>
        </div>
    </section>

    <div class="admin-toolbar">
        <a href="crear_mejora.php" class="btn-crear" style="margin-bottom:0;">+ Crear mejora</a>
        <button type="button" class="admin-switch-btn" id="toggle-sugerencias">Ocultar sugerencias</button>
    </div>

    <?php foreach ($grupos as $clave => $grupo): ?>
        <?php if (empty($grupo["items"]) && $clave === "desconocida") continue; ?>
        <section class="admin-shop-section <?= empty($grupo['items']) ? 'is-closed' : '' ?>" data-shop-section="<?= htmlspecialchars($clave) ?>">
            <div class="admin-shop-head" data-toggle-shop="<?= htmlspecialchars($clave) ?>">
                <div><div class="admin-shop-title"><?= htmlspecialchars($grupo["titulo"]) ?></div><div class="admin-shop-sub"><?= count($grupo["items"]) ?> mejoras · <?= htmlspecialchars($grupo["sub"]) ?></div></div>
                <button type="button" class="admin-small-btn"><?= empty($grupo["items"]) ? "Abrir" : "Cerrar" ?></button>
            </div>
            <div class="admin-shop-body">
            <?php if (empty($grupo["items"])): ?>
                <div class="admin-empty-box">No hay mejoras en este grupo.</div>
            <?php else: ?>
                <div class="admin-mejoras-grid">
                <?php foreach ($grupo["items"] as $m):
                    $meta = tipoMeta($m["tipo"] ?? "");
                    $diags = diagnosticos($m, $ordenCounts);
                    $nivelMax = (int)($m["nivel_maximo"] ?? 0);
                    $coste2 = costeNivel($m["coste_base"], $m["coste_escala"], 2);
                    $costeMax = $nivelMax > 0 ? costeNivel($m["coste_base"], $m["coste_escala"], $nivelMax) : null;
                    $catVisual = !(int)$m["activa"] ? "desactivada" : $meta["cat"];
                ?>
                    <article class="admin-mejora-card" data-tier="<?= htmlspecialchars($catVisual) ?>" style="--tier-color: <?= htmlspecialchars($meta['color']) ?>;">
                        <div class="admin-mejora-top"><div><div class="admin-mejora-name"><?= htmlspecialchars($m["nombre"] ?? "") ?></div><div class="admin-mejora-type"><?= htmlspecialchars($meta["label"]) ?> · <?= htmlspecialchars($m["tipo"] ?? "") ?></div></div><span class="admin-status-badge <?= (int)$m['activa'] ? 'on' : 'off' ?>"><?= (int)$m["activa"] ? "Activa" : "Inactiva" ?></span></div>
                        <div class="admin-mejora-desc"><?= htmlspecialchars($m["descripcion"] ?: "Sin descripcion.") ?></div>
                        <div class="admin-metric-grid">
                            <div class="admin-metric"><span class="admin-metric-label">Coste base</span><span class="admin-metric-value" style="color:var(--gold);"><?= fmtAdmin($m["coste_base"]) ?> pts</span></div>
                            <div class="admin-metric"><span class="admin-metric-label">Escala</span><span class="admin-metric-value">x<?= fmtAdmin($m["coste_escala"]) ?></span></div>
                            <div class="admin-metric"><span class="admin-metric-label">Valor</span><span class="admin-metric-value">+<?= fmtAdmin($m["valor"]) ?></span></div>
                            <div class="admin-metric"><span class="admin-metric-label">Nivel max.</span><span class="admin-metric-value"><?= $nivelMax > 0 ? $nivelMax : "0 · revisar" ?></span></div>
                        </div>
                        <button type="button" class="admin-small-btn js-open-mejora" style="width:100%;">Ver detalle</button>
                        <div class="admin-mejora-detail">
                            <div class="admin-detail-row"><span>Condicion</span><strong><?= htmlspecialchars(condicionLabel($m["condicion_tipo"] ?? "ninguna", $m["condicion_valor"] ?? "")) ?></strong></div>
                            <div class="admin-detail-row"><span>Orden</span><strong><?= (int)($m["orden"] ?? 0) ?></strong></div>
                            <div class="admin-detail-row"><span>Coste nivel 2</span><strong><?= fmtAdmin($coste2) ?> pts</strong></div>
                            <div class="admin-detail-row"><span>Coste nivel max.</span><strong><?= $costeMax !== null ? fmtAdmin($costeMax) . " pts" : "No calculado" ?></strong></div>
                            <?php if (!empty($diags)): ?><div class="admin-diagnostics"><?php foreach ($diags as $diag): ?><div class="admin-diagnostic">⚠ <?= htmlspecialchars($diag) ?></div><?php endforeach; ?></div><?php endif; ?>
                        </div>
                        <div class="admin-mejora-actions">
                            <a class="btn-admin btn-admin-primary" href="editar_mejora.php?id=<?= (int)$m['id'] ?>">Editar</a>
                            <?php if ((int)$m["activa"]): ?><a class="btn-admin btn-admin-danger" href="../PHP/mejoras_action.php?accion=desactivar&id=<?= (int)$m['id'] ?>" onclick="return confirm('Desactivar mejora <?= htmlspecialchars($m['nombre']) ?>?')">Desactivar</a><?php else: ?><a class="btn-admin btn-admin-success" href="../PHP/mejoras_action.php?accion=activar&id=<?= (int)$m['id'] ?>">Activar</a><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</main>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 document.querySelectorAll('[data-toggle-section]').forEach(function(head){head.addEventListener('click',function(e){var id=head.getAttribute('data-toggle-section'),body=document.getElementById(id),btn=head.querySelector('button'); if(!body)return; var open=body.style.display==='none'; body.style.display=open?'grid':'none'; if(btn)btn.textContent=open?'Cerrar':'Abrir';});});
 document.querySelectorAll('[data-toggle-shop]').forEach(function(head){head.addEventListener('click',function(){var section=head.closest('.admin-shop-section'),btn=head.querySelector('button'); section.classList.toggle('is-closed'); if(btn)btn.textContent=section.classList.contains('is-closed')?'Abrir':'Cerrar';});});
 document.querySelectorAll('.js-open-mejora').forEach(function(btn){btn.addEventListener('click',function(){var card=btn.closest('.admin-mejora-card'); card.classList.toggle('is-open'); btn.textContent=card.classList.contains('is-open')?'Ocultar detalle':'Ver detalle';});});
 var sug=document.getElementById('toggle-sugerencias'); if(sug){sug.addEventListener('click',function(){document.body.classList.toggle('hide-shop-suggestions');sug.textContent=document.body.classList.contains('hide-shop-suggestions')?'Ver sugerencias':'Ocultar sugerencias';});}
});
function toggleAdminNav(){var s=document.getElementById('admin-sidebar'),o=document.getElementById('admin-nav-overlay'),b=document.getElementById('admin-hamburger');if(!s||!o||!b)return;var open=s.classList.toggle('open');o.classList.toggle('visible',open);b.innerHTML=open?'&#10005;':'&#9776;';}
function cerrarAdminNav(){var s=document.getElementById('admin-sidebar'),o=document.getElementById('admin-nav-overlay'),b=document.getElementById('admin-hamburger');if(s)s.classList.remove('open');if(o)o.classList.remove('visible');if(b)b.innerHTML='&#9776;';}
</script>
</body>
</html>
