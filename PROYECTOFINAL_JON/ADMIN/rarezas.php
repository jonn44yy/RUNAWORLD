<?php
session_start();
if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php"); exit;
}
require_once "../PHP/conexion.php";

// ── ACCIONES POST ──────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";

    if ($accion === "crear" || $accion === "editar") {
        $nombre     = trim(strip_tags($_POST["nombre"]));
        $slug       = trim(preg_replace('/[^a-z0-9_]/', '_', strtolower($_POST["slug"] ?? $nombre)));
        $color      = trim($_POST["color"] ?? "#ffffff");
        $orden      = (int)$_POST["orden"];
        $es_especial = (int)($_POST["es_especial"] ?? 0);
        $activa     = (int)($_POST["activa"] ?? 1);

        // Curva campana
        $peso_base   = floatval($_POST["peso_base"]);
        $suerte_pico = floatval($_POST["suerte_pico"]);
        $peso_pico   = floatval($_POST["peso_pico"]);
        $suerte_cero = floatval($_POST["suerte_cero"]);

        if ($accion === "crear") {
            $stmt = $conexion->prepare("INSERT INTO rarezas (nombre,slug,color,orden,es_especial,activa) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("sssiii", $nombre, $slug, $color, $orden, $es_especial, $activa);
            $stmt->execute(); $stmt->close();
        } else {
            $id = (int)$_POST["id"];
            $stmt = $conexion->prepare("UPDATE rarezas SET nombre=?,slug=?,color=?,orden=?,es_especial=?,activa=? WHERE id=?");
            $stmt->bind_param("sssiii i", $nombre, $slug, $color, $orden, $es_especial, $activa, $id);
            $stmt->execute(); $stmt->close();
        }

        // Guardar/actualizar curva campana
        $stmt = $conexion->prepare("
            INSERT INTO rareza_curva (rareza, peso_base, suerte_pico, peso_pico, suerte_cero)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                peso_base=VALUES(peso_base), suerte_pico=VALUES(suerte_pico),
                peso_pico=VALUES(peso_pico), suerte_cero=VALUES(suerte_cero)
        ");
        $stmt->bind_param("sdddd", $slug, $peso_base, $suerte_pico, $peso_pico, $suerte_cero);
        $stmt->execute(); $stmt->close();

        header("Location: rarezas.php?ok=1"); exit;
    }

    if ($accion === "eliminar") {
        $id   = (int)$_POST["id"];
        $slug = trim($_POST["slug"]);
        // No eliminar si hay runas que la usan
        $stmt = $conexion->prepare("SELECT COUNT(*) as n FROM runas WHERE rareza = ?");
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $n = $stmt->get_result()->fetch_assoc()["n"];
        $stmt->close();
        if ($n > 0) {
            header("Location: rarezas.php?error=en_uso"); exit;
        }
        $stmt = $conexion->prepare("DELETE FROM rarezas WHERE id=?");
        $stmt->bind_param("i", $id); $stmt->execute(); $stmt->close();
        $stmt = $conexion->prepare("DELETE FROM rareza_curva WHERE rareza=?");
        $stmt->bind_param("s", $slug); $stmt->execute(); $stmt->close();
        header("Location: rarezas.php?ok=eliminar"); exit;
    }
}

// ── CARGAR DATOS ───────────────────────────────────────────────
$stmt = $conexion->prepare("SELECT * FROM rarezas ORDER BY orden ASC");
$stmt->execute();
$rarezas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conexion->prepare("SELECT * FROM rareza_curva");
$stmt->execute();
$curvas_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$curvas = [];
foreach ($curvas_raw as $cv) $curvas[$cv["rareza"]] = $cv;

$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Rarezas</title>
    <link rel="stylesheet" href="../CSS/admin.css">
</head>
<body>
<!-- HAMBURGER -->
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>

<div id="admin-layout" class="visible">
    <aside id="admin-sidebar">
        <div id="sidebar-logo">
            <svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
                <circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/>
                <circle cx="200" cy="200" r="145" fill="none" stroke="currentColor" stroke-width="0.7" opacity="0.7"/>
                <circle cx="200" cy="200" r="80"  fill="none" stroke="currentColor" stroke-width="1" opacity="0.8"/>
                <g stroke="currentColor" stroke-width="2" stroke-linecap="round">
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
                    <circle cx="200" cy="200" r="25" fill="none"/>
                    <circle cx="200" cy="200" r="5" fill="currentColor"/>
                </g>
            </svg>
            <div id="sidebar-logo-titulo">RunaWorld</div>
        </div>
        <nav class="admin-nav">
            <a href="index.php"    class="admin-nav-btn"><span class="nav-icon">⬡</span> Dashboard</a>
            <a href="usuarios.php" class="admin-nav-btn"><span class="nav-icon">◈</span> Usuarios</a>
            <a href="runas.php"    class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="rarezas.php"  class="admin-nav-btn active"><span class="nav-icon">✦</span> Rarezas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <a href="curvas.php"   class="admin-nav-btn"><span class="nav-icon">〜</span> Curvas</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Rarezas</div>
        <div class="admin-page-sub">Gestiona las rarezas del juego — colores, orden y curva de probabilidad</div>
        <div class="admin-separador"></div>

        <?php if (isset($_GET["ok"])): ?>
            <p class="admin-msg-ok">Guardado correctamente.</p>
        <?php endif; ?>
        <?php if (isset($_GET["error"]) && $_GET["error"]==="en_uso"): ?>
            <p class="admin-msg-error">No se puede eliminar: hay runas que usan esa rareza.</p>
        <?php endif; ?>

        <!-- TABLA DE RAREZAS -->
        <div class="admin-card" style="margin-bottom:28px; overflow-x:auto;">
            <div class="admin-card-titulo">Rarezas actuales</div>
            <table class="admin-tabla">
                <thead><tr>
                    <th>Nombre</th><th>Slug</th><th>Color</th><th>Orden</th>
                    <th>Especial</th><th>Activa</th><th>Peso base</th><th>Suerte pico</th><th>Acciones</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rarezas as $r):
                    $cv = $curvas[$r["slug"]] ?? null;
                ?>
                    <tr>
                        <td style="font-family:var(--font-title);color:<?= htmlspecialchars($r['color']) ?>;letter-spacing:2px;">
                            <?= htmlspecialchars($r["nombre"]) ?>
                        </td>
                        <td style="color:var(--silver-dim);font-size:0.8rem;"><?= htmlspecialchars($r["slug"]) ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:18px;height:18px;border-radius:3px;background:<?= htmlspecialchars($r['color']) ?>;border:1px solid rgba(255,255,255,0.2);"></div>
                                <span style="color:var(--silver-dim);font-size:0.75rem;"><?= htmlspecialchars($r["color"]) ?></span>
                            </div>
                        </td>
                        <td style="color:var(--silver-dim);"><?= $r["orden"] ?></td>
                        <td><span class="badge <?= $r['es_especial'] ? 'badge-si' : 'badge-no' ?>"><?= $r['es_especial'] ? 'Si' : 'No' ?></span></td>
                        <td><span class="badge <?= $r['activa'] ? 'badge-si' : 'badge-no' ?>"><?= $r['activa'] ? 'Si' : 'No' ?></span></td>
                        <td style="color:var(--silver-dim);"><?= $cv ? $cv["peso_base"] : '—' ?></td>
                        <td style="color:var(--silver-dim);"><?= $cv ? $cv["suerte_pico"] : '—' ?></td>
                        <td>
                            <div style="display:flex;gap:6px;">
                                <button class="btn-admin btn-admin-primary"
                                    onclick="abrirEditar(<?= htmlspecialchars(json_encode([
                                        'id'=>$r['id'],'nombre'=>$r['nombre'],'slug'=>$r['slug'],
                                        'color'=>$r['color'],'orden'=>$r['orden'],
                                        'es_especial'=>$r['es_especial'],'activa'=>$r['activa'],
                                        'peso_base'  => $cv['peso_base']   ?? 1000,
                                        'suerte_pico'=> $cv['suerte_pico'] ?? 1,
                                        'peso_pico'  => $cv['peso_pico']   ?? 1000,
                                        'suerte_cero'=> $cv['suerte_cero'] ?? 10,
                                    ])) ?>)">Editar</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminar rareza <?= htmlspecialchars($r['nombre']) ?>?')">
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id"    value="<?= $r['id'] ?>">
                                    <input type="hidden" name="slug"  value="<?= htmlspecialchars($r['slug']) ?>">
                                    <button type="submit" class="btn-admin btn-admin-danger">Eliminar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- FORMULARIO CREAR / EDITAR -->
        <div class="admin-card" id="form-rareza">
            <div class="admin-card-titulo" id="form-titulo">Crear nueva rareza</div>
            <form method="POST" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:700px;">
                <input type="hidden" name="accion" id="f-accion" value="crear">
                <input type="hidden" name="id"     id="f-id"     value="">

                <div class="admin-form-grupo">
                    <label class="admin-form-label">Nombre (visible)</label>
                    <input type="text" name="nombre" id="f-nombre" class="admin-form-input" required placeholder="Ej: Divina" oninput="autoSlug()">
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Slug (clave interna)</label>
                    <input type="text" name="slug" id="f-slug" class="admin-form-input" required placeholder="Ej: divina">
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Color (hex)</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="color" name="color" id="f-color-picker" value="#ffffff"
                               oninput="document.getElementById('f-color').value=this.value"
                               style="width:40px;height:36px;cursor:pointer;border:none;background:none;">
                        <input type="text" name="color" id="f-color" class="admin-form-input" value="#ffffff"
                               oninput="document.getElementById('f-color-picker').value=this.value">
                    </div>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Orden (mayor = más rara)</label>
                    <input type="number" name="orden" id="f-orden" class="admin-form-input" min="1" value="8">
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Animación especial al obtener</label>
                    <select name="es_especial" id="f-especial" class="admin-form-select">
                        <option value="0">No</option>
                        <option value="1">Sí</option>
                    </select>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Activa</label>
                    <select name="activa" id="f-activa" class="admin-form-select">
                        <option value="1">Sí</option>
                        <option value="0">No</option>
                    </select>
                </div>

                <!-- Curva campana -->
                <div style="grid-column:1/-1;">
                    <div style="font-family:var(--font-title);font-size:0.7rem;letter-spacing:3px;color:var(--silver-dim);margin-bottom:12px;border-top:1px solid var(--border);padding-top:14px;">
                        CURVA DE PROBABILIDAD (CAMPANA)
                    </div>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Peso base (con suerte x1)</label>
                    <input type="number" name="peso_base" id="f-peso-base" class="admin-form-input" step="0.01" min="0" value="1">
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Suerte en el pico</label>
                    <input type="number" name="suerte_pico" id="f-suerte-pico" class="admin-form-input" step="0.1" min="1" value="200">
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Peso máximo (en el pico)</label>
                    <input type="number" name="peso_pico" id="f-peso-pico" class="admin-form-input" step="1" min="0" value="500">
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Suerte donde llega a 0</label>
                    <input type="number" name="suerte_cero" id="f-suerte-cero" class="admin-form-input" step="1" min="1" value="2000">
                </div>

                <div style="grid-column:1/-1;display:flex;gap:10px;">
                    <button type="submit" class="admin-form-submit" id="f-submit">Crear rareza</button>
                    <button type="button" class="btn-admin btn-admin-primary" onclick="resetForm()" id="f-cancelar" style="display:none;">Cancelar</button>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
function autoSlug() {
    const n = document.getElementById('f-nombre').value;
    if (document.getElementById('f-accion').value === 'crear') {
        document.getElementById('f-slug').value = n.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g,'')
            .replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,'');
    }
}
function abrirEditar(r) {
    document.getElementById('form-titulo').textContent = 'Editar rareza';
    document.getElementById('f-accion').value    = 'editar';
    document.getElementById('f-id').value        = r.id;
    document.getElementById('f-nombre').value    = r.nombre;
    document.getElementById('f-slug').value      = r.slug;
    document.getElementById('f-color').value     = r.color;
    document.getElementById('f-color-picker').value = r.color;
    document.getElementById('f-orden').value     = r.orden;
    document.getElementById('f-especial').value  = r.es_especial;
    document.getElementById('f-activa').value    = r.activa;
    document.getElementById('f-peso-base').value  = r.peso_base;
    document.getElementById('f-suerte-pico').value = r.suerte_pico;
    document.getElementById('f-peso-pico').value   = r.peso_pico;
    document.getElementById('f-suerte-cero').value = r.suerte_cero;
    document.getElementById('f-submit').textContent = 'Guardar cambios';
    document.getElementById('f-cancelar').style.display = '';
    document.getElementById('form-rareza').scrollIntoView({behavior:'smooth'});
}
function resetForm() {
    document.getElementById('form-titulo').textContent = 'Crear nueva rareza';
    document.getElementById('f-accion').value = 'crear';
    document.getElementById('f-id').value = '';
    document.getElementById('f-nombre').value = '';
    document.getElementById('f-slug').value = '';
    document.getElementById('f-color').value = '#ffffff';
    document.getElementById('f-color-picker').value = '#ffffff';
    document.getElementById('f-orden').value = '8';
    document.getElementById('f-especial').value = '0';
    document.getElementById('f-activa').value = '1';
    document.getElementById('f-submit').textContent = 'Crear rareza';
    document.getElementById('f-cancelar').style.display = 'none';
}
function toggleAdminNav() {
    var s=document.getElementById("admin-sidebar"),o=document.getElementById("admin-nav-overlay"),b=document.getElementById("admin-hamburger"),open=s.classList.toggle("open");
    o.classList.toggle("visible",open); b.innerHTML=open?"&#10005;":"&#9776;";
}
function cerrarAdminNav() {
    document.getElementById("admin-sidebar").classList.remove("open");
    document.getElementById("admin-nav-overlay").classList.remove("visible");
    document.getElementById("admin-hamburger").innerHTML="&#9776;";
}
</script>
</body>
</html>
