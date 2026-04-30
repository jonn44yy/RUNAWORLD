<?php
// reset.php — runaworld
// utilidad de desarrollo, nada mas. genera el hash de "admin1234" con
// password_hash() para pegarlo a mano en la bd cuando se me olvida la
// contrasena del admin (pasa mas de lo que deberia)
//
// AVISO: NO dejar este archivo en produccion. ahora mismo cualquiera que
// adivine la url ve el hash y sabe que la contrasena por defecto es
// "admin1234". no es el fin del mundo porque el hash sin la contrasena no
// sirve de mucho, pero es feo de dejar por ahi expuesto. borrar del server
// real antes de subir a prod, o al menos moverlo a una carpeta no servida.
//
// hecho en un apuro a mediados de marzo. llevo "voy a quitarlo" desde
// entonces. !hi al que lea esto antes de que lo borre (si es que lo borro)

$hash = password_hash("admin1234", PASSWORD_DEFAULT);
echo $hash;
?>