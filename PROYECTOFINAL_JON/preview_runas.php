<?php
// preview_runas.php — visual runico autonomo para embeber en index.php
//
// dibuja una R dorada central con un anillo de runas (futhark antiguo)
// alrededor, con animaciones de pulso y glow. todo SVG inline + CSS,
// sin canvas ni js. ligero (~3KB) y self-contained.
//
// publico, NO requiere sesion. el index.php lo embebe con <iframe> y le
// aplica filter: blur(8px) para que sirva como decoracion en las
// secciones donde aun no hay captura real. cuando subas capturas
// reales, sustituye el <iframe> por <img> en index.php.
//
// como esta pensado para iframe blureado:
//   - sin scrollbars (el SVG cubre el viewBox completo)
//   - sin interactividad (pointer-events:none en el iframe del padre)
//   - background mismo que la home para que no se note el corte
//
// !hi

// runas del futhark antiguo (alfabeto rúnico germánico). 12 simbolos
// repartidos en circulo. son codepoints unicode estandar (U+16A0..)
$runas = ['ᚠ', 'ᚢ', 'ᚦ', 'ᚨ', 'ᚱ', 'ᚲ', 'ᚷ', 'ᚹ', 'ᚺ', 'ᚾ', 'ᛁ', 'ᛃ'];
$total = count($runas);

// geometria del anillo. el viewBox es 400x400, centro en (200,200),
// radio del anillo a 155 para dejar margen visual con los bordes
$cx     = 200;
$cy     = 200;
$radio  = 155;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Preview rúnico</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

html, body {
    width: 100%; height: 100%;
    background: #0a0a0a;     /* mismo bg-deep que index.php para que el blur no se note como recorte */
    overflow: hidden;
    font-family: 'EB Garamond', 'Cinzel', Georgia, serif;
}

svg {
    display: block;
    width: 100%; height: 100%;
}

/* ── animaciones ─────────────────────────────────────────────
   tres efectos sumados: la R central respira (brillo y escala),
   cada runa del anillo pulsa con delay encadenado, el anillo entero
   rota muy lento (1 vuelta cada 80s) para que se note solo si miras
   la imagen mucho rato — sutil, no distrae */

.center-r {
    transform-origin: <?= $cx ?>px <?= $cy ?>px;
    animation: respirar 6s ease-in-out infinite;
}

@keyframes respirar {
    0%, 100% { filter: drop-shadow(0 0 6px rgba(255, 215, 0, 0.6)); transform: scale(1); }
    50%      { filter: drop-shadow(0 0 16px rgba(255, 215, 0, 0.9)); transform: scale(1.05); }
}

.ring {
    transform-origin: <?= $cx ?>px <?= $cy ?>px;
    animation: rotar 80s linear infinite;
}

@keyframes rotar {
    from { transform: rotate(0deg);   }
    to   { transform: rotate(360deg); }
}

.runa {
    animation: parpadeo 4s ease-in-out infinite;
}

@keyframes parpadeo {
    0%, 100% { opacity: 0.4; }
    50%      { opacity: 0.95; }
}

/* circulo guia: la linea punteada tambien rota un poco mas rapida en
   sentido contrario para crear efecto de profundidad */
.circulo-guia {
    transform-origin: <?= $cx ?>px <?= $cy ?>px;
    animation: rotar-inverso 50s linear infinite;
}

@keyframes rotar-inverso {
    from { transform: rotate(360deg); }
    to   { transform: rotate(0deg);   }
}
</style>
</head>
<body>
<svg viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet">

    <defs>
        <!-- glow radial dorado debajo de la R central -->
        <radialGradient id="centroGlow">
            <stop offset="0%"  stop-color="#ffd700" stop-opacity="0.55"/>
            <stop offset="40%" stop-color="#ffd700" stop-opacity="0.15"/>
            <stop offset="100%" stop-color="#ffd700" stop-opacity="0"/>
        </radialGradient>
        <!-- filtro de glow para los textos: gaussian blur + composite con
             la fuente original. asi el oro brilla sin perder nitidez -->
        <filter id="glow" x="-50%" y="-50%" width="200%" height="200%">
            <feGaussianBlur stdDeviation="2.5" result="b"/>
            <feMerge>
                <feMergeNode in="b"/>
                <feMergeNode in="SourceGraphic"/>
            </feMerge>
        </filter>
    </defs>

    <!-- halo radial central -->
    <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="120" fill="url(#centroGlow)"/>

    <!-- anillo guia con linea discontinua -->
    <g class="circulo-guia">
        <circle cx="<?= $cx ?>" cy="<?= $cy ?>"
                r="<?= $radio ?>"
                fill="none"
                stroke="#d4af37"
                stroke-width="1"
                stroke-dasharray="2,8"
                opacity="0.35"/>
    </g>

    <!-- letra central R -->
    <g class="center-r">
        <text x="<?= $cx ?>" y="<?= $cy + 38 ?>"
              text-anchor="middle"
              font-size="110"
              font-family="'Cinzel', serif"
              font-weight="700"
              fill="#ffd700"
              filter="url(#glow)">R</text>
    </g>

    <!-- anillo de runas. cada una en su posicion calculada por trigono-
         metria ($cx + radio*cos, $cy + radio*sin), con delay de animacion
         escalonado para que parezca que parpadean en cadena -->
    <g class="ring">
        <?php foreach ($runas as $i => $r):
            $ang = ($i / $total) * 2 * M_PI - M_PI / 2;  // empezamos arriba (-PI/2)
            $x   = $cx + $radio * cos($ang);
            $y   = $cy + $radio * sin($ang);
            $delay = number_format($i * 0.3, 2, '.', '');
        ?>
            <text class="runa"
                  x="<?= round($x, 1) ?>"
                  y="<?= round($y + 9, 1) /* +9 para centrar verticalmente el glyph */ ?>"
                  text-anchor="middle"
                  font-size="26"
                  font-family="'Cinzel', serif"
                  fill="#d4af37"
                  style="animation-delay: <?= $delay ?>s"><?= $r ?></text>
        <?php endforeach; ?>
    </g>

</svg>
</body>
</html>
