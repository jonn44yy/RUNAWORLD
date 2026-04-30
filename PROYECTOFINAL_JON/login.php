<?php
// index.php — runaworld
// la puerta de entrada. si vienes logueado te mando a juego.php, si no, te
// muestro el form de login y punto. el form POST va a PHP/login_action.php
// que es quien hace la magia con la bd (checkea usuario, verifica hash, etc)
//
// indice:
//   1. session_start y redirect si ya hay sesion activa
//   2. flash de error (leer y borrar $_SESSION["error"] si viene del action)
//   3. html del panel de login con estrella giratoria de fondo
//
// lenguaje interno para los poco entendidos:
//   flash de error  = login_action.php guarda el motivo del fallo en la sesion
//                     y redirige aqui. yo lo leo una vez y lo borro. truco
//                     clasico para que al refrescar no resalga el mismo error
//   star-bg         = la estrella morada girando en el fondo, css puro en
//                     auth.css. no hay js de por medio, solo animation:rotate
//
// hecho a primeros de marzo junto con login_action. no lo toco desde entonces
// porque funciona. !hi

session_start();

// si ya hay sesion, pa dentro directamente. no tiene sentido ensenarle el
// login a alguien que ya esta logueado
if (isset($_SESSION["idUsuario"])) {
    header("Location: juego.php");
    exit;
}

// flash de error: login_action.php guarda aqui el motivo si fallo el login
// ("usuario no existe", "contrasena incorrecta", etc). lo leo una vez y lo
// borro para que no persista entre refrescos
$error = "";
if (isset($_SESSION["error"])) {
    $error = $_SESSION["error"];
    unset($_SESSION["error"]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld - Iniciar Sesion</title>
    <link rel="stylesheet" href="CSS/auth.css">
</head>
<body>

    <!-- estrella de fondo girando + overlay difuminado encima. los dos juntos
         dan el look "fondo animado pero sin molestar al form" -->
    <div class="star-bg">
        <div class="star-shape"></div>
    </div>
    <div class="overlay"></div>

    <!-- panel de login centrado por encima del overlay -->
    <div class="auth-panel">

        <div class="auth-game-title">RunaWorld</div>
        <div class="auth-subtitle">Iniciar Sesion</div>
        <div class="auth-sep"></div>

        <?php if ($error !== ""): ?>
            <p class="auth-error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <!-- form POST hacia el action. los autocomplete="username" y
             "current-password" son los que le dicen al navegador "esto es
             un login" y le dejan ofrecer contrasenas guardadas. sin ellos
             Chrome no rellena nada y te pone mal cara -->
        <form method="POST" action="PHP/login_action.php">

            <div class="auth-group">
                <label class="auth-label">Usuario o correo</label>
                <input type="text" name="username" class="auth-input" autocomplete="username" placeholder="Tu usuario o correo" required>
            </div>

            <div class="auth-group">
                <label class="auth-label">Contrasena</label>
                <input type="password" name="password" class="auth-input" autocomplete="current-password" required>
            </div>

            <button type="submit" class="auth-btn">Entrar</button>

        </form>

        <p class="auth-link">
            No tienes cuenta? <a href="registro.php">Registrate aqui</a>
        </p>

    </div>

</body>
</html>