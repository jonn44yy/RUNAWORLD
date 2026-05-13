<?php
function renderAdminSidebar($active = 'tienda') {
    $items = [
        'index' => ['href'=>'index.php','icon'=>'⬡','label'=>'Dashboard'],
        'usuarios' => ['href'=>'usuarios.php','icon'=>'◈','label'=>'Usuarios'],
        'runas' => ['href'=>'runas.php','icon'=>'◎','label'=>'Runas'],
        'tienda' => ['href'=>'tienda.php','icon'=>'⟡','label'=>'Tienda'],
        'mensajes' => ['href'=>'mensajes.php','icon'=>'✉','label'=>'Mensajes'],
    ];
?>
<button id="admin-hamburger" onclick="toggleAdminNav()">&#9776;</button>
<div id="admin-nav-overlay" onclick="cerrarAdminNav()"></div>
<aside id="admin-sidebar">
    <div id="sidebar-logo">
        <svg id="sidebar-runa" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" color="#3c78ff">
            <circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.9"/>
            <circle cx="200" cy="200" r="145" fill="none" stroke="currentColor" stroke-width="0.7" opacity="0.7"/>
            <circle cx="200" cy="200" r="80" fill="none" stroke="currentColor" stroke-width="1" opacity="0.8"/>
            <g stroke="currentColor" stroke-width="2" opacity="1" stroke-linecap="round">
                <line x1="200" y1="125" x2="200" y2="95"/><line x1="193" y1="110" x2="200" y2="95"/><line x1="207" y1="110" x2="200" y2="95"/>
                <line x1="200" y1="275" x2="200" y2="305"/><line x1="193" y1="290" x2="207" y2="290"/>
                <line x1="275" y1="200" x2="305" y2="200"/><line x1="290" y1="193" x2="305" y2="200"/><line x1="290" y1="207" x2="305" y2="200"/>
                <line x1="125" y1="200" x2="95" y2="200"/><line x1="110" y1="193" x2="95" y2="200"/><line x1="110" y1="207" x2="95" y2="200"/>
            </g>
            <g stroke="currentColor" stroke-width="1.5" opacity="0.9"><line x1="200" y1="140" x2="200" y2="260"/><line x1="140" y1="200" x2="260" y2="200"/><circle cx="200" cy="200" r="25" fill="none"/><circle cx="200" cy="200" r="5" fill="currentColor"/></g>
        </svg>
        <div id="sidebar-logo-titulo">RunaWorld</div>
    </div>
    <nav class="admin-nav">
        <?php foreach ($items as $key => $item): ?>
            <a href="<?= $item['href'] ?>" class="admin-nav-btn <?= $active === $key ? 'active' : '' ?>"><span class="nav-icon"><?= $item['icon'] ?></span> <?= $item['label'] ?></a>
        <?php endforeach; ?>
        <div class="admin-nav-divider"></div>
        <a href="../PHP/logout.php" class="admin-nav-btn danger"><span class="nav-icon">→</span> Cerrar Sesion</a>
    </nav>
</aside>
<?php } ?>
