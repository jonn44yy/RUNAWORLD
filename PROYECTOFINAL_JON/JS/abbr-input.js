// abbr-input.js — runaworld admin
// v2 (abril): ampliado para soportar sufijos hasta Dc (decillion = 1e33).
// los nuevos sufijos son de 2 letras (Qa, Qi, Sx, Sp, Oc, No, Dc) y se
// listan ANTES que los de 1 letra en el regex para que no queden
// enmascarados (si "k" fuese antes que "Qa", el regex podria matchear
// "1Q" + sobrante).
//
// ademas expone window.fmt y window.fmtCorto como alias de fmtAbbr,
// para que tienda.js (que esperaba un formato.js dedicado) lo encuentre
// aqui sin tener que mantener dos archivos en sincronia.
//
// conversion automatica entre numeros crudos (1500000) y abreviados (1.5M)
// en los inputs del panel de admin. si un multi de runa eterna son 9 mil
// millones, escribir "9000000000" a mano es una fiesta de ceros mal contados.
// con esto escribes "9B" y ya
//
// uso en el html:
//   <input type="text" class="input-abbr" name="multiplicador" value="5000000">
//   --> el admin ve "5M" en el campo al cargar
//   --> puede escribir "5B", "1.5M", "500k", "50", "2.5Sx" directamente
//   --> al perder el foco se normaliza ("5.00B" pasa a "5B")
//   --> al enviar el form, el valor se convierte al numero crudo para la bd
//
// expone en window:
//   parseAbbr(str)  --> "5B" -> 5e9
//   fmtAbbr(n)      --> 5e9 -> "5B"
//   fmt(n)          --> alias de fmtAbbr (lo busca tienda.js)
//   fmtCorto(n)     --> alias de fmtAbbr (lo busca tienda.js)
//
// !hi


(function () {
    'use strict';

    // tabla de sufijos en orden DESCENDENTE de magnitud. el primero que
    // matchea gana. si un dia hay que pasar de Dc, anado mas filas aqui
    // sin tocar el resto del codigo
    var SUFIJOS = [
        { val: 1e33, sx: 'Dc' },  // decillion
        { val: 1e30, sx: 'No' },  // nonillion
        { val: 1e27, sx: 'Oc' },  // octillion
        { val: 1e24, sx: 'Sp' },  // septillion
        { val: 1e21, sx: 'Sx' },  // sextillion
        { val: 1e18, sx: 'Qi' },  // quintillion
        { val: 1e15, sx: 'Qa' },  // quadrillion
        { val: 1e12, sx: 'T'  },  // trillion
        { val: 1e9,  sx: 'B'  },  // billion (mil millones)
        { val: 1e6,  sx: 'M'  },  // million
        { val: 1e3,  sx: 'k'  }   // mil
    ];

    // mapa sufijo -> multiplicador para parsear input. acepta cualquier
    // capitalizacion (todo se pasa a lower antes de buscar)
    var MULTS = {
        '':   1,
        'k':  1e3,  'm':  1e6,  'b':  1e9,  't':  1e12,
        'qa': 1e15, 'qi': 1e18, 'sx': 1e21, 'sp': 1e24,
        'oc': 1e27, 'no': 1e30, 'dc': 1e33
    };

    // parseAbbr: string abreviado --> numero crudo
    //   "5B"    --> 5000000000
    //   "1.5M"  --> 1500000
    //   "2.5Sx" --> 2.5e21
    //   "500"   --> 500
    //   "abc"   --> NaN (invalido, para que el caller pueda detectar error)
    // acepta coma o punto como decimal (espanol vs ingles) y espacios sueltos
    function parseAbbr(str) {
        if (str === null || str === undefined) return NaN;
        if (typeof str === 'number') return str;
        var s = String(str).trim().replace(/\s/g, '').replace(/,/g, '.');
        if (s === '') return NaN;
        // CRITICAL: los sufijos de 2 letras van ANTES en la alternativa,
        // si no, "Qa" se matchearia como "Q" (no valido) o el "a" sobrante
        // haria fallar el regex completo. flag i = case-insensitive
        var m = s.match(/^(-?\d+(?:\.\d+)?)(Qa|Qi|Sx|Sp|Oc|No|Dc|[kmbt])?$/i);
        if (!m) return NaN;
        var n      = parseFloat(m[1]);
        var sufijo = (m[2] || '').toLowerCase();
        var mult   = MULTS[sufijo];
        if (mult === undefined) return NaN;
        return n * mult;
    }

    // fmtAbbr: numero crudo --> string abreviado, con el sufijo mas alto
    // que aplique. quita decimales inutiles ("5.00M" -> "5M", "1.50B" -> "1.5B")
    function fmtAbbr(n) {
        n = parseFloat(n);
        if (!isFinite(n)) return '';
        if (n === 0)       return '0';
        var abs = Math.abs(n);

        // strip = formato a 2 decimales y luego quitar ceros a la derecha
        // (incluido el punto si quedan colgados)
        var strip = function (x) { return x.toFixed(2).replace(/\.?0+$/, ''); };

        for (var i = 0; i < SUFIJOS.length; i++) {
            if (abs >= SUFIJOS[i].val) {
                return strip(n / SUFIJOS[i].val) + SUFIJOS[i].sx;
            }
        }
        // sub-uno: para suerte 0.05 y similares, no abreviar, solo recortar
        // ceros con toFixed(6) + parseFloat
        if (abs > 0 && abs < 1) {
            return parseFloat(n.toFixed(6)).toString();
        }
        return Math.round(n).toString();
    }

    // exponer en window. los alias fmt y fmtCorto son para que tienda.js
    // (que esperaba un formato.js dedicado) encuentre la funcion sin que
    // tengas que mantener dos archivos. si en el futuro quieres dos
    // formatos distintos (uno con decimales fijos, otro recortando), aqui
    // los separas
    window.parseAbbr = parseAbbr;
    window.fmtAbbr   = fmtAbbr;
    window.fmt       = fmtAbbr;
    window.fmtCorto  = fmtAbbr;


    // engancha el comportamiento a todos los inputs con clase .input-abbr.
    // hace, por cada input:
    //   1. si el input era type="number", lo cambia a text (el "B" no pasa
    //      por el filtro de type=number y el navegador te come la letra)
    //   2. formatea el valor inicial que viene crudo desde la bd
    //   3. valida mientras escribes (marca rojo si no parsea, no bloquea)
    //   4. normaliza al perder el foco ("5.0M" pasa a "5M", "1500M" a "1.5B")
    //   5. convierte a crudo al enviar el form (el admin mando "5M" pero a
    //      la bd llega 5000000)
    function init() {
        document.querySelectorAll('input.input-abbr').forEach(function (inp) {
            if (inp.type === 'number') inp.type = 'text';
            inp.setAttribute('inputmode', 'decimal');
            inp.setAttribute('autocomplete', 'off');

            var rawInicial = parseFloat(inp.value);
            if (!isNaN(rawInicial) && inp.value.trim() !== '') {
                inp.value = fmtAbbr(rawInicial);
            }

            inp.addEventListener('input', function () {
                var p = parseAbbr(inp.value);
                inp.classList.toggle('input-abbr-invalid',
                    isNaN(p) && inp.value.trim() !== ''
                );
            });

            inp.addEventListener('blur', function () {
                var p = parseAbbr(inp.value);
                if (!isNaN(p)) {
                    inp.value = fmtAbbr(p);
                    inp.classList.remove('input-abbr-invalid');
                }
            });

            var form = inp.closest('form');
            if (form && !form.dataset.abbrHooked) {
                form.dataset.abbrHooked = '1';
                form.addEventListener('submit', function (e) {
                    form.querySelectorAll('input.input-abbr').forEach(function (i) {
                        var p = parseAbbr(i.value);
                        if (!isNaN(p)) {
                            i.value = p;
                        } else if (i.value.trim() === '') {
                            i.value = '0';
                        } else {
                            e.preventDefault();
                            i.classList.add('input-abbr-invalid');
                            i.focus();
                        }
                    });
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();


// ideas futuras / TODO:
//   - soportar notacion cientifica (5e9 --> 5000000000) para los pros
//     que la prefieren por costumbre
//   - placeholder dinamico que sugiera un ejemplo segun el rango
//     esperado del campo (p.ej. si es un multi, mostrar "ej: 1.5M")
//   - microflash al normalizar en blur, para que el admin vea que algo
//     ha cambiado y no piense que se rompio
