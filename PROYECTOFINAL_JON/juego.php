<?php
// juego.php — runaworld
// la pagina principal del juego, donde pasa todo. el que este leyendo esto
// que se prepare porque hay mucha cosa. basicamente hace:
//   1) carga los datos del jugador desde la base de datos
//   2) calcula probabilidades con la curva campana segun la suerte
//   3) dibuja el html del juego entero en una sola pagina (es larguisimo si)
//   4) le pasa todo a js en el objeto RW_INIT al final
//
// indice por orden de aparicion:
//   1. autenticacion y carga del jugador
//   2. bonus de grupo ya conseguidos (afectan la suerte)
//   3. bulk total (masa, cuantas runas tira el jugador por cada coin)
//   4. probabilidades con curva campana (ver calcular_pesos.php)
//   5. mejoras del jugador y calculo de stats reales
//   6. coleccion de runas del jugador
//   7. boosts flotantes (los que salen cada X segundos)
//   8. html del juego (sidebar, tirada, tienda, coleccion, ajustes)
//   9. bloque RW_INIT — el puente de datos entre php y js
//
// lenguaje interno para los poco entendidos (va por vosotros profesores,
// estoy loco pero quiero ayudaros):
//   $conexion            = conexion a la base de datos (viene de conexion.php)
//   bulk / masa          = runas por tirada, a mas bulk mas runas por coin
//   suerte real          = la suerte que se usa para sortear, formula (a+b)*c*d:
//                            a = 1 (base del jugador, fija)
//                            b = suma de mejoras de suerte compradas en la tienda
//                            c = multiplicador de boosts de suerte activos
//                            d = multiplicador por completar colecciones enteras
//   campana              = curva de probabilidad por rareza. a mas suerte,
//                          suben las rarezas altas y caen las comunes (no se si
//                          matematicamente es una campana pero suena guay)
//   probmap              = mapa id_runa -> {base%, con_suerte%, peso}
//   rw_init              = objeto global window.RW_INIT que ve js al cargar
//
// empece este archivo el 28 de febrero. hoy es 10 de abril y sigue vivo,
// aun que no se cuanto mas. !hi al que este leyendo esto

session_start();

// si no esta logueado te vas al login y punto
if (!isset($_SESSION["idUsuario"])) {
    header("Location: index.php");
    exit;
}

require_once "PHP/conexion.php";

$id_usuario = $_SESSION["idUsuario"];

// cargar datos basicos del jugador
// 27/04 v3: sistema de suerte eliminado, ya no leemos columna suerte ni display_mode
$stmt = $conexion->prepare("
    SELECT coins, points, coins_por_seg, points_por_seg, coins_ps_max, points_ps_max
    FROM jugadores WHERE usuario_id = ?
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$jugador = $stmt->get_result()->fetch_assoc();
$stmt->close();

$coins        = $jugador["coins"]          ?? 0;
$points       = $jugador["points"]         ?? 0;
$coins_ps     = $jugador["coins_por_seg"]  ?? 1;
$points_ps    = $jugador["points_por_seg"] ?? 0;
$coins_ps_max = $jugador["coins_ps_max"]   ?? $coins_ps;
$points_ps_max= $jugador["points_ps_max"]  ?? $points_ps;

// bulk total del jugador. bulk = masa = cuantas runas lanza por cada coin
// gastada. la mejora "tiradas multiples" suma +1 bulk por nivel. arranca en 1
$stmt = $conexion->prepare("
    SELECT COALESCE(SUM(m.valor * jm.cantidad), 0) as bulk_total
    FROM jugador_mejoras jm
    INNER JOIN mejoras m ON jm.mejora_id = m.id
    WHERE jm.jugador_id = (SELECT id FROM jugadores WHERE usuario_id = ?)
      AND m.tipo = 'bulk' AND m.activa = 1
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$bulk_row   = $stmt->get_result()->fetch_assoc();
$stmt->close();
$bulk_total = 1 + (int)($bulk_row["bulk_total"] ?? 0);

// probabilidades por cascada (sin suerte)
// 27/04 v3: ya no hay curva campana, las probabilidades son fijas por rareza.
// se cargan desde rarezas.denominador y se calculan en cascada:
//   prob(rareza_N) = (1 - 1/d1) * (1 - 1/d2) * ... * (1/dN)
// la rareza con denominador 1 (comun) se queda con lo que sobre.

$stmt = $conexion->prepare("
    SELECT slug, denominador FROM rarezas
    WHERE activa = 1
    ORDER BY denominador DESC
");
$stmt->execute();
$rarezas_db = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// calcular probabilidad de cada rareza siguiendo la cascada
$prob_rareza_pct = [];
$running = 1.0;
foreach ($rarezas_db as $r) {
    $denom = (int)$r["denominador"];
    if ($denom <= 1) {
        // fallback: se lleva lo que quede
        $prob_rareza_pct[$r["slug"]] = $running * 100;
        $running = 0.0;
    } else {
        $hit = 1.0 / $denom;
        $prob_rareza_pct[$r["slug"]] = $running * $hit * 100;
        $running *= (1.0 - $hit);
    }
}

// lista completa de runas activas (para el panel de probabilidades)
$stmt = $conexion->prepare("
    SELECT r.id, r.nombre, r.rareza,
           COALESCE(g.nombre, 'Sin grupo') as grupo_nombre
    FROM runas r
    LEFT JOIN grupos_runas g ON r.grupo_id = g.id
    WHERE r.activa = 1
    ORDER BY g.id ASC, FIELD(r.rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')
");
$stmt->execute();
$todas_runas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// contar cuantas runas hay por rareza para repartir la prob entre ellas
$counts_rareza = [];
foreach ($todas_runas as $r) {
    $counts_rareza[$r["rareza"]] = ($counts_rareza[$r["rareza"]] ?? 0) + 1;
}

// runas que tiene el jugador ahora mismo (solo las que tiene alguna cantidad)
$stmt = $conexion->prepare("
    SELECT r.id, r.nombre, r.rareza, r.grupo_id, jr.cantidad, g.nombre as grupo_nombre
    FROM jugador_runas jr
    INNER JOIN runas r ON jr.runa_id = r.id
    INNER JOIN jugadores j ON jr.jugador_id = j.id
    LEFT JOIN grupos_runas g ON r.grupo_id = g.id
    WHERE j.usuario_id = ?
    ORDER BY g.id ASC, FIELD(r.rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$mis_runas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// las agrupo por grupo (oh que sorpresa) para el sidebar derecho
$runas_agrupadas = [];
foreach ($mis_runas as $runa_jugador) {
    $grupo = $runa_jugador["grupo_nombre"] ?? "Sin grupo";
    $runas_agrupadas[$grupo][] = $runa_jugador;
}

// mejoras compradas por el jugador (niveles incluidos)
$stmt = $conexion->prepare("
    SELECT m.*, COALESCE(jm.cantidad, 0) as nivel_actual
    FROM mejoras m
    LEFT JOIN jugador_mejoras jm ON m.id = jm.mejora_id AND jm.jugador_id = (
        SELECT id FROM jugadores WHERE usuario_id = ?
    )
    WHERE m.activa = 1
    ORDER BY m.tipo ASC, m.coste_base ASC
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$mejoras = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Calcular bloqueos de mejoras para inyectarlos en RW_INIT ──
// (se hace AQUI, antes del $conexion->close() de mas abajo, porque
// usamos el cursor para sacar stats y comprobar la coleccion).
// el server evalua las condiciones (coleccion_basica, tirar_runa_x,
// tirar_rareza, comprar_mejora_id) y marca cada mejora como bloqueada.
// tienda.js solo pinta lo que recibe, asi un cliente no puede saltarse
// una condicion editando el DOM (la validacion final esta tambien en
// comprar_mejora.php). aqui solo es para que la UI muestre el estado
// correcto sin un round-trip extra al server

// resolver jugador_id desde usuario_id (el resto del archivo no lo cachea
// en una variable, hace el JOIN cada vez. aqui necesito el id "seco")
$stmt = $conexion->prepare("SELECT id FROM jugadores WHERE usuario_id = ?");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$jugador_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$jugador_id = (int)($jugador_row["id"] ?? 0);

// stats del jugador (necesitamos total_tiradas y los counters de rareza)
$stmt = $conexion->prepare("SELECT * FROM jugador_stats WHERE jugador_id = ?");
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$jugador_stats_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$total_tiradas      = (int)($jugador_stats_row["total_tiradas"]      ?? 0);
$total_eternas      = (int)($jugador_stats_row["total_eternas"]      ?? 0);
$total_divinas      = (int)($jugador_stats_row["total_divinas"]      ?? 0);
$total_miticas      = (int)($jugador_stats_row["total_miticas"]      ?? 0);
$total_legendarias  = (int)($jugador_stats_row["total_legendarias"]  ?? 0);
$boosts_clickados   = (int)($jugador_stats_row["boosts_clickados"]   ?? 0);  // 28/04 v3.1

// coleccion basica completa? cuento runas no especiales que tiene el jugador
$stmt = $conexion->prepare("
    SELECT
      (SELECT COUNT(*) FROM runas WHERE activa = 1
       AND rareza NOT IN ('eterna','divina','mitica','legendaria')) AS total_basicas,
      (SELECT COUNT(DISTINCT jr.runa_id) FROM jugador_runas jr
       INNER JOIN runas r ON r.id = jr.runa_id
       WHERE jr.jugador_id = ? AND jr.cantidad > 0
         AND r.rareza NOT IN ('eterna','divina','mitica','legendaria')
         AND r.activa = 1) AS poseidas
");
$stmt->bind_param("i", $jugador_id);
$stmt->execute();
$col_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$coleccion_basica_completa = $col_row
    && (int)$col_row["total_basicas"] > 0
    && (int)$col_row["poseidas"] >= (int)$col_row["total_basicas"];

// niveles del jugador en cada mejora_id (para condicion comprar_mejora_id)
$niveles_jugador = [];
foreach ($mejoras as $m) {
    $niveles_jugador[(int)$m["id"]] = (int)$m["nivel_actual"];
}

// helper que devuelve [bloqueada, texto_pista] para una mejora
$evaluar_desbloqueo = function($m) use (
    $total_tiradas, $total_eternas, $total_divinas, $total_miticas, $total_legendarias,
    $coleccion_basica_completa, $niveles_jugador, $boosts_clickados
) {
    $tipo  = $m["condicion_tipo"]  ?? "ninguna";
    $valor = $m["condicion_valor"] ?? null;
    if ($tipo === "ninguna" || $tipo === null || $tipo === "") {
        return [false, ""];
    }
    switch ($tipo) {
        case "coleccion_basica":
            return $coleccion_basica_completa
                ? [false, ""]
                : [true, "Completa la coleccion basica de runas"];
        case "tirar_runa_x":
            $minimo = (int)$valor;
            return $total_tiradas >= $minimo
                ? [false, ""]
                : [true, "Tira " . number_format($minimo, 0, ',', '.') . " runas (" . $total_tiradas . "/" . $minimo . ")"];
        case "tirar_rareza":
            $rareza = strtolower($valor);
            $cnt = 0;
            if     ($rareza === "eterna")      $cnt = $total_eternas;
            elseif ($rareza === "divina")      $cnt = $total_divinas;
            elseif ($rareza === "mitica")      $cnt = $total_miticas;
            elseif ($rareza === "legendaria")  $cnt = $total_legendarias;
            return $cnt >= 1
                ? [false, ""]
                : [true, "Consigue una runa " . $rareza];
        case "comprar_mejora_id":
            $req_id = (int)$valor;
            $tiene  = ($niveles_jugador[$req_id] ?? 0) >= 1;
            return $tiene
                ? [false, ""]
                : [true, "Requiere otra mejora previa"];
        // 28/04 v3.1: nuevo caso. se desbloquea cuando el jugador ha
        // clickado >= condicion_valor boosts (contador en jugador_stats)
        case "clickar_boost_x":
            $minimo = (int)$valor;
            return $boosts_clickados >= $minimo
                ? [false, ""]
                : [true, "Clica " . $minimo . " boost" . ($minimo > 1 ? "s" : "") . " (" . $boosts_clickados . "/" . $minimo . ")"];
        default:
            return [true, "Bloqueada"];
    }
};

// pre-calcular para cada mejora el flag y el texto, para inyectarlos abajo
$mejoras_con_estado = array_map(function($m) use ($evaluar_desbloqueo) {
    list($bloq, $texto) = $evaluar_desbloqueo($m);
    $m["bloqueada"]        = $bloq;
    $m["condicion_texto"]  = $texto;
    return $m;
}, $mejoras);

// calcular stats reales aplicando las mejoras
// 27/04 v3: casos de suerte eliminados, las mejoras de suerte ya no existen
// formulas:
//   coins_seg          -> acumulativo: 1+2+...+nivel
//   coins_seg_multi[_eterno]   -> 2^nivel
//   points_seg         -> lineal: valor * nivel (28/04 v3.1)
//   points_seg_multi[_eterno] -> 2^nivel
//   bulk / bulk_normal -> +nivel runas
//   bulk_extra         -> +valor runas si nivel>=1
$coins_add    = 0.0; $multi_coins  = 1.0;
$points_add   = 0.0; $multi_points = 1.0;
$bulk_extra   = 0;
foreach ($mejoras as $mejora) {
    $valor  = floatval($mejora["valor"]);
    $nivel  = (int)$mejora["nivel_actual"];
    if ($nivel <= 0) continue;
    switch ($mejora["tipo"]) {
        case "coins_seg":
            $coins_add += ($nivel * ($nivel + 1) / 2) * $valor;
            break;
        case "coins_seg_multi":
        case "coins_seg_multi_eterno":
            $multi_coins *= pow(2, $nivel);
            break;
        case "points_seg":
            // 28/04 v3.1: lineal (valor * nivel) en vez de geometrica
            // (valor * 10^(nivel-1)). la geometrica explotaba a millardos
            // en niveles altos, ahora cada nivel suma `valor` puntos/seg.
            $points_add += $valor * $nivel;
            break;
        case "points_seg_multi":
        case "points_seg_multi_eterno":
            $multi_points *= pow(2, $nivel);
            break;
        case "bulk":
        case "bulk_normal":
            // este lo lee tirar_runa.php directamente de la BD,
            // pero lo dejamos aqui por si algun panel quiere mostrar el bulk total
            break;
        case "bulk_extra":
            if ($nivel >= 1) $bulk_extra += (int)$valor;
            break;
    }
}
$coins_ps = (1.0 + $coins_add) * $multi_coins;

// points/seg tiene un extra: las runas que tiene el jugador dan points pasivos
// segun su multiplicador. esto se llama runas_pts_base
// (la base "cruda" sin mejoras aplicadas todavia)
$stmt_pts = $conexion->prepare("
    SELECT COALESCE(SUM(r.multiplicador * jr.cantidad), 0) as total
    FROM jugador_runas jr INNER JOIN runas r ON jr.runa_id = r.id
    WHERE jr.jugador_id = (SELECT id FROM jugadores WHERE usuario_id = ?)
");
$stmt_pts->bind_param("i", $id_usuario);
$stmt_pts->execute();
$runas_pts_base = floatval($stmt_pts->get_result()->fetch_assoc()["total"]);
$stmt_pts->close();
$points_ps = ($runas_pts_base + $points_add) * $multi_points;

// prob_map: probabilidad fija por runa
// 27/04 v3: cascada simple. dentro de cada rareza todas las runas tienen la
// misma prob, asi que dividimos la prob de la rareza entre el numero de runas
// que tiene esa rareza
$prob_map = [];
foreach ($todas_runas as $runa) {
    $rareza = $runa["rareza"];
    $count  = max(1, (int)($counts_rareza[$rareza] ?? 1));
    $pct    = ($prob_rareza_pct[$rareza] ?? 0) / $count;
    $prob_map[$runa["id"]] = [
        "prob" => $pct
    ];
}

// coleccion completa: todas las runas del juego con la cantidad que tiene
// el jugador (0 si no la ha desbloqueado). LEFT JOIN es mi mejor amigo
$stmt = $conexion->prepare("
    SELECT r.id, r.nombre, r.rareza, r.multiplicador, r.imagen,
           COALESCE(jr.cantidad, 0) as cantidad
    FROM runas r
    LEFT JOIN jugador_runas jr ON r.id = jr.runa_id
        AND jr.jugador_id = (SELECT id FROM jugadores WHERE usuario_id = ?)
    WHERE r.activa = 1
    ORDER BY FIELD(r.rareza, 'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$runas_coleccion = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// contador estilo "4/20" en la parte de coleccion (no es lo que piensas)
$total_runas       = count($runas_coleccion);
$desbloqueadas_num = count(array_filter($runas_coleccion, fn($r) => $r["cantidad"] > 0));

// las especiales van arriba con sus botones fancy, las comunes abajo
// (legendaria y mitica son "especiales" porque tienen animacion propia)
$runas_especiales = array_values(array_filter($runas_coleccion, fn($r) => in_array($r["rareza"], ["eterna","divina","legendaria","mitica"])));
$runas_comunes    = array_values(array_filter($runas_coleccion, fn($r) => !in_array($r["rareza"], ["eterna","divina","legendaria","mitica"])));

// 27/04 v3: bloque de bonus_grupo eliminado, tabla dropeada en Fase 1

// tipos de boost activos (los que pueden salir en el juego ahora mismo)
$stmt = $conexion->prepare("SELECT * FROM boost_tipos WHERE activo = 1 ORDER BY peso DESC");
$stmt->execute();
$boost_tipos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// mejoras desbloqueadas por el jugador. lo necesito para filtrar los boosts
// legendario y divino que solo salen si compraste la mejora correspondiente
$stmt = $conexion->prepare("
    SELECT jm.mejora_id
    FROM jugador_mejoras jm
    INNER JOIN jugadores j ON jm.jugador_id = j.id
    WHERE j.usuario_id = ? AND jm.cantidad > 0
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$mejoras_desbloqueadas = array_map(
    fn($r) => (int)$r["mejora_id"],
    $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
);
$stmt->close();

// config de boosts (solo el intervalo por ahora). si algun dia quiero anadir mas
// configuraciones globales, aqui es donde las pondria
$stmt = $conexion->prepare("SELECT clave, valor FROM config_boosts");
$stmt->execute();
$config_boosts_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$boost_intervalo = 30;
foreach ($config_boosts_raw as $item_config) {
    if ($item_config["clave"] === "intervalo_seg") $boost_intervalo = (int)$item_config["valor"];
}

// runas que tengo agrupadas por grupo y rareza, para el menu de estadisticas
// ej: ["Runas Basicas" => ["eterna" => 3, "divina" => 16, ...], ...]
// si la rareza no aparece es que no tengo ninguna de esa rareza en ese grupo
$stmt = $conexion->prepare("
    SELECT COALESCE(g.nombre, 'Sin grupo') as grupo_nombre,
           r.rareza,
           SUM(jr.cantidad) as total
    FROM jugador_runas jr
    INNER JOIN runas r ON jr.runa_id = r.id
    LEFT JOIN grupos_runas g ON r.grupo_id = g.id
    WHERE jr.jugador_id = (SELECT id FROM jugadores WHERE usuario_id = ?)
      AND jr.cantidad > 0
    GROUP BY g.id, r.rareza
    ORDER BY g.id ASC, FIELD(r.rareza,'eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun')
");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$runas_por_grupo_rareza_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// lo reagrupo en una estructura mas comoda para el foreach del html
$stats_grupos = [];
$stats_total_runas = 0;
foreach ($runas_por_grupo_rareza_raw as $fila) {
    $g = $fila["grupo_nombre"];
    if (!isset($stats_grupos[$g])) $stats_grupos[$g] = [];
    $stats_grupos[$g][$fila["rareza"]] = (int)$fila["total"];
    $stats_total_runas += (int)$fila["total"];
}

$conexion->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>RunaWorld</title>
    <link rel="stylesheet" href="CSS/style.css">
    <link rel="stylesheet" href="CSS/style_phone.css">
    <link rel="stylesheet" href="CSS/rune_button.css">
    <link rel="stylesheet" href="CSS/stats_estadisticas.css">
    <link rel="stylesheet" href="CSS/tienda.css">
    <style>
        /* hover y seleccion de runas comunes en la coleccion */
        /* lo puse aqui inline porque style.css estaba creciendo mucho */
        .col-runa-comun.desbloqueada:hover,
        .col-runa-comun.sel {
            outline: 2px solid currentColor;
        }
        .col-runa-comun.poco_comun:hover, .col-runa-comun.poco_comun.sel { color: rgba(50,200,80,0.85); background: rgba(50,200,80,0.07); }
        .col-runa-comun.rara:hover,       .col-runa-comun.rara.sel       { color: rgba(60,140,255,0.85); background: rgba(60,140,255,0.07); }
        .col-runa-comun.epica:hover,      .col-runa-comun.epica.sel      { color: rgba(160,80,255,0.85); background: rgba(160,80,255,0.07); }
        .col-runa-comun.comun:hover,      .col-runa-comun.comun.sel      { color: rgba(160,160,160,0.7); background: rgba(160,160,160,0.07); }

        /* color morado-azul para la rareza eterna (la mas rara del juego por ahora) */
        .col-runa-btn.eterna { color: rgba(180,130,255,0.95); border-color: rgba(160,100,255,0.5); }
        .col-runa-btn.eterna:hover, .col-runa-btn.eterna.sel {
            background: rgba(140,90,255,0.1);
            box-shadow: 0 0 18px rgba(140,90,255,0.4);
        }
        .btn-ver-anim-eterna  { color: rgba(175,125,255,0.95); }
        .btn-ver-anim-eterna:hover { background: rgba(140,90,255,0.08); box-shadow: 0 0 20px rgba(150,100,255,0.5); }
        .btn-toggle-anim-eterna { color: rgba(175,125,255,0.95); border-color: rgba(150,100,255,0.3); }

        #col-iframe { display: block; }

        /* la coleccion necesita espacio extra para que los botones de
           "ver animacion" y "toggle anim" se vean sin hacer scroll */
        .seccion-coleccion-full {
            height: calc(100vh - 240px) !important;
        }
        .coleccion-canvas-wrap {
            min-height: 0 !important;
        }
        /* CSS del popup welcomePopup eliminado: el popup se quito porque
           ahora la presentacion va en index.php (homepage publica). las
           clases .jondrar-modal-*, .welcome-text, .neon-hr, .info-section,
           .info-list, .btn-access ya no se usan en ningun sitio */
    </style>
</head>
<body>

<!-- svg de la runa mitica, animacion especial 2/4 del grupo principiante -->
<!-- va suelto en el body porque se posiciona absoluto y no depende del layout -->
<svg id="mitica-runa-svg" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg"
     style="position:fixed; z-index:9500; pointer-events:none; opacity:0; transform-origin:center center;
            filter: drop-shadow(0 0 20px rgba(255,34,68,0.9)) drop-shadow(0 0 60px rgba(255,34,68,0.5));">
    <circle cx="200" cy="200" r="185" fill="none" stroke="#ff2244" stroke-width="1.5" opacity="0.9"/>
    <circle cx="200" cy="200" r="145" fill="none" stroke="#ff2244" stroke-width="0.8" opacity="0.7"/>
    <circle cx="200" cy="200" r="80"  fill="none" stroke="#ff2244" stroke-width="1.2" opacity="0.8"/>
    <g stroke="#ff2244" stroke-width="2.5" opacity="1" stroke-linecap="round">
        <line x1="200" y1="125" x2="200" y2="95"/><line x1="193" y1="110" x2="200" y2="95"/><line x1="207" y1="110" x2="200" y2="95"/>
        <line x1="200" y1="275" x2="200" y2="305"/><line x1="193" y1="290" x2="207" y2="290"/>
        <line x1="275" y1="200" x2="305" y2="200"/><line x1="290" y1="193" x2="305" y2="200"/><line x1="290" y1="207" x2="305" y2="200"/>
        <line x1="125" y1="200" x2="95"  y2="200"/><line x1="110" y1="193" x2="95"  y2="200"/><line x1="110" y1="207" x2="95"  y2="200"/>
        <line x1="254" y1="146" x2="275" y2="125"/><line x1="146" y1="146" x2="125" y2="125"/>
        <line x1="254" y1="254" x2="275" y2="275"/><line x1="146" y1="254" x2="125" y2="275"/>
    </g>
    <g stroke="#ff2244" stroke-width="2" opacity="0.9">
        <line x1="200" y1="140" x2="200" y2="260"/>
        <line x1="140" y1="200" x2="260" y2="200"/>
        <circle cx="200" cy="200" r="25" fill="none"/>
        <circle cx="200" cy="200" r="6" fill="#ff2244"/>
    </g>
</svg>

<!-- svg de la runa divina, animacion especial 3/4 del grupo principiante -->
<!-- la divina esta rota en su animacion, TODO: revisar por que cruz aparece mal -->
<svg id="divina-runa-svg" viewBox="0 0 1000 1000" xmlns="http://www.w3.org/2000/svg"
     style="position:fixed; z-index:99999; pointer-events:none; opacity:0; display:none;"
     color="#FFFACD">
    <defs>
        <radialGradient id="divGlow" cx="50%" cy="50%" r="50%">
            <stop offset="0%"   stop-color="#fff"    stop-opacity="1"/>
            <stop offset="50%"  stop-color="#FFFACD" stop-opacity="0.4"/>
            <stop offset="100%" stop-color="#FFFFF0" stop-opacity="0"/>
        </radialGradient>
        <filter id="divFilter">
            <feGaussianBlur stdDeviation="10" result="b"/>
            <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
        </filter>
    </defs>
    <circle cx="500" cy="500" r="380" fill="url(#divGlow)" opacity="0.5" filter="url(#divFilter)"/>
    <g stroke="currentColor" stroke-width="2" fill="none" opacity="0.8">
        <circle cx="500" cy="500" r="460"/>
        <circle cx="500" cy="500" r="380"/>
        <circle cx="500" cy="500" r="280"/>
        <path d="M500 120 L840 320 V680 L500 880 L160 680 V320 Z"/>
        <line x1="500" y1="40"  x2="500" y2="960"/>
        <line x1="40"  y1="500" x2="960" y2="500"/>
    </g>
    <g transform="translate(500,500)" filter="url(#divFilter)">
        <rect x="-12" y="-200" width="24" height="400" rx="12" fill="white"/>
        <rect x="-130" y="-65" width="260" height="24" rx="12" fill="white"/>
        <circle cx="0" cy="-60" r="35" fill="#FFFACD"/>
        <circle cx="0" cy="-60" r="15" fill="white"/>
    </g>
</svg>

<!-- destello rojo que uso para el critico de la mitica. opacity 0 por defecto,
     lo enciende animaciones.js cuando toca la mitica -->
<div id="flash-rojo" style="position:fixed; inset:0; background:#ff0022; z-index:9000; opacity:0; pointer-events:none;"></div>

<!-- animacion de intro al entrar al juego. un iframe con la onda que sube
     y luego se autodestruye a los 3.6s. si aburre mucho la animacion esta
     se la quito -->
<iframe id="intro-iframe"
    src="ANIMACIONES_HTML/intro.html"
    style="position:fixed;inset:0;width:100%;height:100%;border:none;z-index:99999;pointer-events:none;"
    onload="setTimeout(()=>{this.remove()},3600)">
</iframe>

<!-- la runa flotante del boost, la que aparece cada X segundos en pantalla
     y si la pillas te da un multiplicador. va fuera del flujo normal porque
     se posiciona en coordenadas random de la pantalla. la logica esta en boosts.js -->
<div id="runa-flotante" style="display:none; position:fixed; width:90px; height:90px; z-index:9700; cursor:pointer; transform:translate(-50%,-50%) scale(0.4);" onclick="clickarRunaFlotante()">
    <svg viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg" id="runa-flotante-svg" style="width:100%;height:100%;color:#a050ff;">
        <circle cx="200" cy="200" r="185" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.9"/>
        <circle cx="200" cy="200" r="80"  fill="none" stroke="currentColor" stroke-width="1.2" opacity="0.8"/>
        <g stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="200" y1="125" x2="200" y2="95"/><line x1="193" y1="110" x2="200" y2="95"/><line x1="207" y1="110" x2="200" y2="95"/>
            <line x1="200" y1="275" x2="200" y2="305"/><line x1="193" y1="290" x2="207" y2="290"/>
            <line x1="275" y1="200" x2="305" y2="200"/><line x1="290" y1="193" x2="305" y2="200"/><line x1="290" y1="207" x2="305" y2="200"/>
            <line x1="125" y1="200" x2="95"  y2="200"/><line x1="110" y1="193" x2="95"  y2="200"/><line x1="110" y1="207" x2="95"  y2="200"/>
            <line x1="254" y1="146" x2="275" y2="125"/><line x1="146" y1="146" x2="125" y2="125"/>
            <line x1="254" y1="254" x2="275" y2="275"/><line x1="146" y1="254" x2="125" y2="275"/>
        </g>
        <g stroke="currentColor" stroke-width="2">
            <line x1="200" y1="140" x2="200" y2="260"/>
            <line x1="140" y1="200" x2="260" y2="200"/>
            <circle cx="200" cy="200" r="25" fill="none"/>
            <circle cx="200" cy="200" r="6" fill="currentColor"/>
        </g>
    </svg>
    <div id="runa-flotante-label" style="position:absolute;bottom:-22px;left:50%;transform:translateX(-50%);font-family:'Oswald',sans-serif;font-size:0.6rem;letter-spacing:2px;text-transform:uppercase;color:#a050ff;white-space:nowrap;text-shadow:0 0 8px #a050ff;"></div>
</div>

<!-- zona donde iban las notificaciones de boosts activos. ahora mismo
     esta vacia pero dejo el contenedor por si vuelvo a meter algo. ver boosts.js -->
<div id="zona-boosts" style="position:fixed;left:calc(var(--sidebar-w) + 16px);top:50%;transform:translateY(-50%);width:200px;display:flex;flex-direction:column;gap:8px;z-index:50;pointer-events:none;">
    <div id="boosts-activos" style="display:flex;flex-direction:column;gap:8px;pointer-events:all;"></div>
</div>

<!-- neon que rodea la pantalla cuando sale una legendaria. 4 lineas en los
     bordes, la animacion se enciende en animaciones.js -->
<div class="neon-overlay" id="neon-overlay">
    <div class="neon-line neon-top"></div>
    <div class="neon-line neon-bottom"></div>
    <div class="neon-line neon-left"></div>
    <div class="neon-line neon-right"></div>
</div>

<!-- ventana emergente para borrar el progreso. pide escribir "estoy seguro"
     para que no lo hagas sin querer. no, no hay undo. llora si le das -->
<div id="modal-borrar">
    <div id="modal-contenido">
        <h3>Borrar Progreso</h3>
        <p>Esta accion eliminara todas tus runas, monedas y puntos permanentemente.</p>
        <p>Escribe <strong>estoy seguro</strong> para confirmar:</p>
        <input type="text" id="input-confirmacion" placeholder="estoy seguro" autocomplete="off">
        <p id="msg-confirmacion"></p>
        <div class="modal-btns">
            <button class="btn-confirmar-borrar" onclick="confirmarBorrado()">Confirmar</button>
            <button class="btn-cancelar" onclick="cerrarModal()">Cancelar</button>
        </div>
    </div>
</div>

<!-- popup de bienvenida eliminado: ahora la presentacion del juego va en
     index.php (la homepage publica). los visitantes nuevos llegan ahi
     primero y deciden si entrar a jugar; quien ya esta dentro no necesita
     que se le repita al cargar juego.php -->

<!-- titulo del juego + saludo al jugador. el saludo viene de la sesion -->
<div id="header">
    <h1>RunaWorld</h1>
    <p class="bienvenido">Bienvenido, <strong><?= htmlspecialchars($_SESSION["username"]) ?></strong></p>
</div>

<!-- contenedor general: a la izquierda sidebar de stats, en medio la zona
     principal que cambia segun que menu este activo (tirada/tienda/coleccion/ajustes),
     a la derecha panel de mis runas o stats de coleccion -->
<div id="layout">

    <aside id="sidebar">
        <div class="stat-block">
            <svg class="stat-icon" viewBox="0 0 40 40" fill="none">
                <circle cx="20" cy="20" r="18" stroke="#ffd700" stroke-width="1.5" opacity="0.5"/>
                <circle cx="20" cy="20" r="11" stroke="#ffd700" stroke-width="1" opacity="0.8"/>
                <text x="20" y="25" text-anchor="middle" fill="#ffd700" font-size="12" font-weight="bold" font-family="Oswald">C</text>
            </svg>
            <div class="stat-info">
                <span class="stat-value gold"   id="coins-display"><?= number_format($coins, 0) ?></span>
                <span class="stat-rate"          id="coins-ps-display">+<?= number_format($coins_ps, 2) ?>/seg</span>
            </div>
        </div>

        <div class="stat-block">
            <svg class="stat-icon" viewBox="0 0 40 40" fill="none">
                <polygon points="20,3 24,15 37,15 26,23 30,35 20,27 10,35 14,23 3,15 16,15" stroke="#dde4f0" stroke-width="1.5" fill="none" opacity="0.6"/>
                <polygon points="20,9 23,17 31,17 25,22 27,30 20,26 13,30 15,22 9,17 17,17" fill="#dde4f0" opacity="0.1"/>
            </svg>
            <div class="stat-info">
                <span class="stat-value silver" id="points-display"><?= number_format($points, 0) ?></span>
                <span class="stat-rate"          id="points-ps-display">+<?= number_format($points_ps, 2) ?>/seg</span>
            </div>
        </div>

        <div class="stat-block">
            <svg class="stat-icon" viewBox="0 0 40 40" fill="none">
                <rect x="6" y="14" width="8" height="16" stroke="#dde4f0" stroke-width="1.2" opacity="0.6" rx="1"/>
                <rect x="16" y="10" width="8" height="20" stroke="#dde4f0" stroke-width="1.2" opacity="0.8" rx="1"/>
                <rect x="26" y="6" width="8" height="24" stroke="#ffd700" stroke-width="1.2" opacity="0.9" rx="1"/>
            </svg>
            <div class="stat-info">
                <span class="stat-value silver" id="bulk-display"><?= $bulk_total ?> runa<?= $bulk_total > 1 ? 's' : '' ?></span>
                <span class="stat-rate">por tirada</span>
            </div>
        </div>

        <div class="sidebar-divider"></div>

        <nav class="nav-menu">
            <button class="nav-btn active" onclick="mostrarSeccion('tirada', this)">
                <span class="nav-icon">⬡</span> Tirar Runa
            </button>
            <button class="nav-btn" onclick="mostrarSeccion('tienda', this)">
                <span class="nav-icon">◈</span> Tienda
            </button>
            <button class="nav-btn" onclick="mostrarSeccion('coleccion', this)">
                <span class="nav-icon">◎</span> Coleccion
            </button>
            <button class="nav-btn" onclick="mostrarSeccion('estadisticas', this)">
                <span class="nav-icon">✦</span> Estadisticas
            </button>
            <div class="sidebar-divider"></div>
            <button class="nav-btn" onclick="mostrarSeccion('ajustes', this)">
                <span class="nav-icon">⚙</span> Ajustes
            </button>
            <div class="sidebar-divider"></div>
            <button class="nav-btn danger" onclick="window.location='PHP/logout.php'">
                <span class="nav-icon">→</span> Cerrar Sesion
            </button>
        </nav>
    </aside>

    <main id="centro">

        <!-- menu 1: tirar runa -->
        <!-- la zona mas importante del juego. el boton + los resultados de la tirada
             + un boton desbloqueado (WIP) para cuando tenga mas listas de runas -->
        <div id="seccion-tirada" class="seccion activa">
            <div id="zona-tirada">
                <span class="tirada-coste">1 coin por tirada</span>

                <!-- el gran boton de tirada. id y onclick van intactos para
                     que animaciones.js y tirada.js sigan funcionando igual.
                     el mandala con runas girando invita a hacer click.
                     estructura:
                       - anillo externo rotando (35s)
                       - anillo interno reverse (18s)
                       - circulo central (core) con pulso de energia
                       - orbita de 16 runas vikingas girando en medio
                       - simbolo central grande (runa raidho) con hint pulsante
                     todos los SVG son pointer-events none para que el click
                     siempre le llegue al boton, no a los decorados -->
                <button id="btn-tirar" class="rune-btn" onclick="tirarRuna()" aria-label="Lanzar runa">

                    <!-- anillo externo: circulos + lineas cardinales + arcos + triangulos. vegvisir vibes -->
                    <svg class="rune-mandala-out" viewBox="0 0 302 302" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="151" cy="151" r="146" stroke="rgba(255,215,0,.16)" stroke-width=".8"/>
                        <circle cx="151" cy="151" r="122" stroke="rgba(255,215,0,.09)" stroke-width=".5"/>
                        <g stroke="rgba(255,215,0,.2)" stroke-width=".7">
                            <line x1="151" y1="5"   x2="151" y2="50"/>
                            <line x1="151" y1="252" x2="151" y2="297"/>
                            <line x1="5"   y1="151" x2="50"  y2="151"/>
                            <line x1="252" y1="151" x2="297" y2="151"/>
                            <line x1="40"  y1="40"  x2="72"  y2="72"/>
                            <line x1="230" y1="230" x2="262" y2="262"/>
                            <line x1="262" y1="40"  x2="230" y2="72"/>
                            <line x1="40"  y1="262" x2="72"  y2="230"/>
                        </g>
                        <path d="M151 29 A122 122 0 0 1 273 151" stroke="rgba(255,215,0,.2)" stroke-width=".8" fill="none"/>
                        <path d="M273 151 A122 122 0 0 1 151 273" stroke="rgba(255,215,0,.2)" stroke-width=".8" fill="none"/>
                        <path d="M151 273 A122 122 0 0 1 29 151"  stroke="rgba(255,215,0,.2)" stroke-width=".8" fill="none"/>
                        <path d="M29 151  A122 122 0 0 1 151 29"  stroke="rgba(255,215,0,.2)" stroke-width=".8" fill="none"/>
                        <circle cx="151" cy="5"   r="2" fill="rgba(255,215,0,.45)"/>
                        <circle cx="151" cy="297" r="2" fill="rgba(255,215,0,.45)"/>
                        <circle cx="5"   cy="151" r="2" fill="rgba(255,215,0,.45)"/>
                        <circle cx="297" cy="151" r="2" fill="rgba(255,215,0,.45)"/>
                        <circle cx="40"  cy="40"  r="1.5" fill="rgba(255,215,0,.3)"/>
                        <circle cx="262" cy="262" r="1.5" fill="rgba(255,215,0,.3)"/>
                        <circle cx="262" cy="40"  r="1.5" fill="rgba(255,215,0,.3)"/>
                        <circle cx="40"  cy="262" r="1.5" fill="rgba(255,215,0,.3)"/>
                        <polygon points="151,12 158,26 151,23 144,26"   fill="rgba(255,215,0,.3)"/>
                        <polygon points="151,290 158,276 151,279 144,276" fill="rgba(255,215,0,.3)"/>
                        <polygon points="12,151 26,144 23,151 26,158"   fill="rgba(255,215,0,.3)"/>
                        <polygon points="290,151 276,144 279,151 276,158" fill="rgba(255,215,0,.3)"/>
                    </svg>

                    <!-- anillo interno girando al reves con trazos discontinuos -->
                    <svg class="rune-mandala-in" viewBox="0 0 250 250" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="125" cy="125" r="119" stroke="rgba(255,215,0,.1)"  stroke-width=".5" stroke-dasharray="3 7"/>
                        <circle cx="125" cy="125" r="96"  stroke="rgba(255,215,0,.07)" stroke-width=".5"/>
                        <g stroke="rgba(255,215,0,.14)" stroke-width=".5">
                            <line x1="125" y1="6"   x2="125" y2="30"/>
                            <line x1="125" y1="220" x2="125" y2="244"/>
                            <line x1="6"   y1="125" x2="30"  y2="125"/>
                            <line x1="220" y1="125" x2="244" y2="125"/>
                        </g>
                    </svg>

                    <!-- circulo dorado central con el pulso de energia dentro -->
                    <span class="rune-core">
                        <span class="rune-energy"></span>
                    </span>

                    <!-- orbita de 16 runas vikingas. las pinto en php para no
                         depender de un script extra. el mismo set que uso en otros
                         sitios del juego (futhark antiguo) -->
                    <span class="rune-orbit">
                        <?php
                        $orbit_runas = ['ᚠ','ᚢ','ᚦ','ᚨ','ᚱ','ᚲ','ᚷ','ᚹ','ᚺ','ᚾ','ᛁ','ᛃ','ᛇ','ᛈ','ᛉ','ᛊ'];
                        foreach ($orbit_runas as $i => $r):
                            $angulo = $i * 22.5;
                        ?>
                            <span style="transform: rotate(<?= $angulo ?>deg) translateY(-98px) rotate(-<?= $angulo ?>deg)"><?= $r ?></span>
                        <?php endforeach; ?>
                    </span>

                    <!-- simbolo central grande. la runa raidho (ᚱ) que significa
                         "viaje", me parecio tematico para un boton de "tirar" -->
                    <span class="rune-symbol">ᚱ</span>

                    <!-- hint visual: anillo pulsante que grita "clickeame" al novato.
                         lo pongo por fuera del core para que no tape el pulso interno -->
                    <span class="rune-click-hint"></span>
                </button>

                <div id="resultado-especial"></div>
                <div id="resultado-tirada"></div>
                <button id="btn-desbloquear" disabled title="Proximamente">
                    ⬡ Desbloquear nueva lista de runas — 1M pts
                </button>
            </div>
        </div>

        <!-- menu 2: tienda -->
        <!-- las mejoras compradas con points (no coins). el HTML lo monta
             tienda.js dinamicamente leyendo window.RW_INIT.mejoras_completas
             (ver final de este archivo). estilo y logica viven en JS/tienda.js
             y CSS/tienda.css respectivamente. aqui solo dejamos el contenedor
             vacio con titulo y zona de mensajes -->
        <div id="seccion-tienda" class="seccion">
            <div class="seccion-titulo">Tienda de Mejoras</div>
            <p id="msg-tienda"></p>
            <!-- las filas de mejoras se inyectan aqui en runtime por tienda.js -->
        </div>

        <!-- menu 3: coleccion -->
        <!-- muestra todas las runas del juego separadas en especiales (animacion propia)
             y comunes (solo card). al seleccionar una runa desbloqueada carga un
             iframe central con la animacion en bucle y sus stats -->
        <div id="seccion-coleccion" class="seccion seccion-coleccion-full">
            <div class="coleccion-layout">

                <!-- columna izquierda: lista de runas separadas en especiales y comunes -->
                <div class="coleccion-columna-lista">

                    <!-- contador "X/Y runas desbloqueadas" arriba del todo -->
                    <div class="col-contador">
                        <span class="col-contador-label">Colección</span>
                        <span class="col-contador-num">
                            <?= $desbloqueadas_num ?><span class="col-contador-sep">/</span><?= $total_runas ?>
                            <span class="col-contador-txt">runas</span>
                        </span>
                    </div>

                    <!-- runas especiales (legendaria/mitica/divina/eterna) -->
                    <!-- estas son las que tienen animaciones propias y neon -->
                    <div class="col-seccion-header">Especiales</div>
                    <?php foreach ($runas_especiales as $runa_col):
                        $desbloqueada = $runa_col["cantidad"] > 0;
                        $cls_rareza   = htmlspecialchars($runa_col["rareza"]);
                        $cls_extra    = $desbloqueada ? "desbloqueada " . $cls_rareza : "bloqueada";
                    ?>
                    <div class="col-runa-btn col-especial <?= $cls_extra ?>"
                         data-id="<?= $runa_col["id"] ?>"
                         data-rareza="<?= $cls_rareza ?>"
                         data-nombre="<?= htmlspecialchars($runa_col["nombre"]) ?>"
                         data-multiplicador="<?= $runa_col["multiplicador"] ?>"
                         data-cantidad="<?= $runa_col["cantidad"] ?>"
                         data-imagen="<?= htmlspecialchars($runa_col["imagen"] ?? "") ?>"
                         <?= $desbloqueada ? 'onclick="seleccionarRunaCol(this)"' : '' ?>>
                        <?php if ($desbloqueada): ?>
                            <canvas class="col-btn-neon" data-rareza="<?= $cls_rareza ?>"></canvas>
                        <?php endif; ?>
                        <span class="col-runa-nombre"><?= htmlspecialchars($runa_col["nombre"]) ?></span>
                        <span class="col-runa-right">
                            <?php if ($desbloqueada): ?>
                                <span class="col-runa-cantidad">x<?= number_format($runa_col["cantidad"]) ?></span>
                            <?php else: ?>
                                <span class="col-candado">🔒</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>

                    <!-- runas comunes (epica/rara/poco_comun/comun) -->
                    <!-- estas son mas pequenas, con un canvas mini para la animacion en bucle -->
                    <div class="col-seccion-header" style="margin-top:14px;">Comunes</div>
                    <?php foreach ($runas_comunes as $runa_col):
                        $desbloqueada = $runa_col["cantidad"] > 0;
                        $cls_rareza   = htmlspecialchars($runa_col["rareza"]);
                        $cls_extra    = $desbloqueada ? "desbloqueada " . $cls_rareza : "bloqueada";
                    ?>
                    <div class="col-runa-comun <?= $cls_extra ?>"
                         data-id="<?= $runa_col["id"] ?>"
                         data-rareza="<?= $cls_rareza ?>"
                         data-nombre="<?= htmlspecialchars($runa_col["nombre"]) ?>"
                         data-multiplicador="<?= $runa_col["multiplicador"] ?>"
                         data-cantidad="<?= $runa_col["cantidad"] ?>"
                         <?= $desbloqueada ? 'onclick="seleccionarRunaCol(this)"' : '' ?>>
                        <canvas class="col-comun-canvas" width="32" height="32" data-rareza="<?= $cls_rareza ?>" data-activa="<?= $desbloqueada ? '1' : '0' ?>"></canvas>
                        <div class="col-comun-info">
                            <span class="col-comun-nombre"><?= htmlspecialchars($runa_col["nombre"]) ?></span>
                            <span class="col-comun-rareza"><?= ucfirst(str_replace("_"," ",$runa_col["rareza"])) ?></span>
                        </div>
                        <span class="col-runa-right">
                            <?php if ($desbloqueada): ?>
                                <span class="col-runa-cantidad">x<?= number_format($runa_col["cantidad"]) ?></span>
                            <?php else: ?>
                                <span class="col-candado">🔒</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>

                </div>

                <!-- zona central: aqui se carga un iframe con la animacion de la runa
                     seleccionada en bucle + botones para ver la animacion completa
                     (la de cuando te toca en una tirada) y toggle on/off -->
                <div class="coleccion-centro">
                    <div class="coleccion-canvas-wrap">
                        <iframe id="col-iframe"
                                src="about:blank"
                                style="width:100%;height:100%;border:none;background:transparent;"
                                allowtransparency="true">
                        </iframe>
                        <div class="col-canvas-hint" id="col-canvas-hint">Selecciona una runa</div>
                    </div>
                    <div id="btn-anim-row" style="display:none; width:100%;">
                        <button id="btn-ver-anim" class="btn-ver-anim" onclick="verAnimacionCompleta()">
                            ▶ Ver animación completa
                        </button>
                        <button id="btn-toggle-anim" class="btn-toggle-anim" onclick="toggleAnimaciones()">
                            <span id="btn-toggle-anim-txt">ANIM ON</span>
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- menu 4: estadisticas -->
        <!-- tabla de datos del jugador, ahora mismo solo informacion, pero en un
             futuro aqui ira tambien la lista de logros (los achievements).
             los valores actuales (coins/points/etc) se actualizan cada segundo
             con js, los totales historicos se recalculan al entrar al menu -->
        <div id="seccion-estadisticas" class="seccion">
            <div class="seccion-titulo">Estadisticas</div>

            <!-- estadisticas generales del jugador. se actualizan en vivo desde js -->
            <div class="stats-bloque">
                <div class="stats-bloque-titulo">General</div>
                <div class="stats-tabla">
                    <div class="stats-fila">
                        <span class="stats-label">Coins actuales</span>
                        <span class="stats-valor" id="stats-coins-actual">—</span>
                    </div>
                    <div class="stats-fila">
                        <span class="stats-label">Coins por segundo</span>
                        <span class="stats-valor" id="stats-coins-ps">—</span>
                    </div>
                    <div class="stats-fila">
                        <span class="stats-label">Points actuales</span>
                        <span class="stats-valor" id="stats-points-actual">—</span>
                    </div>
                    <div class="stats-fila">
                        <span class="stats-label">Points por segundo</span>
                        <span class="stats-valor" id="stats-points-ps">—</span>
                    </div>
                    <div class="stats-fila">
                        <span class="stats-label">Runas por tirada (bulk)</span>
                        <span class="stats-valor" id="stats-bulk">—</span>
                    </div>
                </div>
            </div>

            <!-- runas abiertas en total (suma de toda la coleccion del jugador).
                 este dato SI viene de la base de datos y se recarga al entrar -->
            <div class="stats-bloque">
                <div class="stats-bloque-titulo">Runas abiertas</div>
                <div class="stats-tabla">
                    <div class="stats-fila stats-fila-destacada">
                        <span class="stats-label">Total runas obtenidas</span>
                        <span class="stats-valor"><?= number_format($stats_total_runas) ?></span>
                    </div>
                </div>
            </div>

            <!-- desglose por grupo y rareza. aqui es donde el jugador ve
                 "de las runas basicas llevo 3 eternas, 16 divinas, etc..."
                 los colores son los mismos que uso en el resto del juego -->
            <?php if (!empty($stats_grupos)): ?>
                <?php foreach ($stats_grupos as $nombre_grupo => $rarezas_grupo): ?>
                    <div class="stats-bloque">
                        <div class="stats-bloque-titulo"><?= htmlspecialchars($nombre_grupo) ?></div>
                        <div class="stats-tabla">
                            <?php
                            // el orden de rarezas que uso en todo el juego, de mas rara a mas comun
                            $orden_rarezas = ['eterna','divina','mitica','legendaria','epica','rara','poco_comun','comun'];
                            foreach ($orden_rarezas as $rareza_clave):
                                // si esa rareza no existe en el grupo, la salto (no todos
                                // los grupos tienen todas las rarezas)
                                if (!isset($rarezas_grupo[$rareza_clave])) continue;
                                $cantidad_rareza = $rarezas_grupo[$rareza_clave];
                                // nombre bonito: "poco_comun" -> "Poco comun"
                                $nombre_rareza = ucfirst(str_replace("_", " ", $rareza_clave));
                            ?>
                                <div class="stats-fila">
                                    <span class="stats-label stats-label-rareza rareza-<?= $rareza_clave ?>"><?= $nombre_rareza ?></span>
                                    <span class="stats-valor"><?= number_format($cantidad_rareza) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="stats-bloque">
                    <p style="color:var(--silver-dim); text-align:center; padding:20px; font-style:italic;">
                        Todavia no has conseguido ninguna runa. A tirar!
                    </p>
                </div>
            <?php endif; ?>

            <!-- aqui iran los logros en el futuro. ver ideas futuras al final del archivo -->
            <div class="stats-bloque stats-proximamente">
                <div class="stats-bloque-titulo">Logros</div>
                <p class="stats-proximamente-txt">
                    Proximamente. Aqui iran los logros desbloqueables.
                </p>
            </div>
        </div>

        <!-- menu 5: ajustes -->
        <!-- guardar partida, cambiar cuenta, configurar produccion (por si el jugador
             quiere hacer grindeo lento a proposito), contactar admin por mensajes,
             modo de mostrar probabilidades y el boton sagrado de borrar todo -->
        <div id="seccion-ajustes" class="seccion">
            <div class="seccion-titulo">Ajustes</div>

            <!-- boton de guardado manual. hay autosave cada 30s (ver tirada.js)
                 pero por si acaso y para los paranoicos como yo -->
            <button class="ajuste-btn" onclick="guardarProgreso()">⬡ Guardar partida</button>
            <p id="msg-guardado" class="form-msg exito"></p>

            <!-- cambiar el nombre de usuario. ojo: no cambia el login, solo el
                 display. el login sigue siendo el email -->
            <div class="ajuste-input-grupo">
                <div class="ajuste-label">Cambiar nombre de usuario</div>
                <div class="ajuste-input-row">
                    <input type="text" class="ajuste-input" id="nuevo-username"
                           placeholder="Nuevo nombre de usuario">
                    <button class="ajuste-input-btn" onclick="cambiarUsername()">Guardar</button>
                </div>
                <span class="ajuste-msg" id="msg-username"></span>
            </div>

            <!-- cambiar el email del usuario. importante, porque con esto se loguea -->
            <div class="ajuste-input-grupo">
                <div class="ajuste-label">Cambiar email</div>
                <div class="ajuste-input-row">
                    <input type="email" class="ajuste-input" id="nuevo-email"
                           placeholder="Nuevo email">
                    <button class="ajuste-input-btn" onclick="cambiarEmail()">Guardar</button>
                </div>
                <span class="ajuste-msg" id="msg-email"></span>
            </div>

            <!-- cambiar contrasena. no se requiere la actual, solo porque ya
                 estas logueado. TODO: quizas pedirla por seguridad -->
            <div class="ajuste-input-grupo">
                <div class="ajuste-label">Cambiar contrasena</div>
                <div class="ajuste-input-row">
                    <input type="password" class="ajuste-input" id="nueva-password"
                           placeholder="Nueva contrasena">
                    <button class="ajuste-input-btn" onclick="cambiarPassword()">Guardar</button>
                </div>
                <span class="ajuste-msg" id="msg-password"></span>
            </div>

            <!-- configurar produccion de coins/seg. el jugador puede ponerse menos
                 produccion si quiere (por ejemplo para grindear en idle lento).
                 no puede pasarse del maximo que le dan sus mejoras -->
            <div class="ajuste-input-grupo">
                <div class="ajuste-label">Configurar coins/seg</div>
                <div class="ajuste-label-hint">
                    Tu maximo: <strong id="max-coins-display"><?= number_format($coins_ps_max, 2) ?></strong>/seg
                    — Pon 0 para restablecer al maximo
                </div>
                <div class="ajuste-input-row">
                    <input type="number" class="ajuste-input" id="config-coins-ps"
                           min="0" step="0.01"
                           placeholder="0 = maximo">
                    <button class="ajuste-input-btn" onclick="configurarProduccion('coins')">Aplicar</button>
                </div>
                <span class="ajuste-msg" id="msg-coins-ps"></span>
            </div>

            <!-- igual que el de arriba pero para points/seg -->
            <div class="ajuste-input-grupo">
                <div class="ajuste-label">Configurar points/seg</div>
                <div class="ajuste-label-hint">
                    Tu maximo: <strong id="max-points-display"><?= number_format($points_ps_max, 2) ?></strong>/seg
                    — Pon 0 para restablecer al maximo
                </div>
                <div class="ajuste-input-row">
                    <input type="number" class="ajuste-input" id="config-points-ps"
                           min="0" step="0.01"
                           placeholder="0 = maximo">
                    <button class="ajuste-input-btn" onclick="configurarProduccion('points')">Aplicar</button>
                </div>
                <span class="ajuste-msg" id="msg-points-ps"></span>
            </div>

            <!-- panel desplegable para contactar al admin. permite reportar bugs,
                 enviar ideas, quejarse de la vida, etc. con imagen adjunta opcional
                 TODO: que el admin pueda responder desde dentro del juego -->
            <button id="contacto-toggle" onclick="toggleContacto()">
                <span>✉ Contactar Admin</span>
                <span class="flecha">▼</span>
            </button>

            <div id="contacto-contenido">
                <div class="form-grupo">
                    <label class="form-label">Tipo de mensaje</label>
                    <select id="msg-tipo" class="form-select">
                        <option value="">-- Selecciona --</option>
                        <option value="ideas">Ideas</option>
                        <option value="errores">Error en el juego</option>
                        <option value="error_datos">Error en mis datos</option>
                        <option value="no_importante">No importante</option>
                    </select>
                </div>
                <div class="form-grupo">
                    <label class="form-label">Asunto</label>
                    <input type="text" id="msg-asunto" class="form-input" maxlength="200" placeholder="Asunto del mensaje">
                </div>
                <div class="form-grupo">
                    <label class="form-label">Mensaje (max 500 caracteres)</label>
                    <textarea id="msg-contenido" class="form-textarea" maxlength="500" placeholder="Escribe tu mensaje..."></textarea>
                    <span class="contador-chars" id="contador-chars">0 / 500</span>
                </div>
                <div class="form-grupo">
                    <label class="form-label">Adjuntar imagen (opcional, max 5MB)</label>
                    <div class="file-input-wrapper">
                        <input type="file" id="msg-archivo" accept=".jpg,.jpeg,.png,.webp"
                               onchange="actualizarNombreArchivo(this)">
                        <div class="file-input-label">
                            <span class="file-icon">📎</span>
                            <span id="file-nombre-label" class="file-nombre">Seleccionar imagen...</span>
                        </div>
                    </div>
                </div>
                <p id="msg-error" class="form-msg error"></p>
                <p id="msg-exito" class="form-msg exito"></p>
                <button class="btn-enviar" onclick="enviarMensaje()">Enviar Mensaje</button>
            </div>

            <!-- 27/04 v3: toggle de display_mode (porcentaje/peso) eliminado.
                 ahora todas las probabilidades se muestran como fraccion fija. -->

            <div class="sidebar-divider"></div>

            <!-- rendimiento: toggles para desactivar animaciones pesadas. ahora
                 mismo solo hay uno pero aqui ire metiendo los que vayan saliendo
                 (reducir neons, desactivar mini canvas de coleccion, etc)
                 el estado se guarda en localStorage, no va a la base de datos
                 porque es cliente-especifico (si el jugador entra desde otro
                 pc, le da igual, es por rendimiento del dispositivo) -->
            <div class="ajuste-input-grupo">
                <div class="ajuste-label">Rendimiento</div>
                <div class="ajuste-label-hint">
                    Desactiva las animaciones que consuman mas si notas lag
                </div>
                <div class="toggle-rendimiento">
                    <span class="toggle-rendimiento-label">Particulas del boton de tirada</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="toggle-anim-boton" checked onchange="toggleAnimBoton(this)">
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div class="sidebar-divider"></div>

            <!-- the big red button. todo el mundo sabe lo que hace. punto -->
            <button class="ajuste-btn danger" onclick="abrirModal()">✕ Borrar progreso</button>
        </div>

    </main>

    <!-- panel derecho: muestra "mis runas" en modo tirada/tienda/ajustes, y cambia
         a "estadisticas de runa seleccionada" cuando estas en coleccion.
         el toggle lo hace mostrarSeccion() en ui.js -->
    <aside id="panel-derecho">

        <!-- mis runas: lista de todas las runas del juego con su cantidad y %.
             cada card es desplegable para ver probabilidad base y con suerte -->
        <div id="panel-mis-runas">
            <div class="panel-titulo">Mis Runas <span class="panel-titulo-sub" id="panel-runas-count"><?= $desbloqueadas_num ?>/<?= $total_runas ?></span></div>
            <div id="panel-runas-contenido">
                <?php
                // agrupar todas las runas por su grupo (basicas, evento, etc)
                // para pintarlas con su separador en el sidebar derecho
                $todas_runas_agrupadas = [];
                foreach ($todas_runas as $runa) {
                    $todas_runas_agrupadas[$runa["grupo_nombre"]][] = $runa;
                }
                // mapa id_runa -> cantidad que tiene el jugador, para saber si
                // dibujo la runa como desbloqueada o con el candado de bloqueo
                $mis_cantidades = [];
                foreach ($mis_runas as $runa_jug) { $mis_cantidades[$runa_jug["id"]] = (int)$runa_jug["cantidad"]; }
                ?>
                <?php foreach ($todas_runas_agrupadas as $grupo_nombre => $runas_grupo): ?>
                    <div class="grupo-runas">
                        <div class="grupo-nombre"><?= htmlspecialchars($grupo_nombre) ?></div>
                        <?php foreach ($runas_grupo as $runa_item):
                            $id_runa   = $runa_item["id"];
                            $cantidad  = $mis_cantidades[$id_runa] ?? 0;
                            $tiene     = $cantidad > 0;
                            $pct_prob  = isset($prob_map[$id_runa]) ? $prob_map[$id_runa]["prob"] : 0;
                            $rareza    = htmlspecialchars($runa_item["rareza"]);
                        ?>
                            <div class="runa-card <?= $rareza ?> runa-card-btn <?= !$tiene ? 'runa-bloqueada' : '' ?>"
                                 data-id="<?= $id_runa ?>"
                                 data-prob="<?= $pct_prob ?>"
                                 onclick="toggleRunaProb(this)">
                                <div class="runa-card-main">
                                    <span class="runa-card-nombre"><?= htmlspecialchars($runa_item["nombre"]) ?></span>
                                    <div class="runa-card-right">
                                        <?php if ($tiene): ?>
                                            <span class="runa-card-cantidad">x<?= number_format($cantidad) ?></span>
                                        <?php else: ?>
                                            <span class="runa-card-candado">🔒</span>
                                        <?php endif; ?>
                                        <span class="runa-card-flecha">▾</span>
                                    </div>
                                </div>
                                <div class="runa-card-prob">
                                    <div class="prob-fila">
                                        <span class="prob-label">Probabilidad</span>
                                        <span class="prob-val prob-base-val"></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- panel alternativo de stats de la runa seleccionada en coleccion.
             por defecto oculto (display:none). se enciende desde ui.js cuando
             el jugador entra al menu de coleccion -->
        <div id="panel-col-stats" style="display:none;">
            <div class="panel-titulo col-stats-titulo" id="col-stats-titulo">— Estadísticas —</div>
            <div id="col-stats-contenido">
                <p style="color:var(--silver-dim);font-size:0.9rem;text-align:center;margin-top:20px;">
                    Selecciona una runa
                </p>
            </div>
        </div>
    </aside>

</div>

<script>
// RW_INIT: el puente de datos de php a js
// aqui dentro va todo lo que js necesita saber al cargar la pagina.
// js lo lee como window.RW_INIT en ui.js y copia los valores a variables globales.
// si anades algo aqui, tienes que leerlo tambien en ui.js (y al reves)
window.RW_INIT = {
    coins:          <?= number_format($coins,  4, '.', '') ?>,
    points:         <?= number_format($points, 4, '.', '') ?>,
    coins_ps:       <?= $coins_ps  ?>,
    points_ps:      <?= $points_ps ?>,
    coins_ps_max:   <?= $coins_ps_max  ?>,
    points_ps_max:  <?= $coins_ps_max  ?>,

    // 27/04 v3: campos suerte_*, curvas_data y display_mode eliminados.
    // las probabilidades son fijas, no dependen de suerte ni de boosts.

    // multiplicadores de mejoras que persisten entre tiradas, js los
    // guarda en variables globales para no tener que recalcular siempre
    mejora_coins_ps:    <?= isset($coins_ps) ? $coins_ps : 1 ?>,
    mejora_multi_pts:   <?= isset($multi_points) ? $multi_points : 1.0 ?>,
    mejora_points_add:  <?= isset($points_add) ? $points_add : 0.0 ?>,
    runas_points_ps:    <?= isset($runas_pts_base) ? $runas_pts_base : 0.0 ?>,

    bulk_total:     <?= $bulk_total ?>,
    user_id:        <?= (int)$id_usuario ?>,
    probMap:        <?= json_encode($prob_map) ?>,

    boost_tipos:    <?= json_encode($boost_tipos) ?>,
    boost_intervalo:<?= $boost_intervalo ?> * 1000,

    // necesario para filtrar los boosts legendario y divino, que solo salen
    // si el jugador tiene comprada la mejora de desbloqueo correspondiente
    mejoras_desbloqueadas: <?= json_encode($mejoras_desbloqueadas) ?>,
    prob_rareza:    <?= json_encode($prob_rareza_pct) ?>,

    // datos de TODAS las mejoras de la tabla `mejoras` con el nivel actual
    // del jugador. tienda.js los lee al cargar y construye las filas
    // horizontales con barra segmentada e iconos. el flag `bloqueada` y el
    // texto-pista los calcula PHP arriba (ver $mejoras_con_estado), asi
    // tienda.js solo pinta lo que recibe sin tener que evaluar nada
    mejoras_completas: <?= json_encode(array_map(function($m) {
        return [
            "id"               => (int)   $m["id"],
            "nombre"           => (string)$m["nombre"],
            "tipo"             => (string)$m["tipo"],
            "valor"            => (float) $m["valor"],
            "coste_base"       => (float) $m["coste_base"],
            "coste_escala"     => (float) $m["coste_escala"],
            "nivel_actual"     => (int)   $m["nivel_actual"],
            "nivel_maximo"     => (int)   $m["nivel_maximo"],
            "descripcion"      => (string)($m["descripcion"] ?? ""),
            "orden"            => (int)   ($m["orden"] ?? 0),
            "bloqueada"        => (bool)  ($m["bloqueada"] ?? false),
            "condicion_texto"  => (string)($m["condicion_texto"] ?? ""),
            "es_nueva"         => false,   // marca tras desbloqueo reciente (pendiente)
        ];
    }, $mejoras_con_estado)) ?>,
};

// toggle de particulas del boton de tirada (ajustes > rendimiento).
// guarda el estado en localStorage porque es client-side (depende del dispositivo)
function toggleAnimBoton(cb) {
    // si esta marcada (checked), animaciones ON, si no, OFF
    localStorage.setItem("rw_anim_boton", cb.checked ? "on" : "off");
}

// al cargar, sincronizo el checkbox con lo que haya guardado en localStorage.
// si no hay nada guardado, por defecto esta encendido (mejor experiencia de
// entrada, que vea las animaciones y si le molestan las quita)
(function() {
    const estado = localStorage.getItem("rw_anim_boton");
    // solo si explicitamente esta en "off" desmarco. si es null o "on" queda checked
    if (estado === "off") {
        // espero a que el DOM cargue porque el checkbox esta en ajustes
        document.addEventListener("DOMContentLoaded", () => {
            const cb = document.getElementById("toggle-anim-boton");
            if (cb) cb.checked = false;
        });
    }
})();

// Función simple para quitar el mensaje de la vista
    function cerrarBienvenida() {
        const overlay = document.getElementById('bienvenida-overlay');
        overlay.classList.remove('mostrar');
        // Hemos quitado el localStorage.setItem, así que no se guardará el cierre
    }

    // Se ejecuta cada vez que la página termina de cargar
    window.addEventListener('load', () => {
        const overlay = document.getElementById('bienvenida-overlay');
        if (overlay) { // <-- Esta comprobación evita el error
            setTimeout(() => {
                overlay.classList.add('mostrar');
            }, 3500); 
        }
    });

</script>

<!-- orden de los scripts importa: animaciones primero porque la usan casi todos,
     toggles segundo porque ui.js usa el modo display, ui.js despues (define
     las variables globales), coleccion necesita ui, boosts necesita ui,
     tirada necesita boosts, ajustes al final -->
<script src="JS/animaciones.js"></script>
<script src="JS/eterna_shards.js"></script>
<script src="JS/toggles.js"></script>
<script src="JS/ui.js"></script>
<script src="JS/coleccion.js"></script>
<script src="JS/boosts.js"></script>
<script src="JS/runa-sync.js"></script>
<script src="JS/tirada.js"></script>
<script src="JS/tienda.js"></script>
<script src="JS/ajustes.js"></script>
<script src="JS/mobile.js"></script>

<!-- ================================================================
     ideas futuras / TODO / cosas que olvide
     ================================================================

     core del juego:
       - sistema de prestigio. reseteas runas/mejoras pero ganas una moneda
         especial que multiplica todo permanentemente. en antimatter dimensions
         esto se llama "dimensional boost" y hace que quieras reiniciar, idea buena
       - eventos temporales con runas exclusivas que solo salen en fechas concretas
       - ranking global de jugadores por points, runas conseguidas, suerte maxima, etc
       - sistema de logros visible en ajustes (ej: "tira 1000 runas", "consigue tu primera eterna")
       - daily login reward, que el jugador vuelva cada dia
       - prestigio de segundo nivel (meta-prestigio) para los que lo terminen todo

     quality of life:
       - boton para silenciar musica y sonidos por separado
       - guardar/cargar partida desde archivo json (para moverla entre dispositivos)
       - modo oscuro / claro (el juego ahora es oscuro fijo, algun valiente pedira el claro)
       - atajos de teclado para tirar runa (barra espaciadora, enter)
       - mostrar dps (damage per second) en runas? no, esto no es league of legends

     visual:
       - que cada rareza tenga un sonido distintivo al salir (especialmente las raras)
       - animacion de legendaria necesita un repaso, es la mas tosca
       - divina_animacion.html esta medio rota, hay que arreglar donde aparece la cruz
       - efecto de "pantalla rajada" al salir una mitica (critico)
       - boost flotante: cuando lo pillas, que deje rastro de particulas

     rendimiento:
       - actualizarPantalla() se llama cada segundo + cada tirada, quiza muchas veces.
         revisar si se puede throttlear
       - el panel de mis runas se regenera entero al actualizar, se podria
         actualizar solo la runa que cambia

     social / competitivo (ideas lejanas):
       - chat global entre jugadores
       - compartir builds de mejoras
       - trading de runas entre jugadores (cuidado con los bots)
       - guilds / clanes con bonus compartidos

     notas finales:
       - empece el 28 de febrero pensando que era un proyecto de 2 semanas.
         hoy 10 de abril sigo aqui. por que empece esto? maldito mundo capitalista.
         !hi al que lo este leyendo y gg.
================================================================ -->

</body>
</html>
