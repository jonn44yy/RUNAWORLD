<?php
// 27/04 v3: pagina de admin para editar el sistema de cascada de probabilidades.
// reemplaza la antigua curvas.php (que gestionaba la curva campana).
// aqui solo se edita el campo `denominador` de la tabla `rarezas`.
//
// como funciona la cascada:
//   en cada tirada, el server rolea de la rareza con MAYOR denominador
//   a la de MENOR denominador. para cada una hace mt_rand(1, denom) === 1.
//   la primera que acierte gana. si nada acierta, cae la rareza con
//   denominador 1 (fallback, normalmente "comun").

session_start();
if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php"); exit;
}
require_once "../PHP/conexion.php";

// ── ACCION POST: guardar denominadores ─────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $denoms = $_POST["denominador"] ?? [];
    if (is_array($denoms)) {
        $stmt = $conexion->prepare("UPDATE rarezas SET denominador = ? WHERE id = ?");
        foreach ($denoms as $id => $val) {
            $rid   = (int)$id;
            $denom = max(1, (int)$val);
            $stmt->bind_param("ii", $denom, $rid);
            $stmt->execute();
        }
        $stmt->close();
    }
    $conexion->close();
    header("Location: cascada.php?ok=1"); exit;
}

// ── CARGAR RAREZAS ─────────────────────────────────────────────
$stmt = $conexion->prepare("
    SELECT id, slug, nombre, color, denominador, activa
    FROM rarezas
    ORDER BY denominador DESC, orden ASC
");
$stmt->execute();
$rarezas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conexion->close();

// helper: formatea un denominador como fraccion legible (1/100k, 1/25, 1/1)
function fmtFraccion($denom) {
    if ($denom <= 1) return "1/1";
    if ($denom >= 1000000) return "1/" . round($denom / 1000000) . "M";
    if ($denom >= 1000)    return "1/" . round($denom / 1000)    . "k";
    return "1/" . $denom;
}

// calcular probabilidad real de cada rareza tras la cascada
// y la prob "directa" (lo que pone en el denominador, sin tener en cuenta el resto)
$prob_real_pct   = [];
$prob_direct_pct = [];
$running = 1.0;
foreach ($rarezas as $r) {
    $denom = max(1, (int)$r["denominador"]);
    $prob_direct_pct[$r["slug"]] = (1.0 / $denom) * 100;

    if (!$r["activa"]) {
        $prob_real_pct[$r["slug"]] = 0;
        continue;
    }
    if ($denom <= 1) {
        $prob_real_pct[$r["slug"]] = $running * 100;
        $running = 0.0;
    } else {
        $hit = 1.0 / $denom;
        $prob_real_pct[$r["slug"]] = $running * $hit * 100;
        $running *= (1.0 - $hit);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Cascada de Rareza</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .cascada-tabla { width:100%; border-collapse:collapse; font-size:0.85rem; }
        .cascada-tabla th { padding:10px 12px; text-align:left; color:var(--silver-dim); font-family:var(--font-title); letter-spacing:2px; font-size:0.65rem; border-bottom:1px solid var(--border); text-transform:uppercase; }
        .cascada-tabla td { padding:10px 12px; color:var(--silver); border-bottom:1px solid rgba(255,255,255,0.04); vertical-align:middle; }
        .cascada-tabla tr:hover td { background:rgba(255,255,255,0.02); }
        .denom-input { background:rgba(0,0,0,0.3); border:1px solid var(--border); color:var(--silver); padding:6px 10px; border-radius:3px; width:120px; font-family:var(--font-title); font-size:0.9rem; text-align:right; }
        .denom-input:focus { border-color:var(--blue-bright); outline:none; }
        .frac-display { font-family:var(--font-title); color:var(--gold); font-size:0.95rem; min-width:80px; display:inline-block; }
        .preset-btn { padding:5px 12px; background:rgba(60,120,255,0.08); border:1px solid var(--border); color:var(--silver-dim); cursor:pointer; font-size:0.7rem; font-family:var(--font-title); letter-spacing:1px; border-radius:3px; transition:all 0.2s; }
        .preset-btn:hover { background:rgba(60,120,255,0.2); color:var(--blue-bright); border-color:var(--blue-bright); }
        .info-box { background:rgba(60,120,255,0.05); border:1px solid rgba(60,120,255,0.2); border-radius:4px; padding:14px 18px; margin-bottom:24px; font-size:0.82rem; color:var(--silver-dim); line-height:1.6; }
        .info-box strong { color:var(--blue-bright); }
        .pct-cero { color:rgba(255,255,255,0.2); }
    </style>
</head>
<body>

<!-- HAMBURGER mobile -->
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>

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
            <a href="runas.php"    class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="rarezas.php"  class="admin-nav-btn"><span class="nav-icon">◇</span> Rarezas</a>
            <a href="cascada.php"  class="admin-nav-btn active"><span class="nav-icon">〜</span> Cascada</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="boosts.php"   class="admin-nav-btn"><span class="nav-icon">✦</span> Boosts</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Cascada de Probabilidad</div>
        <div class="admin-page-sub">Sistema de tirada en cascada — edita los denominadores de cada rareza</div>
        <div class="admin-separador"></div>

        <?php if (isset($_GET["ok"])): ?>
            <p class="admin-msg-ok">Denominadores guardados correctamente.</p>
        <?php endif; ?>

        <div class="info-box">
            <strong>Como funciona:</strong> en cada tirada, el server rolea de la rareza mas rara (mayor denominador) a la menos rara. Para cada una hace una tirada de <code>1/denominador</code>. La primera que acierta gana. Si nada acierta, cae la rareza con denominador 1 (la "fallback", normalmente comun).<br>
            <strong>Probabilidad directa:</strong> la fraccion que pone (ej. 1/100k para eterna). <strong>Probabilidad real:</strong> la prob despues de aplicar la cascada (es ligeramente menor, porque otras rarezas mas raras ya han "robado" su tirada).
        </div>

        <!-- Tabla principal -->
        <form method="POST">
            <div class="admin-card" style="margin-bottom:24px;">
                <div class="admin-card-titulo">Denominadores actuales</div>
                <div style="overflow-x:auto;">
                    <table class="cascada-tabla">
                        <thead>
                            <tr>
                                <th>Rareza</th>
                                <th>Slug</th>
                                <th>Denominador</th>
                                <th>Fraccion</th>
                                <th>Prob. directa</th>
                                <th>Prob. real (cascada)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rarezas as $r):
                                $denom = (int)$r["denominador"];
                                $direct = $prob_direct_pct[$r["slug"]] ?? 0;
                                $real   = $prob_real_pct[$r["slug"]]   ?? 0;
                            ?>
                                <tr>
                                    <td style="font-family:var(--font-title);color:<?= htmlspecialchars($r['color']) ?>;letter-spacing:2px;">
                                        <?= htmlspecialchars($r["nombre"]) ?>
                                        <?php if (!$r["activa"]): ?>
                                            <span style="color:var(--silver-dim);font-size:0.7rem;margin-left:8px;">(inactiva)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:var(--silver-dim);font-size:0.78rem;"><?= htmlspecialchars($r["slug"]) ?></td>
                                    <td>
                                        <input type="number"
                                               name="denominador[<?= $r['id'] ?>]"
                                               class="denom-input"
                                               min="1" step="1"
                                               value="<?= $denom ?>"
                                               data-slug="<?= htmlspecialchars($r['slug']) ?>"
                                               oninput="actualizarPreview(this)">
                                    </td>
                                    <td>
                                        <span class="frac-display" id="frac-<?= htmlspecialchars($r['slug']) ?>">
                                            <?= fmtFraccion($denom) ?>
                                        </span>
                                    </td>
                                    <td style="color:<?= htmlspecialchars($r['color']) ?>;font-size:0.82rem;">
                                        <?php
                                            if ($direct >= 1)        echo number_format($direct, 2) . "%";
                                            elseif ($direct >= 0.01) echo number_format($direct, 4) . "%";
                                            else                     echo number_format($direct, 6) . "%";
                                        ?>
                                    </td>
                                    <td style="color:var(--silver);font-size:0.82rem;<?= !$r['activa'] ? 'opacity:0.3;' : '' ?>">
                                        <?php
                                            if ($real <= 0)         echo '<span class="pct-cero">—</span>';
                                            elseif ($real >= 1)     echo number_format($real, 2) . "%";
                                            elseif ($real >= 0.01)  echo number_format($real, 4) . "%";
                                            else                    echo number_format($real, 6) . "%";
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top:20px; display:flex; gap:12px; align-items:center;">
                    <button type="submit" class="admin-form-submit">Guardar cambios</button>
                    <span style="color:var(--silver-dim);font-size:0.78rem;">Recarga la pagina tras guardar para recalcular las probabilidades reales.</span>
                </div>
            </div>
        </form>

        <!-- Presets rapidos -->
        <div class="admin-card">
            <div class="admin-card-titulo">Presets rapidos</div>
            <p style="color:var(--silver-dim);font-size:0.82rem;margin-bottom:16px;">
                Aplica un set de denominadores tipico al instante. Solo modifica los inputs, no guarda hasta que pulses "Guardar cambios" arriba.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button type="button" class="preset-btn" onclick="aplicarPreset('estandar')">Estandar (1/100k a 1/1)</button>
                <button type="button" class="preset-btn" onclick="aplicarPreset('facil')">Facil (probs altas)</button>
                <button type="button" class="preset-btn" onclick="aplicarPreset('hardcore')">Hardcore (probs bajas)</button>
            </div>
        </div>

    </main>
</div>

<script>
// formato de fraccion (espejo del PHP)
function fmtFraccion(denom) {
    denom = parseInt(denom);
    if (!denom || denom <= 1) return "1/1";
    if (denom >= 1000000) return "1/" + Math.round(denom / 1000000) + "M";
    if (denom >= 1000)    return "1/" + Math.round(denom / 1000) + "k";
    return "1/" + denom;
}

// al cambiar el denominador, actualiza el display de fraccion al lado
function actualizarPreview(input) {
    const slug = input.dataset.slug;
    const span = document.getElementById("frac-" + slug);
    if (span) span.textContent = fmtFraccion(input.value);
}

// presets predefinidos. cambian solo los inputs, el usuario decide si guardar
const PRESETS = {
    estandar: {
        eterna:     100000,
        divina:      25000,
        mitica:       5000,
        legendaria:   1000,
        epica:         100,
        rara:           25,
        poco_comun:     10,
        comun:           1
    },
    facil: {
        eterna:      10000,
        divina:       5000,
        mitica:       1000,
        legendaria:    250,
        epica:          50,
        rara:           10,
        poco_comun:      5,
        comun:           1
    },
    hardcore: {
        eterna:    1000000,
        divina:     250000,
        mitica:      50000,
        legendaria:  10000,
        epica:        1000,
        rara:         100,
        poco_comun:    25,
        comun:          1
    }
};

function aplicarPreset(nombre) {
    const preset = PRESETS[nombre];
    if (!preset) return;
    document.querySelectorAll(".denom-input").forEach(input => {
        const slug = input.dataset.slug;
        if (preset.hasOwnProperty(slug)) {
            input.value = preset[slug];
            actualizarPreview(input);
        }
    });
}

// nav mobile
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
</script>
</body>
</html>
