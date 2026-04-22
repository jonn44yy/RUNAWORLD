<?php
session_start();

if (!isset($_SESSION["idUsuario"]) || $_SESSION["rol"] !== "admin") {
    header("Location: ../index.php");
    exit;
}

require_once "../PHP/conexion.php";

$errores = [];
if (isset($_SESSION["errores"])) {
    $errores = $_SESSION["errores"];
    unset($_SESSION["errores"]);
}

$stmt = $conexion->prepare("
    SELECT g.id, g.nombre, COUNT(r.id) as total_runas
    FROM grupos_runas g
    LEFT JOIN runas r ON g.id = r.grupo_id
    GROUP BY g.id ORDER BY g.id ASC
");
$stmt->execute();
$grupos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld — Gestionar Grupos</title>
    <link rel="stylesheet" href="../CSS/admin.css">
</head>
<body>
<div id="admin-layout" class="visible">

    <aside id="admin-sidebar">
        <div id="sidebar-logo">
            <svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
                <circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/>
                <circle cx="200" cy="200" r="80"  fill="none" stroke="currentColor" stroke-width="1" opacity="0.8"/>
                <g stroke="currentColor" stroke-width="2.5" opacity="1" stroke-linecap="round">
                    <line x1="200" y1="125" x2="200" y2="95"/><line x1="193" y1="110" x2="200" y2="95"/><line x1="207" y1="110" x2="200" y2="95"/>
                    <line x1="200" y1="275" x2="200" y2="305"/><line x1="193" y1="290" x2="207" y2="290"/>
                    <line x1="275" y1="200" x2="305" y2="200"/><line x1="290" y1="193" x2="305" y2="200"/><line x1="290" y1="207" x2="305" y2="200"/>
                    <line x1="125" y1="200" x2="95" y2="200"/><line x1="110" y1="193" x2="95" y2="200"/><line x1="110" y1="207" x2="95" y2="200"/>
                    <line x1="254" y1="146" x2="275" y2="125"/><line x1="146" y1="146" x2="125" y2="125"/>
                    <line x1="254" y1="254" x2="275" y2="275"/><line x1="146" y1="254" x2="125" y2="275"/>
                </g>
                <g stroke="currentColor" stroke-width="1.5" opacity="0.9">
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
            <a href="runas.php"    class="admin-nav-btn active"><span class="nav-icon">◎</span> Runas</a>
            <a href="tienda.php"   class="admin-nav-btn"><span class="nav-icon">⟡</span> Tienda</a>
            <a href="mensajes.php" class="admin-nav-btn"><span class="nav-icon">✉</span> Mensajes</a>
            <div class="admin-nav-divider"></div>
            <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
        </nav>
    </aside>

    <main id="admin-content">
        <div class="admin-page-titulo">Listas de Runas</div>
        <div class="admin-page-sub">Gestiona los grupos en los que se organizan las runas</div>
        <div class="admin-separador"></div>

        <a href="runas.php" class="btn-admin btn-admin-primary" style="margin-bottom:28px; display:inline-block;">← Volver a Runas</a>

        <?php foreach ($errores as $e): ?>
            <p class="admin-msg-error"><?= htmlspecialchars($e) ?></p>
        <?php endforeach; ?>

        <form method="POST" action="../PHP/grupos_action.php" class="admin-form" style="max-width:400px; margin-bottom:32px;">
            <input type="hidden" name="accion" value="crear">
            <div class="admin-form-grupo">
                <label class="admin-form-label">Nombre de la nueva lista</label>
                <input type="text" name="nombre" class="admin-form-input" placeholder="Ej: Avanzado" required>
            </div>
            <button type="submit" class="admin-form-submit">Crear lista</button>
        </form>

        <?php if (!empty($grupos)): ?>
            <div class="admin-tabla-wrapper">
                <table class="admin-tabla">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Runas</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grupos as $g): ?>
                            <tr>
                                <td style="color:var(--silver-dim);">#<?= $g["id"] ?></td>
                                <td style="font-family:var(--font-title); color:var(--blue-bright); letter-spacing:1px;">
                                    <?= htmlspecialchars($g["nombre"]) ?>
                                </td>
                                <td style="color:var(--silver-dim);"><?= $g["total_runas"] ?> runas</td>
                                <td>
                                    <a href="../PHP/grupos_action.php?accion=eliminar&id=<?= $g["id"] ?>"
                                       class="btn-admin btn-admin-danger"
                                       onclick="return confirm('Eliminar lista <?= htmlspecialchars($g["nombre"]) ?>? Las runas quedaran sin grupo.')">
                                       Eliminar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
