<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

// Marcar como leido
if (isset($_GET["marcar_leido"])) {
    $mid = (int)$_GET["marcar_leido"];
    $stmt = $conexion->prepare("UPDATE mensajes SET leido = 1 WHERE id = ?");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $stmt->close();
    header("Location: mensajes.php" . (isset($_GET["filtro"]) ? "?filtro=".$_GET["filtro"] : ""));
    exit;
}

// Eliminar mensaje
if (isset($_GET["eliminar"])) {
    $mid = (int)$_GET["eliminar"];
    $stmt = $conexion->prepare("SELECT archivo FROM mensajes WHERE id = ?");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $msg = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($msg && $msg["archivo"]) {
        $ruta = "../" . $msg["archivo"];
        if (file_exists($ruta)) unlink($ruta);
    }
    $stmt = $conexion->prepare("DELETE FROM mensajes WHERE id = ?");
    $stmt->bind_param("i", $mid);
    $stmt->execute();
    $stmt->close();
    header("Location: mensajes.php");
    exit;
}

// Filtro
$filtro = $_GET["filtro"] ?? "todos";

if ($filtro === "nuevos") {
    $stmt = $conexion->prepare("
        SELECT m.*, u.username FROM mensajes m
        INNER JOIN usuarios u ON m.usuario_id = u.id
        WHERE m.leido = 0 ORDER BY m.fecha DESC
    ");
} elseif ($filtro === "leidos") {
    $stmt = $conexion->prepare("
        SELECT m.*, u.username FROM mensajes m
        INNER JOIN usuarios u ON m.usuario_id = u.id
        WHERE m.leido = 1 ORDER BY m.fecha DESC
    ");
} elseif ($filtro === "solicitud_admin") {
    $stmt = $conexion->prepare("
        SELECT m.*, u.username FROM mensajes m
        INNER JOIN usuarios u ON m.usuario_id = u.id
        WHERE m.asunto LIKE 'Solicitud para ser admin%'
        ORDER BY m.leido ASC, m.fecha DESC
    ");
} elseif (in_array($filtro, ["ideas","errores","error_datos","no_importante"])) {
    $stmt = $conexion->prepare("
        SELECT m.*, u.username FROM mensajes m
        INNER JOIN usuarios u ON m.usuario_id = u.id
        WHERE m.tipo = ? ORDER BY m.leido ASC, m.fecha DESC
    ");
    $stmt->bind_param("s", $filtro); 
}else {
    $stmt = $conexion->prepare("
        SELECT m.*, u.username FROM mensajes m
        INNER JOIN usuarios u ON m.usuario_id = u.id
        ORDER BY m.leido ASC, m.fecha DESC
    ");
}

$stmt->execute();
$mensajes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conexion->close();

function rw_es_solicitud_admin($mensaje) {
    return isset($mensaje["asunto"]) && stripos($mensaje["asunto"], "Solicitud para ser admin") === 0;
}

function rw_extraer_mensaje_admin($contenido) {
    $contenido = (string)$contenido;

    if (preg_match('/Mensaje:\s*(.*)$/su', $contenido, $m)) {
        return trim($m[1]);
    }

    return trim($contenido);
}

$solicitudes_admin = [];
$mensajes_generales = [];

foreach ($mensajes as $mensaje_item) {
    if (rw_es_solicitud_admin($mensaje_item)) {
        $solicitudes_admin[] = $mensaje_item;
    } else {
        $mensajes_generales[] = $mensaje_item;
    }
}

if ($filtro === "solicitud_admin") {
    $mensajes_lista = [];
} elseif ($filtro === "todos") {
    $mensajes_lista = $mensajes_generales;
} else {
    $mensajes_lista = $mensajes;
}

$etiquetas_tipo = [
    "ideas"         => "Idea",
    "errores"       => "Error",
    "error_datos"   => "Error datos",
    "no_importante" => "Info",
    "solicitud_admin" => "Solicitud admin"
];

$colores_tipo = [
    "ideas"         => "#6a9fff",
    "errores"       => "#ff7788",
    "error_datos"   => "#ffaa00",
    "no_importante" => "#8a96aa",
    "solicitud_admin" => "#ffd700"
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Mensajes</title>
    <link rel="stylesheet" href="../CSS/admin.css">
    <style>
        .filtros-tabs {
            display: flex;
            gap: 6px;
            margin-bottom: 24px;
        }

        .filtro-btn {
            padding: 9px 20px;
            font-family: var(--font-title);
            font-size: 0.75rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--silver-dim);
            border-radius: 3px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .filtro-btn:hover { border-color: var(--blue); color: var(--blue-bright); }
        .filtro-btn.active { border-color: var(--blue); color: var(--blue-bright); background: rgba(60,120,255,0.1); }

        .ticket-lista { display: flex; flex-direction: column; gap: 10px; }

        .ticket {
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: hidden;
            transition: border-color 0.2s;
        }

        .ticket-nuevo { border-left: 3px solid var(--blue-bright); }
        .ticket-leido { border-left: 3px solid rgba(100,110,140,0.4); opacity: 0.8; }

        .ticket-cabecera {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            cursor: pointer;
            background: rgba(60,120,255,0.03);
            transition: background 0.2s;
            flex-wrap: wrap;
        }

        .ticket-cabecera:hover { background: rgba(60,120,255,0.07); }

        .ticket-id {
            font-family: var(--font-title);
            font-size: 0.75rem;
            color: var(--blue-bright);
            min-width: 30px;
        }

        .ticket-tipo-badge {
            padding: 3px 10px;
            border-radius: 2px;
            font-family: var(--font-title);
            font-size: 0.6rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: 1px solid currentColor;
            white-space: nowrap;
        }

        .ticket-asunto {
            flex: 1;
            font-size: 0.95rem;
            color: var(--silver);
            min-width: 150px;
        }

        .ticket-meta {
            display: flex;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
        }

        .ticket-usuario {
            font-family: var(--font-title);
            font-size: 0.72rem;
            color: var(--blue);
            letter-spacing: 1px;
        }

        .ticket-fecha {
            font-size: 0.75rem;
            color: var(--silver-dim);
            white-space: nowrap;
        }

        .ticket-nuevo-badge {
            font-family: var(--font-title);
            font-size: 0.6rem;
            letter-spacing: 2px;
            padding: 2px 8px;
            background: rgba(60,120,255,0.15);
            border: 1px solid var(--blue);
            color: var(--blue-bright);
            border-radius: 2px;
        }

        .ticket-flecha {
            color: var(--silver-dim);
            font-size: 0.8rem;
            transition: transform 0.2s;
            margin-left: auto;
        }

        .ticket-contenido {
            padding: 20px 22px;
            border-top: 1px solid var(--border);
            background: rgba(0,0,0,0.2);
            display: none;
        }

        .ticket-contenido.abierto { display: block; }

        .ticket-mensaje {
            font-size: 1rem;
            line-height: 1.8;
            color: var(--silver);
            margin-bottom: 16px;
        }

        .ticket-imagen {
            max-width: 400px;
            width: 100%;
            border-radius: 4px;
            border: 1px solid var(--border);
            margin-bottom: 16px;
        }

        .ticket-acciones {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .admin-request-section {
            margin-bottom: 28px;
            padding: 18px;
            border: 1px solid rgba(255,215,0,0.22);
            border-left: 3px solid #ffd700;
            border-radius: 6px;
            background: rgba(255,215,0,0.035);
        }

        .admin-request-section-title {
            font-family: var(--font-title);
            font-size: 0.78rem;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #ffd700;
            margin-bottom: 14px;
        }

        .admin-request-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .admin-request-card {
            border: 1px solid rgba(255,215,0,0.20);
            background: rgba(0,0,0,0.22);
            border-radius: 5px;
            overflow: hidden;
        }

        .admin-request-head {
            display: flex;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(255,215,0,0.12);
        }

        .admin-request-title {
            flex: 1;
            min-width: 220px;
            font-family: var(--font-title);
            font-size: 0.8rem;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #ffd700;
        }

        .admin-request-meta {
            font-size: 0.75rem;
            color: var(--silver-dim);
        }

        .admin-request-body {
            padding: 16px;
        }

        .admin-request-body strong {
            color: #ffd700;
            font-family: var(--font-title);
            letter-spacing: 1px;
            text-transform: uppercase;
            font-size: 0.72rem;
        }

        .admin-request-message {
            margin-top: 8px;
            color: var(--silver);
            line-height: 1.75;
        }

        .admin-request-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--silver-dim);
        }

        .empty-state p {
            font-size: 1rem;
            margin-top: 8px;
        }
    </style>
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
                <g font-size="13" fill="currentColor" opacity="0.9" font-family="serif" text-anchor="middle">
                    <text transform="rotate(0,200,200)   translate(200,22)">&#5792;</text>
                    <text transform="rotate(45,200,200)  translate(200,22)">&#5794;</text>
                    <text transform="rotate(90,200,200)  translate(200,22)">&#5798;</text>
                    <text transform="rotate(135,200,200) translate(200,22)">&#5800;</text>
                    <text transform="rotate(180,200,200) translate(200,22)">&#5809;</text>
                    <text transform="rotate(225,200,200) translate(200,22)">&#5810;</text>
                    <text transform="rotate(270,200,200) translate(200,22)">&#5815;</text>
                    <text transform="rotate(315,200,200) translate(200,22)">&#5817;</text>
                </g>
            </svg>
            <div id="sidebar-logo-titulo">RunaWorld</div>
        </div>
        <nav class="admin-nav">
            <a href="index.php"    class="admin-nav-btn"><span class="nav-icon">⬡</span> Dashboard</a>
            <a href="usuarios.php" class="admin-nav-btn"><span class="nav-icon">◈</span> Usuarios</a>
            <a href="runas.php"    class="admin-nav-btn"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn active"><span class="nav-icon">✉</span> Mensajes</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Mensajes</div>
        <div class="admin-page-sub">
            <?= count($mensajes) ?> mensaje<?= count($mensajes) !== 1 ? 's' : '' ?>
            <?= $filtro !== 'todos' ? '— filtro: ' . $filtro : '' ?>
        </div>
        <div class="admin-separador"></div>

        <!-- FILTROS -->
        <div class="filtros-tabs">
            <a href="mensajes.php?filtro=todos"         class="filtro-btn <?= $filtro==='todos'         ? 'active':'' ?>">Todos</a>
            <a href="mensajes.php?filtro=nuevos"        class="filtro-btn <?= $filtro==='nuevos'        ? 'active':'' ?>">Sin leer</a>
            <a href="mensajes.php?filtro=leidos"        class="filtro-btn <?= $filtro==='leidos'        ? 'active':'' ?>">Leidos</a>
            <a href="mensajes.php?filtro=ideas"         class="filtro-btn <?= $filtro==='ideas'         ? 'active':'' ?>" style="color:#6a9fff; border-color:<?= $filtro==='ideas' ? '#6a9fff' : 'var(--border)' ?>">Ideas</a>
            <a href="mensajes.php?filtro=errores"       class="filtro-btn <?= $filtro==='errores'       ? 'active':'' ?>" style="color:#ff7788; border-color:<?= $filtro==='errores' ? '#ff7788' : 'var(--border)' ?>">Errores</a>
            <a href="mensajes.php?filtro=error_datos"   class="filtro-btn <?= $filtro==='error_datos'   ? 'active':'' ?>" style="color:#ffaa00; border-color:<?= $filtro==='error_datos' ? '#ffaa00' : 'var(--border)' ?>">Error datos</a>
            <a href="mensajes.php?filtro=no_importante" class="filtro-btn <?= $filtro==='no_importante' ? 'active':'' ?>" style="color:#8a96aa; border-color:<?= $filtro==='no_importante' ? '#8a96aa' : 'var(--border)' ?>">Info</a>
            <a href="mensajes.php?filtro=solicitud_admin" class="filtro-btn <?= $filtro==='solicitud_admin' ? 'active':'' ?>" style="color:#ffd700; border-color:<?= $filtro==='solicitud_admin' ? '#ffd700' : 'var(--border)' ?>">Admin</a>
        </div>

        <?php if (($filtro === "todos" || $filtro === "solicitud_admin") && !empty($solicitudes_admin)): ?>
            <section class="admin-request-section">
                <div class="admin-request-section-title">
                    Solicitudes para ser admin — <?= count($solicitudes_admin) ?>
                </div>

                <div class="admin-request-list">
                    <?php foreach ($solicitudes_admin as $s):
                        $mensaje_admin = rw_extraer_mensaje_admin($s["contenido"]);
                    ?>
                        <article class="admin-request-card <?= $s["leido"] ? "ticket-leido" : "ticket-nuevo" ?>">
                            <div class="admin-request-head">
                                <span class="ticket-id">#<?= $s["id"] ?></span>
                                <span class="admin-request-title">
                                    Solicitud para ser admin de: <?= htmlspecialchars($s["username"]) ?>
                                </span>
                                <span class="admin-request-meta">
                                    <?= date("d/m/Y H:i", strtotime($s["fecha"])) ?>
                                </span>
                                <?php if (!$s["leido"]): ?>
                                    <span class="ticket-nuevo-badge">Nuevo</span>
                                <?php endif; ?>
                            </div>

                            <div class="admin-request-body">
                                <strong>Mensaje:</strong>
                                <div class="admin-request-message">
                                    <?= nl2br(htmlspecialchars($mensaje_admin)) ?>
                                </div>

                                <div class="admin-request-actions">
                                    <?php if (!$s["leido"]): ?>
                                        <a href="mensajes.php?marcar_leido=<?= $s["id"] ?>&filtro=<?= $filtro ?>"
                                           class="btn-admin btn-admin-success">Marcar como leido</a>
                                    <?php endif; ?>

                                    <a href="mensajes.php?eliminar=<?= $s["id"] ?>"
                                       class="btn-admin btn-admin-danger"
                                       onclick="return confirm('Eliminar esta solicitud?')">Eliminar</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>



        <?php if (empty($mensajes_lista) && !($filtro === "todos" && !empty($solicitudes_admin)) && !($filtro === "solicitud_admin" && !empty($solicitudes_admin))): ?>
            <div class="empty-state">
                <div style="font-size:2.5rem; opacity:0.2;">✉</div>
                <p>No hay mensajes<?= $filtro !== 'todos' ? ' en este filtro' : '' ?>.</p>
            </div>
        <?php elseif (!empty($mensajes_lista)): ?>
            <div class="ticket-lista">
                <?php foreach ($mensajes_lista as $m):
                    $es_admin_request = rw_es_solicitud_admin($m);
                    $color = $es_admin_request ? "#ffd700" : ($colores_tipo[$m["tipo"]] ?? "var(--silver-dim)");
                    $tipo_label = $es_admin_request ? "Solicitud admin" : ($etiquetas_tipo[$m["tipo"]] ?? $m["tipo"]);
                ?>
                    <div class="ticket <?= $m["leido"] ? "ticket-leido" : "ticket-nuevo" ?>">

                        <div class="ticket-cabecera" onclick="toggleTicket(<?= $m["id"] ?>)">
                            <span class="ticket-id">#<?= $m["id"] ?></span>

                            <span class="ticket-tipo-badge" style="color:<?= $color ?>;">
                                <?= $tipo_label ?>
                            </span>

                            <span class="ticket-asunto"><?= htmlspecialchars($m["asunto"]) ?></span>

                            <div class="ticket-meta">
                                <span class="ticket-usuario"><?= htmlspecialchars($m["username"]) ?></span>
                                <span class="ticket-fecha"><?= date("d/m/Y H:i", strtotime($m["fecha"])) ?></span>
                                <?php if (!$m["leido"]): ?>
                                    <span class="ticket-nuevo-badge">Nuevo</span>
                                <?php endif; ?>
                            </div>

                            <span class="ticket-flecha" id="flecha-<?= $m["id"] ?>">▼</span>
                        </div>

                        <div class="ticket-contenido" id="ticket-<?= $m["id"] ?>">
                            <p class="ticket-mensaje"><?= nl2br(htmlspecialchars($m["contenido"])) ?></p>

                            <?php if ($m["archivo"]): ?>
                                <img src="../<?= htmlspecialchars($m["archivo"]) ?>"
                                     alt="Adjunto" class="ticket-imagen">
                            <?php endif; ?>

                            <div class="ticket-acciones">
                                <?php if (!$m["leido"]): ?>
                                    <a href="mensajes.php?marcar_leido=<?= $m["id"] ?>&filtro=<?= $filtro ?>"
                                       class="btn-admin btn-admin-success">Marcar como leido</a>
                                <?php endif; ?>
                                <a href="mensajes.php?eliminar=<?= $m["id"] ?>"
                                   class="btn-admin btn-admin-danger"
                                   onclick="return confirm('Eliminar este mensaje?')">Eliminar</a>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>
</div>

<script>
function toggleTicket(id) {
    const contenido = document.getElementById("ticket-" + id);
    const flecha    = document.getElementById("flecha-" + id);
    const abierto   = contenido.classList.toggle("abierto");
    flecha.textContent = abierto ? "▲" : "▼";
}
</script>

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
});
</script>
</body>
</html>
