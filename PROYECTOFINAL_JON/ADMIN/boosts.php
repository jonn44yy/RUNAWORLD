<?php
session_start();
if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php"); exit;
}
require_once "../PHP/conexion.php";

// ── Valores permitidos (hardcoded para que admin no pueda romper nada) ──
$RAREZAS_VALIDAS = ['normal', 'raro', 'epico', 'legendario', 'divino'];
$TIPOS_VALIDOS   = ['coins_seg', 'points_seg'];

// Acciones POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST["accion"] ?? "";

    if ($accion === "guardar_config") {
        $intervalo = max(5, (int)$_POST["intervalo_seg"]);
        $stmt = $conexion->prepare("INSERT INTO config_boosts (clave,valor) VALUES ('intervalo_seg',?) ON DUPLICATE KEY UPDATE valor=?");
        $stmt->bind_param("ss", $intervalo, $intervalo);
        $stmt->execute(); $stmt->close();
        header("Location: boosts.php?ok=config"); exit;
    }

    if ($accion === "crear") {
        $nombre = trim($_POST["nombre"] ?? "");
        $tipo   = $_POST["tipo"] ?? "";
        $rareza = $_POST["rareza"] ?? "normal";
        $multi  = floatval($_POST["multiplicador"] ?? 0);
        $peso   = max(0, (int)($_POST["peso"] ?? 0));
        $dur    = max(1, (int)($_POST["duracion_seg"] ?? 60));
        $usos   = max(0, (int)($_POST["usos_tirada"] ?? 0));
        $req    = !empty($_POST["requiere_mejora_id"]) ? (int)$_POST["requiere_mejora_id"] : null;

        // Validaciones
        if ($nombre === "" || !in_array($tipo, $TIPOS_VALIDOS) || !in_array($rareza, $RAREZAS_VALIDAS) || $multi <= 0) {
            header("Location: boosts.php?error=datos"); exit;
        }

        $stmt = $conexion->prepare("INSERT INTO boost_tipos (nombre,tipo,rareza,multiplicador,peso,duracion_seg,usos_tirada,requiere_mejora_id) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssdiiii", $nombre, $tipo, $rareza, $multi, $peso, $dur, $usos, $req);
        $stmt->execute(); $stmt->close();
        header("Location: boosts.php?ok=crear"); exit;
    }

    if ($accion === "editar") {
        $id     = (int)$_POST["id"];
        $nombre = trim($_POST["nombre"] ?? "");
        $tipo   = $_POST["tipo"] ?? "";
        $rareza = $_POST["rareza"] ?? "normal";
        $multi  = floatval($_POST["multiplicador"] ?? 0);
        $peso   = max(0, (int)($_POST["peso"] ?? 0));
        $dur    = max(1, (int)($_POST["duracion_seg"] ?? 60));
        $usos   = max(0, (int)($_POST["usos_tirada"] ?? 0));
        $activo = (int)$_POST["activo"];
        $req    = !empty($_POST["requiere_mejora_id"]) ? (int)$_POST["requiere_mejora_id"] : null;

        // Validaciones
        if ($id <= 0 || $nombre === "" || !in_array($tipo, $TIPOS_VALIDOS) || !in_array($rareza, $RAREZAS_VALIDAS) || $multi <= 0) {
            header("Location: boosts.php?error=datos"); exit;
        }

        $stmt = $conexion->prepare("UPDATE boost_tipos SET nombre=?,tipo=?,rareza=?,multiplicador=?,peso=?,duracion_seg=?,usos_tirada=?,activo=?,requiere_mejora_id=? WHERE id=?");
        $stmt->bind_param("sssdiiiiii", $nombre, $tipo, $rareza, $multi, $peso, $dur, $usos, $activo, $req, $id);
        $stmt->execute(); $stmt->close();
        header("Location: boosts.php?ok=editar"); exit;
    }

    if ($accion === "eliminar") {
        $id = (int)$_POST["id"];
        $stmt = $conexion->prepare("DELETE FROM boost_tipos WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute(); $stmt->close();
        header("Location: boosts.php?ok=eliminar"); exit;
    }
}

// Cargar datos
$stmt = $conexion->prepare("SELECT * FROM boost_tipos ORDER BY tipo, peso DESC");
$stmt->execute();
$boosts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conexion->prepare("SELECT clave,valor FROM config_boosts");
$stmt->execute();
$cfg_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$cfg = [];
foreach ($cfg_raw as $c) $cfg[$c["clave"]] = $c["valor"];
$intervalo = $cfg["intervalo_seg"] ?? 30;

// Mejoras de desbloqueo (para el dropdown)
$stmt = $conexion->prepare("SELECT id, nombre FROM mejoras WHERE tipo LIKE 'desbloquear%' ORDER BY id");
$stmt->execute();
$mejoras_desbloq = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conexion->close();

// Calcular total de pesos POR TIPO (no global — así se ve el % real dentro de cada grupo)
$pesos_por_tipo = [];
foreach ($boosts as $b) {
    if (!$b["activo"]) continue;
    $pesos_por_tipo[$b["tipo"]] = ($pesos_por_tipo[$b["tipo"]] ?? 0) + $b["peso"];
}

$tipos_label = ["coins_seg" => "Coins/seg", "points_seg" => "Points/seg"];
$COLORES_RAREZA = [
    'normal'     => '#6a9fff',
    'raro'       => '#a050ff',
    'epico'      => '#ffaa00',
    'legendario' => '#ff3060',
    'divino'     => '#ffffff'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>RunaWorld — Admin Boosts</title>
    <link rel="stylesheet" href="../CSS/admin.css">
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
            <a href="runas.php"    class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <a href="boosts.php"   class="admin-nav-btn active"><span class="nav-icon">✦</span> Boosts</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Boosts Flotantes</div>
        <div class="admin-page-sub">Runas especiales que aparecen aleatoriamente en pantalla</div>
        <div class="admin-separador"></div>

        <?php if (isset($_GET["ok"])): ?>
            <p class="admin-msg-ok">Cambios guardados correctamente.</p>
        <?php endif; ?>
        <?php if (isset($_GET["error"])): ?>
            <p class="admin-msg-ok" style="color:#ff5566;border-color:#ff5566;">Error: datos inválidos. Revisa nombre, tipo, rareza y multiplicador.</p>
        <?php endif; ?>

        <!-- CONFIG GLOBAL -->
        <div class="admin-card" style="margin-bottom:28px;">
            <div class="admin-card-titulo">Configuración global</div>
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_config">
                <div class="admin-form-grupo" style="max-width:280px;">
                    <label class="admin-form-label">Intervalo entre apariciones (segundos)</label>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <input type="number" name="intervalo_seg" class="admin-form-input"
                               min="5" value="<?= $intervalo ?>" style="width:100px;">
                        <button type="submit" class="btn-admin btn-admin-primary">Guardar</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- TABLA BOOSTS -->
        <div class="admin-card" style="margin-bottom:28px;">
            <div class="admin-card-titulo">Tipos de boost activos</div>
            <?php if (empty($boosts)): ?>
                <p style="color:var(--silver-dim);">No hay boosts. Crea uno abajo.</p>
            <?php else: ?>
            <div class="admin-tabla-wrapper">
                <table class="admin-tabla">
                    <thead><tr>
                        <th>Nombre</th><th>Tipo</th><th>Rareza</th><th>Multi</th>
                        <th>Peso</th><th>% en tipo</th><th>Dur</th><th>Usos</th><th>Req</th><th>Activo</th><th>Acciones</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($boosts as $b):
                        $total_tipo = $pesos_por_tipo[$b["tipo"]] ?? 0;
                        $pct = ($total_tipo > 0 && $b["activo"]) ? number_format($b["peso"] / $total_tipo * 100, 1) : 0;
                        $col_rareza = $COLORES_RAREZA[$b["rareza"]] ?? '#6a9fff';
                    ?>
                        <tr id="fila-<?= $b["id"] ?>">
                            <td style="font-family:var(--font-title);letter-spacing:1px;color:<?= $col_rareza ?>;"><?= htmlspecialchars($b["nombre"]) ?></td>
                            <td><span class="rareza-badge" style="color:#a050ff;"><?= $tipos_label[$b["tipo"]] ?? $b["tipo"] ?></span></td>
                            <td><span class="rareza-badge" style="color:<?= $col_rareza ?>;border:1px solid <?= $col_rareza ?>;padding:2px 8px;"><?= $b["rareza"] ?></span></td>
                            <td style="color:var(--gold);">x<?= rtrim(rtrim(number_format($b["multiplicador"], 2), '0'), '.') ?></td>
                            <td style="color:var(--silver-dim);"><?= $b["peso"] ?></td>
                            <td style="color:var(--blue-bright);"><?= $b["activo"] ? $pct."%" : "—" ?></td>
                            <td style="color:var(--silver-dim);"><?= $b["duracion_seg"] ?>s</td>
                            <td style="color:var(--silver-dim);"><?= $b["usos_tirada"] > 0 ? $b["usos_tirada"] : "—" ?></td>
                            <td style="color:var(--silver-dim);"><?= $b["requiere_mejora_id"] ? "🔒" : "—" ?></td>
                            <td><span class="badge <?= $b["activo"] ? 'badge-si' : 'badge-no' ?>"><?= $b["activo"] ? "Si" : "No" ?></span></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn-admin btn-admin-primary"
                                            onclick="abrirEditar(<?= htmlspecialchars(json_encode($b)) ?>)">Editar</button>
                                    <form method="POST" style="display:inline;"
                                          onsubmit="return confirm('Eliminar <?= htmlspecialchars($b["nombre"]) ?>?')">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id" value="<?= $b["id"] ?>">
                                        <button type="submit" class="btn-admin btn-admin-danger">Eliminar</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- CREAR BOOST -->
        <div class="admin-card" id="form-crear">
            <div class="admin-card-titulo" id="form-titulo">Crear nuevo boost</div>
            <form method="POST" class="admin-form" style="max-width:500px;">
                <input type="hidden" name="accion" id="form-accion" value="crear">
                <input type="hidden" name="id"     id="form-id"     value="">

                <div class="admin-form-grupo">
                    <label class="admin-form-label">Nombre</label>
                    <input type="text" name="nombre" id="form-nombre" class="admin-form-input" required placeholder="Ej: x3 Coins">
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Tipo de boost *</label>
                    <select name="tipo" id="form-tipo" class="admin-form-select" required>
                        <option value="coins_seg">Coins/seg</option>
                        <option value="points_seg">Points/seg</option>
                    </select>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Rareza *</label>
                    <select name="rareza" id="form-rareza" class="admin-form-select" required>
                        <?php foreach ($RAREZAS_VALIDAS as $r): ?>
                            <option value="<?= $r ?>"><?= ucfirst($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--silver-dim);font-size:0.72rem;">Color del glow del boost flotante</small>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Multiplicador</label>
                    <input type="number" name="multiplicador" id="form-multi" class="admin-form-input"
                           step="0.1" min="0.1" value="2" required>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Peso (mayor = más probable)</label>
                    <input type="number" name="peso" id="form-peso" class="admin-form-input"
                           min="0" value="50" required>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Duración (segundos)</label>
                    <input type="number" name="duracion_seg" id="form-dur" class="admin-form-input"
                           min="1" value="60" required>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Usos por tirada</label>
                    <input type="number" name="usos_tirada" id="form-usos" class="admin-form-input"
                           min="0" value="0">
                    <small style="color:var(--silver-dim);font-size:0.72rem;">0 = normal (dura por tiempo); &gt;0 = dura N tiradas (ej: Suerte x1000 con 1 uso)</small>
                </div>
                <div class="admin-form-grupo">
                    <label class="admin-form-label">Requiere mejora desbloqueada</label>
                    <select name="requiere_mejora_id" id="form-req" class="admin-form-select">
                        <option value="">— Ninguna (siempre disponible)</option>
                        <?php foreach ($mejoras_desbloq as $m): ?>
                            <option value="<?= $m["id"] ?>"><?= htmlspecialchars($m["nombre"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--silver-dim);font-size:0.72rem;">Solo aparece en el juego si el jugador tiene esta mejora comprada</small>
                </div>
                <div class="admin-form-grupo" id="campo-activo" style="display:none;">
                    <label class="admin-form-label">Activo</label>
                    <select name="activo" id="form-activo" class="admin-form-select">
                        <option value="1">Si</option>
                        <option value="0">No</option>
                    </select>
                </div>

                <div style="display:flex;gap:10px;">
                    <button type="submit" class="admin-form-submit" id="form-submit-btn">Crear boost</button>
                    <button type="button" class="btn-admin btn-admin-primary"
                            onclick="resetForm()" id="btn-cancelar" style="display:none;">Cancelar</button>
                </div>
            </form>
        </div>

    </main>
</div>

<script>
function abrirEditar(b) {
    document.getElementById("form-titulo").textContent     = "Editar boost";
    document.getElementById("form-accion").value           = "editar";
    document.getElementById("form-id").value               = b.id;
    document.getElementById("form-nombre").value           = b.nombre;
    document.getElementById("form-tipo").value             = b.tipo;
    document.getElementById("form-rareza").value           = b.rareza || "normal";
    document.getElementById("form-multi").value            = b.multiplicador;
    document.getElementById("form-peso").value             = b.peso;
    document.getElementById("form-dur").value              = b.duracion_seg;
    document.getElementById("form-usos").value             = b.usos_tirada || 0;
    document.getElementById("form-req").value              = b.requiere_mejora_id || "";
    document.getElementById("form-activo").value           = b.activo;
    document.getElementById("campo-activo").style.display  = "";
    document.getElementById("form-submit-btn").textContent = "Guardar cambios";
    document.getElementById("btn-cancelar").style.display  = "";
    document.getElementById("form-crear").scrollIntoView({ behavior: "smooth" });
}
function resetForm() {
    document.getElementById("form-titulo").textContent     = "Crear nuevo boost";
    document.getElementById("form-accion").value           = "crear";
    document.getElementById("form-id").value               = "";
    document.getElementById("form-nombre").value           = "";
    document.getElementById("form-rareza").value           = "normal";
    document.getElementById("form-multi").value            = "2";
    document.getElementById("form-peso").value             = "50";
    document.getElementById("form-dur").value              = "60";
    document.getElementById("form-usos").value             = "0";
    document.getElementById("form-req").value              = "";
    document.getElementById("campo-activo").style.display  = "none";
    document.getElementById("form-submit-btn").textContent = "Crear boost";
    document.getElementById("btn-cancelar").style.display  = "none";
}
</script>
<script src="../JS/admin-mobile.js"></script>
</body>
</html>
