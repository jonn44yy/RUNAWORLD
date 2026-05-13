(function(){
    function parseNum(v){
        if (v == null) return 0;
        v = String(v).trim().replace(',', '.');
        var m = v.match(/^([0-9]+(?:\.[0-9]+)?)(k|m|b|t)?$/i);
        if (!m) return Number(v) || 0;
        var n = Number(m[1]);
        var s = (m[2] || '').toLowerCase();
        if (s === 'k') n *= 1e3;
        if (s === 'm') n *= 1e6;
        if (s === 'b') n *= 1e9;
        if (s === 't') n *= 1e12;
        return n;
    }
    function fmt(n){
        n = Number(n) || 0;
        if (n >= 1e12) return (n/1e12).toFixed(2).replace(/\.00$/,'').replace(/0$/,'') + 'T';
        if (n >= 1e9) return (n/1e9).toFixed(2).replace(/\.00$/,'').replace(/0$/,'') + 'B';
        if (n >= 1e6) return (n/1e6).toFixed(2).replace(/\.00$/,'').replace(/0$/,'') + 'M';
        if (n >= 1e3) return (n/1e3).toFixed(2).replace(/\.00$/,'').replace(/0$/,'') + 'k';
        return String(Math.round(n * 10000) / 10000);
    }
    function categoria(tipo){
        if (['coins_seg','points_seg','suerte'].includes(tipo)) return 'Normal';
        if (['coins_seg_multi','points_seg_multi','bulk_normal','bulk_extra','desbloquear_boost_leg','desbloquear_boost_div'].includes(tipo)) return 'Especial/dorada';
        if (['coins_seg_multi_eterno','points_seg_multi_eterno','bulk'].includes(tipo)) return 'Eterna/morada';
        return 'Desconocida';
    }
    function condicionHelp(c){
        if (c === 'tirar_runa_x') return 'Se desbloquea cuando el jugador alcanza el numero indicado de tiradas.';
        if (c === 'comprar_mejora_id') return 'Se desbloquea cuando el jugador compra la mejora cuyo ID pongas como valor.';
        if (c === 'clickar_boost_x') return 'Se desbloquea cuando el jugador interactua con boosts el numero indicado de veces.';
        return 'Sin condicion: visible desde el inicio.';
    }
    function update(){
        var base = parseNum(document.getElementById('coste_base') && document.getElementById('coste_base').value);
        var escala = Number(document.getElementById('coste_escala') && document.getElementById('coste_escala').value) || 1;
        var max = Number(document.getElementById('nivel_maximo') && document.getElementById('nivel_maximo').value) || 0;
        var tipo = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
        var cond = document.getElementById('condicion_tipo') ? document.getElementById('condicion_tipo').value : 'ninguna';
        var n1 = document.getElementById('prev_n1');
        var n2 = document.getElementById('prev_n2');
        var nm = document.getElementById('prev_max');
        var cat = document.getElementById('prev_cat');
        var help = document.getElementById('condicion_help');
        if (n1) n1.textContent = fmt(base) + ' pts';
        if (n2) n2.textContent = fmt(base * escala) + ' pts';
        if (nm) nm.textContent = max > 0 ? fmt(base * Math.pow(escala, Math.max(0, max - 1))) + ' pts' : 'No calculado';
        if (cat) cat.textContent = categoria(tipo);
        if (help) help.textContent = condicionHelp(cond);
    }
    document.addEventListener('DOMContentLoaded', function(){
        ['coste_base','coste_escala','nivel_maximo','tipo','condicion_tipo'].forEach(function(id){
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', update);
            if (el) el.addEventListener('change', update);
        });
        update();
    });
})();
