<?php
// index.php — homepage publica de runaworld
//
// es lo primero que ve un visitante al entrar al dominio. una sola pagina
// con scroll, reveals progresivos al hacer scroll. el boton "JUGAR" lleva
// directamente a juego.php si hay sesion, o a login.php si no.
//
// la query de ranking es publica (solo username + points), no expone nada
// sensible. si la tabla esta vacia se muestra un mensaje "se el primero".
//
// estilo visual: gotico-ocultismo, dorado-sobre-negro, alineado con la
// estetica del juego pero mas refinado. tipografias Cinzel (display) +
// EB Garamond (body) + JetBrains Mono (datos numericos). grain overlay
// para textura, linea vertical dorada como hilo conductor entre secciones.
//
// !hi

session_start();
require_once "PHP/conexion.php";

$logueado = isset($_SESSION["idUsuario"]);
$username = $logueado ? ($_SESSION["username"] ?? null) : null;

// ── ranking publico: top 10 jugadores por puntos ─────────────
// solo expongo username + points. si quisieras anadir mas info (suerte,
// total runas, eternas) sumas columnas aqui. filtro points > 0 para no
// listar cuentas recien creadas con todo a cero
$ranking = [];
try {
    $stmt = $conexion->prepare("
        SELECT u.username, j.points
        FROM jugadores j
        INNER JOIN usuarios u ON u.id = j.usuario_id
        WHERE j.points > 0
        ORDER BY j.points DESC
        LIMIT 10
    ");
    $stmt->execute();
    $ranking = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    // si la tabla no existe o algo raro pasa, dejamos el ranking vacio.
    // el HTML pinta "aun no hay jugadores" sin romper la pagina
    $ranking = [];
}
$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RunaWorld · Sistema rúnico</title>
<meta name="description" content="Idle clicker de runas con motor de probabilidades. Colecciona, asciende, descubre lo eterno.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;500;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<noscript><link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;500;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"></noscript>
<style>
:root {
    --bg-deep:        #0a0a0a;
    --bg:             #111;
    --bg-elevated:    #1a1a1a;
    --gold:           #d4af37;
    --gold-bright:    #ffd700;
    --gold-deep:      #8b6914;
    --text:           #e8e0d0;
    --text-dim:       #6a6a6a;
    --text-faint:     #3a3a3a;
    --line:           rgba(212, 175, 55, 0.15);
    --line-bright:    rgba(212, 175, 55, 0.4);
}

* { margin: 0; padding: 0; box-sizing: border-box; }
html { scroll-behavior: smooth; }

body {
    background: var(--bg-deep);
    color: var(--text);
    font-family: 'EB Garamond', Georgia, serif;
    font-size: 18px;
    line-height: 1.7;
    overflow-x: hidden;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* grain noise sutil sobre toda la pagina, da textura como papel envejecido */
body::before {
    content: '';
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 100;
    opacity: 0.05;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='n'><feTurbulence baseFrequency='0.85' numOctaves='2' stitchTiles='stitch'/></filter><rect width='100%25' height='100%25' filter='url(%23n)'/></svg>");
    mix-blend-mode: overlay;
}

/* linea vertical dorada que cruza la pagina: hilo conductor visual.
   se desvanece arriba y abajo para que no quede cortada */
.spine {
    position: fixed;
    left: 50%;
    top: 0;
    bottom: 0;
    width: 1px;
    background: linear-gradient(to bottom,
        transparent 0%,
        var(--line) 8%,
        var(--line) 92%,
        transparent 100%);
    z-index: 1;
    pointer-events: none;
}

/* ─── HERO ─────────────────────────────────────────────────── */

.hero {
    min-height: 100vh;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    text-align: center;
    padding: 4rem 2rem;
    z-index: 2;
}

/* halo radial detrás del título, pulsa lentamente */
.hero::before {
    content: '';
    position: absolute;
    width: min(800px, 90vw);
    height: min(800px, 90vw);
    border-radius: 50%;
    background: radial-gradient(circle,
        rgba(212, 175, 55, 0.10) 0%,
        rgba(212, 175, 55, 0.02) 35%,
        transparent 70%);
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    z-index: 0;
    animation: heroPulse 7s ease-in-out infinite;
}

@keyframes heroPulse {
    0%, 100% { opacity: 1;   transform: translate(-50%, -50%) scale(1); }
    50%      { opacity: 0.5; transform: translate(-50%, -50%) scale(1.1); }
}

.hero-version {
    position: absolute;
    top: 2rem; right: 2rem;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.65rem;
    letter-spacing: 0.3em;
    color: var(--gold-deep);
    border: 1px solid var(--gold-deep);
    padding: 0.4rem 0.8rem;
    z-index: 3;
    opacity: 0;
    animation: fadeIn 1.2s 2s ease-out forwards;
    text-decoration: none;
    cursor: pointer;
    transition: color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
}

.hero-version:hover {
    color: var(--gold);
    border-color: var(--gold);
    box-shadow: 0 0 12px rgba(212, 175, 55, 0.3);
}

.hero h1 {
    font-family: 'Cinzel', serif;
    font-weight: 600;
    font-size: clamp(2.8rem, 9vw, 7.5rem);
    letter-spacing: 0.05em;
    line-height: 1;
    color: var(--gold);
    text-shadow:
        0 0 40px rgba(212, 175, 55, 0.4),
        0 0 80px rgba(212, 175, 55, 0.2);
    margin-bottom: 1.8rem;
    position: relative; z-index: 2;
}

/* cada letra cae desde arriba con un delay encadenado. el JS inyecta
   los <span class="letra"> con su animation-delay individual */
.hero h1 .letra {
    display: inline-block;
    animation: letraDrop 0.34s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes letraDrop {
    from {
        opacity: 0;
        transform: translateY(34px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn { to { opacity: 1; } }

.hero-tagline {
    font-family: 'EB Garamond', serif;
    font-style: italic;
    font-size: clamp(1rem, 1.5vw, 1.3rem);
    color: rgba(255,248,226,0.96);
    text-shadow: 0 3px 18px rgba(0,0,0,1), 0 0 28px rgba(0,0,0,0.95), 0 0 18px rgba(212,175,55,0.32);
    margin-bottom: 4rem;
    position: relative; z-index: 2;
    opacity: 0;
    animation: fadeIn 1.2s 1.5s ease-out forwards;
    letter-spacing: 0.05em;
}

.hero-tagline::before, .hero-tagline::after {
    content: '·';
    margin: 0 1.2rem;
    color: var(--gold);
    font-style: normal;
}

.hero-cta {
    display: flex;
    gap: 1.5rem;
    position: relative; z-index: 2;
    opacity: 0;
    animation: fadeIn 1.2s 1.8s ease-out forwards;
    flex-wrap: wrap;
    justify-content: center;
}

.hero-scroll-cue {
    position: absolute;
    left: 50%;
    bottom: clamp(2rem, 6vh, 4.2rem);
    transform: translateX(-50%);
    z-index: 3;
    width: 54px;
    height: 54px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--gold);
    text-decoration: none;
    opacity: 0;
    animation: fadeIn 1.2s 2.25s ease-out forwards, scrollCueFloat 1.8s 2.25s ease-in-out infinite;
    filter: drop-shadow(0 0 14px rgba(212,175,55,.42));
}

.hero-scroll-cue::before,
.hero-scroll-cue::after {
    content: '';
    position: absolute;
    width: 23px;
    height: 23px;
    border-right: 2px solid currentColor;
    border-bottom: 2px solid currentColor;
    transform: rotate(45deg);
    opacity: .95;
}

.hero-scroll-cue::before { top: 7px; }
.hero-scroll-cue::after  { top: 21px; opacity: .62; }

.hero-scroll-cue:hover,
.hero-scroll-cue:focus-visible {
    color: var(--gold-bright);
    filter: drop-shadow(0 0 20px rgba(255,215,0,.58));
}

@keyframes scrollCueFloat {
    0%, 100% { transform: translate(-50%, 0); }
    50%      { transform: translate(-50%, 12px); }
}

.scroll-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 74px;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    padding: 0 1.4rem;
    z-index: 60;
    background: linear-gradient(to bottom, rgba(7,7,7,.92), rgba(7,7,7,.68));
    border-bottom: 1px solid rgba(212,175,55,.16);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    transform: translateY(-105%);
    opacity: 0;
    pointer-events: none;
    transition: transform .34s cubic-bezier(.16,1,.3,1), opacity .25s ease;
}

.scroll-header.visible {
    transform: translateY(0);
    opacity: 1;
    pointer-events: auto;
}

.scroll-header-cta {
    justify-self: start;
    display: flex;
    gap: .65rem;
    align-items: center;
}

.scroll-header-brand {
    justify-self: center;
    font-family: 'Cinzel', serif;
    font-size: clamp(1.15rem, 2.2vw, 1.9rem);
    letter-spacing: .25em;
    color: var(--gold);
    text-decoration: none;
    text-shadow: 0 0 18px rgba(212,175,55,.35);
    white-space: nowrap;
}

.scroll-header .btn {
    font-size: .64rem;
    letter-spacing: .22em;
    padding: .72rem 1.05rem;
}

.scroll-header-version {
    justify-self: end;
    font-family: 'JetBrains Mono', monospace;
    font-size: .58rem;
    letter-spacing: .18em;
    color: var(--gold-deep);
    text-decoration: none;
    border: 1px solid rgba(212,175,55,.22);
    padding: .45rem .7rem;
    transition: color .25s ease, border-color .25s ease, box-shadow .25s ease;
}

.scroll-header-version:hover,
.scroll-header-version:focus-visible {
    color: var(--gold);
    border-color: var(--gold);
    box-shadow: 0 0 12px rgba(212,175,55,.25);
}

.btn {
    font-family: 'Cinzel', serif;
    font-weight: 500;
    font-size: 0.85rem;
    letter-spacing: 0.4em;
    padding: 1.2rem 3rem;
    text-decoration: none;
    text-transform: uppercase;
    position: relative;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    cursor: pointer;
    display: inline-block;
}

.btn-primary {
    background: var(--gold);
    color: var(--bg-deep);
    border: 1px solid var(--gold);
}

.btn-primary:hover {
    background: var(--gold-bright);
    border-color: var(--gold-bright);
    box-shadow: 0 0 30px rgba(212, 175, 55, 0.5);
    letter-spacing: 0.5em;
}

.btn-secondary {
    background: transparent;
    color: var(--text);
    border: 1px solid var(--gold-deep);
}

.btn-secondary:hover {
    border-color: var(--gold);
    color: var(--gold);
    letter-spacing: 0.5em;
}

/* ─── SECCIONES ────────────────────────────────────────────── */

.seccion {
    scroll-margin-top: 6rem;
    max-width: 1100px;
    margin: 0 auto;
    padding: 8rem 2rem;
    position: relative;
    z-index: 2;
}

.seccion-titulo {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.7rem;
    letter-spacing: 0.5em;
    color: var(--gold-deep);
    margin-bottom: 1.2rem;
    display: flex;
    align-items: center;
    gap: 1.2rem;
}

.seccion-titulo::before {
    content: '';
    width: 40px; height: 1px;
    background: var(--gold-deep);
    flex-shrink: 0;
}

.seccion-h {
    font-family: 'Cinzel', serif;
    font-weight: 500;
    font-size: clamp(1.8rem, 4vw, 3.2rem);
    color: var(--gold);
    margin-bottom: 3.5rem;
    line-height: 1.15;
    max-width: 800px;
}

.seccion-grid {
    display: grid;
    grid-template-columns: 5fr 4fr;
    gap: 4rem;
    align-items: start;
}

.seccion-grid.invertido {
    grid-template-columns: 4fr 5fr;
}

.seccion-texto > p {
    margin-bottom: 1.5rem;
    color: var(--text);
}

.seccion-texto > p:first-child {
    font-size: 1.15rem;
    color: var(--text);
}

/* features con borde izquierdo dorado, se ilumina al hover */
.seccion-features {
    margin-top: 2.5rem;
}

.feature {
    border-left: 1px solid var(--line);
    padding: 0.5rem 0 0.5rem 1.5rem;
    margin-bottom: 1.8rem;
    transition: border-color 0.4s ease, padding-left 0.4s ease;
}

.feature:hover {
    border-left-color: var(--gold);
    padding-left: 1.8rem;
}

.feature h3 {
    font-family: 'Cinzel', serif;
    font-weight: 500;
    font-size: 1.05rem;
    color: var(--gold);
    margin-bottom: 0.4rem;
    letter-spacing: 0.1em;
}

.feature p {
    color: var(--text-dim);
    font-size: 0.95rem;
    line-height: 1.6;
}

/* placeholder para captura: borde dashed dorado, aspect 16:10. cuando
   metas la imagen real, sustituye el contenido por <img src="..."> */
.captura {
    border: 1px dashed var(--gold-deep);
    aspect-ratio: 16 / 10;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.7rem;
    letter-spacing: 0.3em;
    color: var(--gold-deep);
    position: relative;
    overflow: hidden;
    background:
        linear-gradient(135deg, transparent 49%, var(--line) 50%, transparent 51%) 0 0 / 30px 30px,
        var(--bg);
}

.captura::before {
    content: 'CAPTURA · PENDIENTE';
}

.captura img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}

/* iframe del preview runico, blureado para que actue como decoracion
   semi-abstracta. el iframe es 110% mas grande que el contenedor con
   margin negativo: asi el blur (8px) no muestra bordes nitidos en los
   limites del frame. pointer-events:none lo hace inerte (no captura
   clicks ni focus, solo se ve) */
.captura iframe {
    width: 110%;
    height: 110%;
    margin: -5%;
    border: 0;
    filter: blur(8px) saturate(1.2);
    pointer-events: none;
    /* gradiente sobre el iframe para reforzar la sensacion de "preview"
       y dar profundidad. el degradado va por encima en otro pseudo */
}

/* cuando hay iframe o img, ocultamos el placeholder de texto */
.captura:has(iframe)::before,
.captura:has(img)::before { display: none; }

/* overlay sobre el iframe blureado: viñeta dorada sutil + fade hacia
   los bordes para que se sienta integrado en la pagina */
.captura:has(iframe)::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at center,
            transparent 30%,
            rgba(10, 10, 10, 0.4) 90%);
    pointer-events: none;
}

/* ─── PROGRESIÓN: rarezas ──────────────────────────────────── */

.rarezas {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0.4rem;
    margin-top: 2rem;
}

.rareza {
    padding: 0.9rem 0.3rem;
    border: 1px solid;
    text-align: center;
    font-family: 'Cinzel', serif;
    font-size: 0.62rem;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    transition: transform 0.3s ease, box-shadow 0.3s ease, background 0.3s ease;
    /* reset de button: ahora son <button> para que sean clickables/
       accesibles (foco con tab, anuncio en lectores), pero hereda estilos
       default del navegador que rompen el diseno. quito margen y fuerzo
       background transparente */
    background: transparent;
    cursor: pointer;
    font-weight: 500;
}

.rareza:hover {
    transform: translateY(-3px);
}

/* card seleccionada: fondo dorado tenue + el borde se ilumina mas */
.rareza.activa,
.rareza-eterna-card.activa {
    background: rgba(212, 175, 55, 0.08);
    box-shadow: 0 0 18px rgba(212, 175, 55, 0.15);
}

.rareza[data-r="comun"]      { color: #8a8a8a; border-color: #8a8a8a40; }
.rareza[data-r="poco_comun"] { color: #5fb85f; border-color: #5fb85f40; }
.rareza[data-r="rara"]       { color: #4a90e2; border-color: #4a90e240; }
.rareza[data-r="epica"]      { color: #b14ae2; border-color: #b14ae240; }
.rareza[data-r="legendaria"] { color: #f0a020; border-color: #f0a02040; }
.rareza[data-r="mitica"]     { color: #e24a4a; border-color: #e24a4a40; }
.rareza[data-r="divina"]     {
    color: var(--gold-bright);
    border-color: rgba(255, 215, 0, 0.5);
    box-shadow: 0 0 20px rgba(255, 215, 0, 0.2);
}

/* tarjeta especial para Eterna: separada del resto, con glow morado */
.rareza-eterna-card {
    margin-top: 2.5rem;
    padding: 2rem;
    border: 1px solid var(--gold);
    background:
        linear-gradient(135deg, rgba(212, 175, 55, 0.08), transparent 70%);
    position: relative;
    overflow: hidden;
    /* reset de button (ahora es clickable) */
    width: 100%;
    text-align: left;
    cursor: pointer;
    font-family: inherit;
    color: inherit;
    transition: background 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;
    display: block;
}

.rareza-eterna-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(212, 175, 55, 0.2);
}

.rareza-eterna-card::before {
    content: '∞';
    position: absolute;
    top: -10px; right: 15px;
    font-family: 'Cinzel', serif;
    font-size: 5rem;
    color: rgba(212, 175, 55, 0.1);
    line-height: 1;
}

.rareza-eterna-card h4 {
    font-family: 'Cinzel', serif;
    letter-spacing: 0.4em;
    color: var(--gold);
    margin-bottom: 0.6rem;
    font-size: 1rem;
}

.rareza-eterna-card p {
    color: var(--text-dim);
    font-size: 0.95rem;
    font-style: italic;
    margin: 0;
}

/* ─── VERSIÓN actual / changelog ───────────────────────────── */

.cambios-titulo {
    font-family: 'Cinzel', serif;
    color: var(--gold);
    letter-spacing: 0.25em;
    margin-bottom: 1.2rem;
    font-weight: 500;
    font-size: 0.9rem;
}

.cambios-titulo.proximo { color: var(--gold-deep); }

.cambios {
    list-style: none;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.85rem;
}

.cambios li {
    padding: 0.7rem 0 0.7rem 2rem;
    position: relative;
    border-bottom: 1px solid var(--line);
    color: var(--text);
    line-height: 1.5;
}

.cambios li::before {
    content: '+';
    position: absolute;
    left: 0;
    color: var(--gold);
    font-weight: bold;
}

.cambios.proximo li::before {
    content: '~';
    color: var(--text-dim);
}

.cambios.proximo li {
    color: var(--text-dim);
}


.version-entry {
    margin-bottom: 5rem;
    padding-bottom: 4rem;
    border-bottom: 1px solid var(--line);
}
.version-entry:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
.version-entry .seccion-h { margin-bottom: 2.5rem; }
.version-note {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.72rem;
    letter-spacing: 0.16em;
    color: var(--text-dim);
    text-transform: uppercase;
    margin: -1.5rem 0 2.4rem;
}


/* ─── ACCESIBILIDAD / NAVEGACIÓN DE VERSIONES ─────────────── */
.sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
.skip-link { position:fixed; top:.8rem; left:.8rem; z-index:9999; transform:translateY(-140%); background:#000; color:var(--gold-bright); border:1px solid var(--gold); padding:.7rem 1rem; font-family:'JetBrains Mono',monospace; font-size:.75rem; letter-spacing:.08em; text-decoration:none; transition:transform .2s ease; }
.skip-link:focus { transform:translateY(0); outline:2px solid var(--gold-bright); outline-offset:3px; }
a:focus-visible, button:focus-visible { outline:2px solid var(--gold-bright); outline-offset:4px; }
.version-shell { display:grid; grid-template-columns:minmax(130px,190px) minmax(0,1fr); gap:clamp(1.5rem,3vw,3rem); align-items:start; }
.version-side-nav { position:sticky; top:6rem; align-self:start; border:1px solid rgba(212,175,55,.16); background:rgba(10,10,10,.52); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); padding:.9rem; z-index:5; }
.version-side-nav-title { font-family:'JetBrains Mono',monospace; font-size:.58rem; letter-spacing:.22em; color:var(--text-dim); text-transform:uppercase; margin-bottom:.75rem; }
.version-side-nav a { display:block; color:var(--text-dim); text-decoration:none; font-family:'Cinzel',serif; font-size:.82rem; letter-spacing:.08em; padding:.58rem .7rem; border-left:1px solid rgba(212,175,55,.18); transition:color .2s ease, background .2s ease, border-color .2s ease; }
.version-side-nav a:hover, .version-side-nav a:focus-visible { color:var(--gold); background:rgba(212,175,55,.06); border-left-color:var(--gold); }
.version-list { min-width:0; }
.version-entry { scroll-margin-top:2rem; }
@media (prefers-reduced-motion: reduce) { html { scroll-behavior:auto; } *,*::before,*::after { animation-duration:.001ms !important; animation-iteration-count:1 !important; transition-duration:.001ms !important; } #bg-eterno { display:none !important; } }

/* ─── RANKING ─────────────────────────────────────────────── */

.ranking {
    border-top: 1px solid var(--line);
    margin-top: 2rem;
}

.ranking-fila {
    display: grid;
    grid-template-columns: 60px 1fr auto;
    gap: 2rem;
    padding: 1.3rem 1.5rem;
    border-bottom: 1px solid var(--line);
    align-items: center;
    transition: background 0.3s ease, padding-left 0.3s ease;
}

.ranking-fila:hover {
    background: rgba(212, 175, 55, 0.04);
    padding-left: 2rem;
}

.ranking-pos {
    font-family: 'Cinzel', serif;
    font-size: 1.4rem;
    color: var(--text-faint);
    font-weight: 500;
}

.ranking-fila[data-pos="1"] .ranking-pos {
    color: var(--gold-bright);
    text-shadow: 0 0 15px var(--gold);
}
.ranking-fila[data-pos="2"] .ranking-pos { color: #c0c0c0; }
.ranking-fila[data-pos="3"] .ranking-pos { color: #cd7f32; }

.ranking-nombre {
    font-family: 'Cinzel', serif;
    font-size: 1rem;
    color: var(--text);
    letter-spacing: 0.05em;
}

.ranking-puntos {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.95rem;
    color: var(--gold);
}

.ranking-vacio {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--text-dim);
    font-style: italic;
    font-family: 'EB Garamond', serif;
}

/* ─── CRÉDITOS ─────────────────────────────────────────────── */

.creditos {
    text-align: center;
    padding: 8rem 2rem 4rem;
    border-top: 1px solid var(--line);
    margin-top: 4rem;
    position: relative;
    z-index: 2;
}

.creditos .seccion-titulo {
    justify-content: center;
}

.creditos .seccion-titulo::before {
    display: none;
}

.creditos h2 {
    font-family: 'Cinzel', serif;
    font-weight: 500;
    font-size: clamp(1.8rem, 3.5vw, 2.8rem);
    color: var(--gold);
    margin-bottom: 2rem;
    line-height: 1.2;
}

.creditos p {
    color: var(--text-dim);
    margin-bottom: 0.5rem;
    font-size: 1.05rem;
}

.stack {
    font-family: 'JetBrains Mono', monospace !important;
    font-size: 0.75rem !important;
    color: var(--gold-deep) !important;
    letter-spacing: 0.3em;
    margin-top: 2rem !important;
}

footer {
    text-align: center;
    padding: 3rem 2rem 2.5rem;
    border-top: 1px solid var(--line);
}

/* iconos sociales con hover dorado y leve elevacion. el SVG hereda el
   color del <a> (currentColor) asi un solo cambio en :hover repinta todo */
.social-links {
    display: flex;
    justify-content: center;
    gap: 2rem;
    margin-bottom: 2rem;
}

.social-links a {
    color: var(--gold-deep);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px; height: 44px;
    border: 1px solid var(--line);
    transition: color 0.3s ease, transform 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
    text-decoration: none;
}

.social-links a:hover {
    color: var(--gold);
    border-color: var(--gold);
    transform: translateY(-3px);
    box-shadow: 0 4px 14px rgba(212, 175, 55, 0.15);
}

.footer-copyright {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.65rem;
    color: var(--text-faint);
    letter-spacing: 0.3em;
}

/* ─── ANIMACIONES SCROLL ───────────────────────────────────── */

.fade-up {
    opacity: 0;
    transform: translateY(40px);
    transition:
        opacity 1s cubic-bezier(0.16, 1, 0.3, 1),
        transform 1s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: opacity, transform;
}

.fade-up.visible {
    opacity: 1;
    transform: translateY(0);
}

.fade-up.delay-1 { transition-delay: 0.1s; }
.fade-up.delay-2 { transition-delay: 0.2s; }
.fade-up.delay-3 { transition-delay: 0.3s; }
.fade-up.delay-4 { transition-delay: 0.4s; }

/* ─── MOBILE ───────────────────────────────────────────────── */

@media (max-width: 768px) {
    body { font-size: 16px; }

    .hero h1 {
        font-size: clamp(1.8rem, 8vw, 3.5rem);
        letter-spacing: 0.02em;
    }
    
    .seccion { padding: 5rem 1.5rem; }

    .seccion-grid, .seccion-grid.invertido {
        grid-template-columns: 1fr;
        gap: 2rem;
    }

    /* en movil invertimos el orden para que la captura siempre vaya
       debajo del texto, mas natural en columna */
    .seccion-grid.invertido > .captura { order: 2; }
    .seccion-grid.invertido > .seccion-texto { order: 1; }

    .hero-cta {
        flex-direction: column;
        width: 100%;
        max-width: 320px;
    }

    .hero-cta .btn { padding: 1rem 2rem; }

    .hero-version {
        top: 1rem; right: 1rem;
        font-size: 0.55rem;
        padding: 0.3rem 0.6rem;
    }

    .hero-scroll-cue {
        bottom: 1.6rem;
        width: 46px;
        height: 46px;
    }

    .scroll-header {
        height: auto;
        min-height: 72px;
        grid-template-columns: 1fr;
        justify-items: center;
        gap: .5rem;
        padding: .7rem .75rem .62rem;
    }

    .scroll-header-brand { display: none; }

    .scroll-header-cta {
        justify-self: center;
        width: 100%;
        max-width: 360px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: .55rem;
    }

    .scroll-header .btn {
        text-align: center;
        padding: .72rem .55rem;
        font-size: .57rem;
        letter-spacing: .12em;
    }

    .scroll-header-version {
        justify-self: center;
        font-size: .52rem;
        padding: .22rem .5rem;
        letter-spacing: .14em;
        border-color: rgba(212,175,55,.18);
        opacity: .86;
    }

    .rarezas {
        grid-template-columns: repeat(4, 1fr);
    }

    .ranking-fila {
        grid-template-columns: 40px 1fr auto;
        gap: 1rem;
        padding: 1rem;
    }

    .ranking-pos { font-size: 1.1rem; }

    .creditos { padding: 5rem 1.5rem 3rem; }

    .spine { display: none; }

    #bg-eterno,
    #bg-eterno-veil { display: none !important; }
    .version-shell { display:block; }
    .version-side-nav { position:sticky; top:5.25rem; display:flex; gap:.35rem; overflow-x:auto; padding:.42rem; margin-bottom:1.25rem; background:rgba(8,8,8,.72); backdrop-filter:blur(12px); -webkit-backdrop-filter:blur(12px); border-color:rgba(212,175,55,.18); z-index:35; }
    .version-side-nav-title { display:none; }
    .version-side-nav a { flex:0 0 auto; border-left:none; border:1px solid rgba(212,175,55,.14); background:rgba(0,0,0,.22); padding:.34rem .52rem; font-size:.66rem; letter-spacing:.06em; }

}

@media (max-width: 480px) {
    .rarezas { grid-template-columns: repeat(3, 1fr); }
}

/* fondo animado en bucle: la animacion eterna full-screen detras de todo.
   uso el mismo blur que los previews (limpio, saturado) y oscurezco con
   un overlay aparte (#bg-eterno-veil) en vez de con `brightness` para
   no lavar los dorados */
#bg-eterno {
    position: fixed;
    inset: 0;
    width: 100vw;
    height: 100vh;
    border: none;
    z-index: -20;
    pointer-events: none;
    filter: blur(24px) saturate(0.62) brightness(0.42);
    opacity: 0.40;
    transform: scale(1.18);   /* hide blur edges */
}

/* veil oscuro encima del bg para que el contenido se lea bien.
   ajusta opacity de 0.55 a 0.75 si lo quieres mas oscuro */
#bg-eterno-veil {
    position: fixed;
    inset: 0;
    z-index: -10;
    pointer-events: none;
    background:
        radial-gradient(ellipse at center, rgba(0,0,0,0.72) 0%, rgba(0,0,0,0.96) 100%);
}
</style>
</head>
<body>
<a class="skip-link" href="#main-content">Saltar al contenido</a>

<nav class="scroll-header" id="scroll-header" aria-label="Acceso rápido">
    <div class="scroll-header-cta">
        <?php if ($logueado): ?>
            <a href="juego.php" class="btn btn-primary">Continuar</a>
            <a href="PHP/cerrar_sesion.php" class="btn btn-secondary">Salir</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary">Jugar</a>
            <a href="registro.php" class="btn btn-secondary">Crear cuenta</a>
        <?php endif; ?>
    </div>
    <a href="#top" class="scroll-header-brand" aria-label="Volver al inicio">RUNAWORLD</a>
    <a href="#version" class="scroll-header-version" aria-label="Ir al changelog de versiones">CHANGELOG</a>
</nav>

<div class="spine" aria-hidden="true"></div>
<!-- Fondo animado eterno (bucle infinito) + veil para oscurecer -->
<iframe id="bg-eterno" data-src="RUNAS_HTML/RUNAS/eterna_index_low_quality.html" 
        loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true"></iframe>
<div id="bg-eterno-veil" aria-hidden="true"></div>
<!-- ─── HERO ─────────────────────────────────────────────── -->
<header class="hero" id="top">
    <a href="#version-v023" class="hero-version" aria-label="Ir a las notas del parche de versiones">VERSIÓN 0.2.3</a>
    <h1 id="hero-h1">
        <span class="letra" style="animation-delay:0.03s">R</span><span class="letra" style="animation-delay:0.06s">U</span><span class="letra" style="animation-delay:0.09s">N</span><span class="letra" style="animation-delay:0.12s">A</span><span class="letra" style="animation-delay:0.15s">W</span><span class="letra" style="animation-delay:0.18s">O</span><span class="letra" style="animation-delay:0.21s">R</span><span class="letra" style="animation-delay:0.24s">L</span><span class="letra" style="animation-delay:0.27s">D</span>
    </h1>
    <p class="hero-tagline">colecciona, asciende, descubre lo eterno</p>
    <div class="hero-cta">
        <?php if ($logueado): ?>
            <a href="juego.php" class="btn btn-primary">Continuar partida</a>
            <a href="PHP/cerrar_sesion.php" class="btn btn-secondary">Cerrar sesión</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary">Jugar</a>
            <a href="registro.php" class="btn btn-secondary">Crear cuenta</a>
        <?php endif; ?>
    </div>
    <a href="#el-juego" class="hero-scroll-cue" aria-label="Bajar al contenido"></a>
</header>

<main id="main-content">
<!-- ─── SECCIÓN 1: QUÉ ES ─────────────────────────────────── -->
<section class="seccion" id="el-juego">
    <div class="seccion-titulo fade-up">EL JUEGO</div>
    <h2 class="seccion-h fade-up delay-1">Idle clicker de runas con motor de probabilidades</h2>
    <div class="seccion-grid">
        <div class="seccion-texto fade-up delay-2">
            <p>RunaWorld es un sistema de gestión rúnica donde cada tirada es una apuesta contra una distribución de probabilidades en forma de campana. No hay azar puro: hay matemática.</p>
            <div class="seccion-features">
                <div class="feature">
                    <h3>Motor probabilístico</h3>
                    <p>Curvas de campana ajustables por rareza. La suerte modifica el centro de la distribución, no solo los pesos finales.</p>
                </div>
                <div class="feature">
                    <h3>Idle real</h3>
                    <p>Sigue generando puntos y monedas estés conectado o no. El servidor calcula los pasivos al volver, con cap horario.</p>
                </div>
                <div class="feature">
                    <h3>Sin pay-to-win</h3>
                    <p>Proyecto académico. Sin microtransacciones, sin anuncios, sin telemetría externa. Tu progreso es tuyo.</p>
                </div>
            </div>
        </div>
        <div class="captura fade-up delay-3">
            <!-- preview blureado de una runa real (sacada de RUNAS_HTML/).
                 el JS de mas abajo cambia el src a la rareza que el visitante
                 clickee en las cards de la siguiente seccion. cuando tengas
                 capturas reales, sustituye este iframe por: <img src="..."> -->
            <iframe id="preview-runa-1" src="RUNAS_HTML/RUNAS/eterna_index_low_quality.html" loading="lazy" scrolling="no" tabindex="-1" title="Preview rúnico"></iframe>
        </div>
    </div>
</section>

<!-- ─── SECCIÓN 2: PROGRESIÓN ─────────────────────────────── -->
<section class="seccion" id="progresion">
    <div class="seccion-titulo fade-up">PROGRESIÓN</div>
    <h2 class="seccion-h fade-up delay-1">Ocho rarezas, variantes corruptas y camino hacia lo eterno</h2>
    <div class="seccion-grid invertido">
        <div class="captura fade-up delay-2">
            <!-- preview de la rareza seleccionada. arranca con eterna por
                 ser la mas vistosa. el JS cambia el src cuando el usuario
                 clickea cualquier card de rareza, manteniendo el blur (el
                 efecto: ves runas pero borrosas, "para descubrirlas
                 jugando") -->
            <iframe id="preview-runa-2" src="RUNAS_HTML/RUNAS/eterna_index_low_quality.html" loading="lazy" scrolling="no" tabindex="-1" title="Preview rúnico"></iframe>
        </div>
        <div class="seccion-texto fade-up delay-3">
            <p>Cada runa pertenece a una rareza. Subir desde común a eterna lleva tiempo, paciencia y suerte. Ahora, las variantes corruptas abren una segunda capa de colección, riesgo y recompensa.</p>
            <p style="font-size:0.9rem; color: var(--text-dim); font-style: italic;">Pulsa una rareza para ver su runa.</p>
            <div class="rarezas">
                <button type="button" class="rareza" data-r="comun"      data-runa="comun">Común</button>
                <button type="button" class="rareza" data-r="poco_comun" data-runa="poco_comun">P. común</button>
                <button type="button" class="rareza" data-r="rara"       data-runa="rara">Rara</button>
                <button type="button" class="rareza" data-r="epica"      data-runa="epica">Épica</button>
                <button type="button" class="rareza" data-r="legendaria" data-runa="legendaria">Legend.</button>
                <button type="button" class="rareza" data-r="mitica"     data-runa="mitica">Mítica</button>
                <button type="button" class="rareza" data-r="divina"     data-runa="divina">Divina</button>
            </div>
            <button type="button" class="rareza-eterna-card activa" data-runa="eterna">
                <h4>ETERNA</h4>
                <p>Únicas. Solo se obtienen una vez. Su aparición es un acontecimiento, no un drop.</p>
            </button>
        </div>
    </div>
</section>

<!-- ─── SECCIÓN 3: VERSIONES / ESTADO ACTUAL ─────────────── -->
<section class="seccion" id="version" aria-labelledby="version-title">
    <div class="seccion-titulo fade-up">ESTADO ACTUAL</div>
    <h2 id="version-title" class="sr-only">Historial de versiones de RunaWorld</h2>
    <div class="version-shell">
        <nav class="version-side-nav" aria-label="Navegación de versiones">
            <div class="version-side-nav-title">Versiones</div>
            <a href="#version-v023">0.2.3V</a>
            <a href="#version-v022">0.2.2V</a>
            <a href="#version-v021">0.2.1V</a>
            <a href="#version-v02">0.2V</a>
            <a href="#version-v01">0.1V</a>
        </nav>
        <div class="version-list">
    <article class="version-entry" id="version-v023">
        <h2 class="seccion-h fade-up delay-1">0.2.3V · Runas corruptas, colección y economía visual</h2>
        <p class="version-note fade-up delay-1">Parche centrado en variantes corruptas, colección, móvil, boosts y coherencia visual</p>
        <div class="seccion-grid">
            <div class="seccion-texto fade-up delay-2">
                <div class="cambios-titulo">Añadido y corregido</div>
                <ul class="cambios">
                    <li>Añadidas nuevas runas corruptas especiales: Divina Corrupta y Eterna Corrupta, con previews y animaciones independientes.</li>
                    <li>Unificada la lógica de previews: las runas normales y corruptas cargan sus HTML desde el mismo sistema de colección.</li>
                    <li>El menú lateral y la colección separan runas básicas y corruptas con estados de desbloqueo coherentes.</li>
                    <li>Las runas bloqueadas ya no revelan nombres reales en colección, estadísticas ni panel lateral.</li>
                    <li>Corregidos los toggles de animación para Divina Corrupta y Eterna Corrupta.</li>
                    <li>Añadido control global en Ajustes para activar o desactivar todas las animaciones de runas.</li>
                    <li>La colección se refresca al entrar y tras tiradas/sincronizaciones, evitando depender de reiniciar la página.</li>
                </ul>
            </div>
            <div class="seccion-texto fade-up delay-3">
                <div class="cambios-titulo proximo">Economía e interfaz</div>
                <ul class="cambios proximo">
                    <li>Separado el valor base de points/seg del valor visual con boosts temporales, evitando multiplicaciones incorrectas en tienda.</li>
                    <li>Corregida la visualización de bulk al completar la colección básica corrupta: el bonus aplica x2 suerte y +2 bulk.</li>
                    <li>Los bonus de colección normal y corrupta son independientes y no se reclaman ni muestran mezclados.</li>
                    <li>Mejorada la interfaz móvil: stats visibles, colección más estable, tabs más legibles y menú lateral más coherente con escritorio.</li>
                    <li>Mejorado el formato de números grandes con K, M, B, T, Qa y Qi.</li>
                    <li>Las probabilidades muestran valor base y valor ajustado por suerte del jugador con mejor contraste.</li>
                    <li>Actualizado el icono de suerte con un trébol SVG inline en el HUD.</li>
                </ul>
            </div>
        </div>
    </article>

    <article class="version-entry" id="version-v022">
        <h2 class="seccion-h fade-up delay-1">0.2.2V · Sincronización económica y estabilidad</h2>
        <p class="version-note fade-up delay-1">Parche técnico centrado en economía, packs, boosts y tienda</p>
        <div class="seccion-grid">
            <div class="seccion-texto fade-up delay-2">
                <div class="cambios-titulo">Correcciones principales</div>
                <ul class="cambios">
                    <li>Corregida la sincronización visual de coins para evitar saltos, bajadas falsas y respuestas antiguas del servidor.</li>
                    <li>Ajustado el sistema de packs para reducir desincronizaciones durante clicks rápidos, boosts activos y tiradas pendientes.</li>
                    <li>Optimizado el flujo de tiradas con packs de mayor tamaño, prefetch más estable y confirmaciones menos frecuentes.</li>
                    <li>Corregidas compras de tienda para usar el saldo visible actual del jugador y evitar reseteos incorrectos de puntos.</li>
                    <li>Protegidas las compras recientes frente a autosaves o sincronizaciones antiguas que podían revertir el gasto.</li>
                    <li>Actualización inmediata de la suerte al comprar mejoras como el Amuleto de suerte.</li>
                    <li>Corregido el cálculo de boosts para evitar multiplicar valores ya boosteados de coins/seg o points/seg.</li>
                </ul>
            </div>
            <div class="seccion-texto fade-up delay-3">
                <div class="cambios-titulo proximo">Estado del parche</div>
                <ul class="cambios proximo">
                    <li>La economía queda mucho más estable entre cliente, servidor, packs, autosave y tienda.</li>
                    <li>Se reducen peticiones innecesarias al servidor durante sesiones activas.</li>
                    <li>El inventario queda preparado para el nuevo sistema de colección y variantes.</li>
                    <li>Se continuará revisando el comportamiento de boosts, tiradas rápidas y sincronización entre dispositivos.</li>
                </ul>
            </div>
        </div>
    </article>

    <article class="version-entry" id="version-v021">
        <h2 class="seccion-h fade-up delay-1">0.2.1V · Divisas y visibilidad</h2>
        <p class="version-note fade-up delay-1">Correcciones técnicas y ajustes del flujo de usuario</p>
        <div class="seccion-grid">
            <div class="seccion-texto fade-up delay-2">
                <div class="cambios-titulo">Implementado</div>
                <ul class="cambios">
                    <li>Corregida la actualización en tiempo real de coins y puntos tras tiradas y sincronizaciones.</li>
                    <li>Revisada la lógica de economía para que los descuentos de divisas sean más consistentes tras cada transacción.</li>
                    <li>Añadidas restricciones de visibilidad para elementos bloqueados: el contenido aparece cuando se desbloquea.</li>
                    <li>Ocultación inicial de runas corruptas no desbloqueadas en el panel de runas.</li>
                    <li>Mejorada la lógica de aparición de variantes corruptas y animaciones especiales asociadas.</li>
                </ul>
            </div>
            <div class="seccion-texto fade-up delay-3">
                <div class="cambios-titulo proximo">Pendiente</div>
                <ul class="cambios proximo">
                    <li>Rework completo del inventario y de la representación visual de runas obtenidas.</li>
                    <li>Mejoras adicionales en el sistema de colección y clasificación de variantes.</li>
                    <li>Revisión de mensajes y feedback visual para desbloqueos nuevos.</li>
                </ul>
            </div>
        </div>
    </article>

    <article class="version-entry" id="version-v02">
        <h2 class="seccion-h fade-up delay-1">0.2V · Mejoras del juego</h2>
        <p class="version-note fade-up delay-1">Avance del proyecto, fase critica, avance en un nuevo campo y contenido</p>
        <div class="seccion-grid">
            <div class="seccion-texto fade-up delay-2">
                <div class="cambios-titulo">Cambios principales</div>
                <ul class="cambios">
                    <li>Sistema de suerte rediseñado con límite máximo de x1.50.</li>
                    <li>Sincronización de tiradas por packs para reducir peticiones al servidor.</li>
                    <li>Tirada visual unitaria: una apertura muestra una única runa.</li>
                    <li>Nerfeo completo de tienda para alargar la progresión y evitar escalado excesivo.</li>
                    <li>Revisión de boosts legendarios/divinos para que dependan de desbloqueos.</li>
                    <li>Rediseño inicial de colección con listas de runas y variantes futuras.</li>
                </ul>
            </div>
            <div class="seccion-texto fade-up delay-3">
                <div class="cambios-titulo proximo">Próximamente</div>
                <ul class="cambios proximo">
                    <li>Nuevas runas básicas, intermedias y avanzadas.</li>
                    <li>Variantes futuras: normales, corruptas y caos.</li>
                    <li>Más mejoras en la tienda y nuevos desbloqueos progresivos.</li>
                    <li>La sección Estadísticas pasará a convertirse en Perfil.</li>
                    <li>Mejoras internas del sistema de seguridad y validación del servidor.</li>
                    <li>Posible reset de progreso: se podría borrar el progreso de usuarios existentes.</li>
                </ul>
            </div>
        </div>
    </article>

    <article class="version-entry" id="version-v01">
        <h2 class="seccion-h fade-up delay-1">0.1V · Beta abierta</h2>
        <p class="version-note fade-up delay-1">Registro original de la primera beta pública</p>
        <div class="seccion-grid">
            <div class="seccion-texto fade-up delay-2">
                <div class="cambios-titulo">Implementado</div>
                <ul class="cambios">
                    <li>Motor de probabilidades estilo campana</li>
                    <li>Tienda de mejoras con tres tiers (eternas, especiales, normales)</li>
                    <li>Sistema de boosts temporales con multiplicadores</li>
                    <li>Bonus por colecciones completas</li>
                    <li>Sincronización servidor-autoritativa anti-trampas</li>
                    <li>Mobile responsive completo</li>
                </ul>
            </div>
            <div class="seccion-texto fade-up delay-3">
                <div class="cambios-titulo proximo">Próximamente</div>
                <ul class="cambios proximo">
                    <li>Balanceo completo de tienda</li>
                    <li>Perfil de jugador con estadísticas detalladas</li>
                    <li>Sistema de prestigio / renacimiento</li>
                    <li>Logros y desafíos</li>
                    <li>Más runas eternas</li>
                </ul>
            </div>
        </div>
    </article>
        </div>
    </div>
</section>

<!-- ─── SECCIÓN 4: RANKING ────────────────────────────────── -->
<section class="seccion" id="ranking">
    <div class="seccion-titulo fade-up">CLASIFICACIÓN</div>
    <h2 class="seccion-h fade-up delay-1">Top jugadores por puntos</h2>
    <div class="ranking fade-up delay-2">
        <?php if (empty($ranking)): ?>
            <div class="ranking-vacio">Aún no hay jugadores en la clasificación. Sé el primero.</div>
        <?php else: ?>
            <?php foreach ($ranking as $i => $r): ?>
                <div class="ranking-fila" data-pos="<?= $i + 1 ?>">
                    <div class="ranking-pos"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></div>
                    <div class="ranking-nombre"><?= htmlspecialchars($r["username"]) ?></div>
                    <div class="ranking-puntos" data-pts="<?= (float)$r["points"] ?>"><?= number_format((float)$r["points"], 0, '.', '.') ?> pts</div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<!-- ─── CRÉDITOS ──────────────────────────────────────────── -->
<section class="creditos" id="creditos">
    <div class="seccion-titulo fade-up">SOBRE EL PROYECTO</div>
    <h2 class="fade-up delay-1">Hecho a mano</h2>
    <div class="fade-up delay-2">
        <p>RunaWorld es un proyecto académico desarrollado en solitario.</p>
        <p>Sin frameworks, sin generadores, sin atajos.</p>
        <p class="stack">PHP · MySQL · JAVASCRIPT · HTML · CSS</p>
    </div>
</section>

</main>

<!-- Footer con iconos a redes sociales / contacto. los SVG son inline
     (no dependo de fontawesome ni cdn) y heredan el color del padre, asi
     el hover dorado se aplica con un solo cambio de color en el <a>.
     El href de github es un placeholder, sustituye con el link real -->
<footer>
    <div class="social-links">
        <a href="https://www.instagram.com/jon._.yyy?igsh=djNnNWN1bmN5d3Iw" target="_blank" rel="noopener noreferrer" aria-label="Instagram" title="Instagram">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
                <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/>
                <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/>
            </svg>
        </a>
        <a href="mailto:jonvmaribor@gmail.com" aria-label="Email de contacto" title="jonvmaribor@gmail.com">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="16" rx="2"/>
                <path d="m22 7-10 6L2 7"/>
            </svg>
        </a>
        <a href="https://github.com/jonn44yy" target="_blank" rel="noopener noreferrer" aria-label="GitHub" title="Código fuente en GitHub">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>
            </svg>
        </a>
    </div>
    <div class="footer-copyright">© 2026 · RUNAWORLD</div>
</footer>

<script src="JS/abbr-input.js"></script>
<script>
// ─── scroll-triggered fade-up ─────────────────────────────────
// IntersectionObserver con threshold y rootMargin para que el reveal
// dispare cuando el elemento entra en pantalla, no cuando esta justo
// en el borde. unobserve tras dispararse para liberar memoria
(function () {
    var ob = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting) {
                e.target.classList.add('visible');
                ob.unobserve(e.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -80px 0px' });

    document.querySelectorAll('.fade-up').forEach(function (el) {
        ob.observe(el);
    });
})();

// ─── ranking: formatear puntos con sufijos cortos ─────────────
// usa fmtAbbr de abbr-input.js. asi un jugador con 2.5e21 puntos sale
// como "2.5Sx pts" en vez de "2.500.000.000.000.000.000.000 pts"
(function () {
    if (typeof window.fmtAbbr !== 'function') return;
    document.querySelectorAll('.ranking-puntos').forEach(function (el) {
        var n = parseFloat(el.dataset.pts);
        if (isNaN(n)) return;
        // si el numero es chico (< 100k), dejo el formato con miles para
        // que no salga "23k pts" cuando son solo 23 mil (la version corta
        // pierde precision util en numeros pequenos). a partir de 100k uso
        // fmtAbbr que es mas legible
        if (n < 100000) {
            el.textContent = n.toLocaleString('es-ES') + ' pts';
        } else {
            el.textContent = window.fmtAbbr(n) + ' pts';
        }
    });
})();

// ─── selector de runa: cambiar iframes al clickar una rareza ──
// los dos iframes (preview-runa-1 y preview-runa-2) cargan el HTML
// correspondiente desde RUNAS_HTML/. la card clickeada queda con clase
// .activa (fondo dorado tenue) y se borra de las demas.
//
// arranca con eterna activa porque es lo que cargan los iframes por
// defecto. asi el visitante ve coherencia visual entre captura y card
(function () {
    var iframe1 = document.getElementById('preview-runa-1');
    var iframe2 = document.getElementById('preview-runa-2');
    var cards   = document.querySelectorAll('[data-runa]');
    if (!cards.length) return;

    // mapa de nombres de runa -> archivo. eterna usa la version low quality
    // porque la real lleva canvas+JS+filtros gaussian que matan la GPU.
    // las demas runas son ligeras y van directas a [runa].html
    function archivoRuna(runa) {
        if (runa === 'eterna') return 'RUNAS_HTML/RUNAS/eterna_index_low_quality.html';
        return 'RUNAS_HTML/RUNAS/' + runa + '.html';
    }

    function seleccionar(card) {
        var runa = card.getAttribute('data-runa');
        if (!runa) return;
        var url = archivoRuna(runa);
        if (iframe1) iframe1.src = url;
        if (iframe2) iframe2.src = url;
        cards.forEach(function (c) { c.classList.remove('activa'); });
        card.classList.add('activa');
    }

    cards.forEach(function (card) {
        card.addEventListener('click', function () { seleccionar(card); });
    });
})();

// header fijo: aparece cuando el hero deja de ser visible.
(function scrollHeaderControl() {
    var hero = document.querySelector('.hero');
    var header = document.getElementById('scroll-header');
    if (!hero || !header) return;

    function setVisible(visible) {
        header.classList.toggle('visible', visible);
    }

    if ('IntersectionObserver' in window) {
        var ob = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                setVisible(!entry.isIntersecting);
            });
        }, { threshold: 0.08, rootMargin: '-74px 0px 0px 0px' });
        ob.observe(hero);
    } else {
        function onScroll() {
            setVisible(window.scrollY > Math.max(240, hero.offsetHeight * 0.72));
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }
})();

// fondo animado eterno: carga DIFERIDA + bucle + check movil.
// en movil no cargamos el iframe para no machacar CPU/bateria.
// en escritorio lo cargamos 800ms despues del load para no bloquear LCP.
(function fondoEterno() {
    if (window.innerWidth <= 768) return;

    const bg = document.getElementById('bg-eterno');
    if (!bg || !bg.dataset.src) return;

    function arrancar() {
        bg.src = bg.dataset.src;
    }

    if (document.readyState === 'complete') {
        setTimeout(arrancar, 800);
    } else {
        window.addEventListener('load', function() { setTimeout(arrancar, 800); });
    }
})();
</script>
</body>
</html>
