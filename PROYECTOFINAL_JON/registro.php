<?php
// registro.php — runaworld
// pantalla de crear cuenta. hermana gemela de index.php pero con mas campos
// y con un canvas animado que reacciona a la fuerza de la contrasena mientras
// escribes. el form POST va a PHP/registro_action.php que valida todo e
// inserta el usuario nuevo en la bd
//
// indice:
//   1. session_start y redirect si ya hay sesion activa
//   2. flash de errores por campo (array $errores, no un string como en login)
//   3. html del panel de registro con sus 6 campos
//   4. al final: load de animaciones.js + llamada a iniciarCirculoSeguridad()
//      que es la que pinta el canvas de fuerza de contrasena
//
// lenguaje interno para los poco entendidos:
//   $errores             = array asociativo campo => mensaje. ej:
//                          ["email" => "ya registrado", "password2" => "no coincide"].
//                          el action valida todo y si algo falla me redirige
//                          aqui con el array relleno. cada input pinta su propio
//                          error debajo si le toca
//   canvas de seguridad  = el circulo morado detras del form que cambia segun
//                          la fuerza de la contrasena. vive en animaciones.js,
//                          funcion iniciarCirculoSeguridad()
//   star-bg              = la misma estrella morada giratoria que en login,
//                          reutilizo el mismo css de auth.css
//
// hecho a mediados de marzo con registro_action. estable desde entonces. !hi

session_start();

// si ya hay sesion no dejo entrar al registro. no tiene mucho sentido que un
// usuario ya logueado se cree otra cuenta sin pasar por logout primero
if (isset($_SESSION["idUsuario"])) {
    header("Location: juego.php");
    exit;
}

// flash de errores por campo. el action valida y si algo falla me redirige
// aqui con $_SESSION["errores"] relleno. lo leo una vez y lo borro para que
// no persista entre refrescos (mismo patron que index.php pero con array)
$errores = [];
if (isset($_SESSION["errores"])) {
    $errores = $_SESSION["errores"];
    unset($_SESSION["errores"]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RunaWorld - Registro</title>
    <link rel="stylesheet" href="CSS/auth.css">
</head>
<body>

    <!-- canvas donde se pinta el circulo de fuerza de contrasena. va suelto
         en body, lo posiciona fixed desde css. el js del final lo engancha
         al input de password y va repintando segun escribes -->
    <canvas id="password-strength-canvas"></canvas>

    <!-- misma estrella + overlay que en index.php. copy/paste consciente
         porque las dos pantallas tienen que verse identicas de fondo -->
    <div class="star-bg">
        <div class="star-shape"></div>
    </div>
    <div class="overlay"></div>

    <!-- panel con max-width 440 (en login es mas estrecho). aqui hay el doble
         de campos y con menos ancho se quedaban muy apretados -->
    <div class="auth-panel" style="max-width:440px;">

        <div class="auth-game-title">RunaWorld</div>
        <div class="auth-subtitle">Crear Cuenta</div>
        <div class="auth-sep"></div>

        <!-- el form es una cadena de bloques auth-group. cada uno tiene su
             label, su input, y (si hay error) un span field-error debajo.
             todos los inputs son required asi que la validacion nativa de
             html ya pilla los vacios antes siquiera de llegar al action -->
        <form method="POST" action="PHP/registro_action.php">

            <div class="auth-group">
                <label class="auth-label">Nombre de usuario</label>
                <input type="text" name="username" class="auth-input" required>
                <?php if (!empty($errores["username"])): ?>
                    <span class="field-error"><?= htmlspecialchars($errores["username"]) ?></span>
                <?php endif; ?>
            </div>

            <div class="auth-group">
                <label class="auth-label">Correo electronico</label>
                <input type="email" name="email" class="auth-input" required>
                <?php if (!empty($errores["email"])): ?>
                    <span class="field-error"><?= htmlspecialchars($errores["email"]) ?></span>
                <?php endif; ?>
            </div>

            <!-- id="password-input" lo busca animaciones.js para engancharle
                 el listener. si le cambias el id aqui hay que cambiarlo alla
                 tambien o el circulo deja de reaccionar -->
            <div class="auth-group">
                <label class="auth-label">Contrasena</label>
                <input type="password" name="password" id="password-input" class="auth-input" required autocomplete="new-password">
                <?php if (!empty($errores["password"])): ?>
                    <span class="field-error"><?= htmlspecialchars($errores["password"]) ?></span>
                <?php endif; ?>
            </div>

            <!-- segundo input de contrasena solo para confirmar. el action
                 compara y si no coinciden rellena $errores["password2"] -->
            <div class="auth-group">
                <label class="auth-label">Confirmar contrasena</label>
                <input type="password" name="password2" id="password2-input" class="auth-input" required autocomplete="new-password">
                <?php if (!empty($errores["password2"])): ?>
                    <span class="field-error"><?= htmlspecialchars($errores["password2"]) ?></span>
                <?php endif; ?>
            </div>

            <div class="auth-group">
                <label class="auth-label">Fecha de nacimiento</label>
                <input type="date" name="fecha_nac" class="auth-input" required>
                <?php if (!empty($errores["fecha_nac"])): ?>
                    <span class="field-error"><?= htmlspecialchars($errores["fecha_nac"]) ?></span>
                <?php endif; ?>
            </div>

            <div class="auth-group">
                <label class="auth-label">Genero</label>
                <select name="genero" class="auth-select" required>
                    <option value="">-- Selecciona --</option>
                    <option value="masculino">Masculino</option>
                    <option value="femenino">Femenino</option>
                    <option value="otro">Otro</option>
                </select>
                <?php if (!empty($errores["genero"])): ?>
                    <span class="field-error"><?= htmlspecialchars($errores["genero"]) ?></span>
                <?php endif; ?>
            </div>

            <button type="submit" class="auth-btn">Crear cuenta</button>

        </form>

        <p class="auth-link">
            Ya tienes cuenta? <a href="index.php">Inicia sesion</a>
        </p>

    </div>

<!-- animaciones.js primero porque dentro vive iniciarCirculoSeguridad, y
     justo despues la llamada. si invertis el orden el canvas se queda pelado -->
<script src="JS/animaciones.js"></script>
<script>iniciarCirculoSeguridad();</script>

</body>
</html>