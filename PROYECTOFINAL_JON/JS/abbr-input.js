// abbr-input.js — runaworld admin
// conversion automatica entre numeros crudos (1500000) y abreviados (1.5M)
// en los inputs del panel de admin. si un multi de runa eterna son 9 mil
// millones, escribir "9000000000" a mano es una fiesta de ceros mal contados.
// con esto escribes "9B" y ya
//
// uso en el html:
//   <input type="text" class="input-abbr" name="multiplicador" value="5000000">
//   --> el admin ve "5M" en el campo al cargar
//   --> puede escribir "5B", "1.5M", "500k", "50" directamente
//   --> al perder el foco se normaliza ("5.00B" pasa a "5B")
//   --> al enviar el form, el valor se convierte al numero crudo para la bd
//
// expone en window: parseAbbr(str) y fmtAbbr(n) por si algun otro script
// los necesita fuera de los inputs (p.ej. para mostrar numeros grandes)
//
// hecho a finales de marzo para el panel admin nuevo. no he tocado una linea
// desde que lo escribi, es de los pocos archivos que puedo decir que estan
// acabados. !hi


(function () {
    'use strict';

    // parseAbbr: string abreviado --> numero crudo
    //   "5B"    --> 5000000000
    //   "1.5M"  --> 1500000
    //   "500"   --> 500
    //   "abc"   --> NaN (invalido, para que el caller pueda detectar error)
    // acepta coma o punto como decimal (espanol vs ingles) y espacios sueltos.
    // el regex permite negativos por si algun dia hay valores negativos
    function parseAbbr(str) {
        if (str === null || str === undefined) return NaN;
        if (typeof str === 'number') return str;
        // normalizo: fuera espacios, coma --> punto
        const s = String(str).trim().replace(/\s/g, '').replace(/,/g, '.');
        if (s === '') return NaN;
        // signo opcional + digitos + decimal opcional + sufijo opcional
        const m = s.match(/^(-?\d+(?:\.\d+)?)([kKmMbBtT]?)$/);
        if (!m) return NaN;
        const n = parseFloat(m[1]);
        const mults = { '': 1, 'k': 1e3, 'm': 1e6, 'b': 1e9, 't': 1e12 };
        return n * mults[m[2].toLowerCase()];
    }

    // fmtAbbr: numero crudo --> string abreviado
    //   5000000 --> "5M"
    //   1500000 --> "1.5M"
    //   0.5     --> "0.5"  (decimales pequenos tal cual)
    //   150     --> "150"
    // el replace del strip quita decimales inutiles: "5.00M" queda "5M"
    function fmtAbbr(n) {
        n = parseFloat(n);
        if (!isFinite(n)) return '';
        const abs = Math.abs(n);
        const strip = (x) => x.toFixed(2).replace(/\.?0+$/, '');
        if (abs >= 1e12) return strip(n / 1e12) + 'T';
        if (abs >= 1e9)  return strip(n / 1e9)  + 'B';
        if (abs >= 1e6)  return strip(n / 1e6)  + 'M';
        if (abs >= 1e3)  return strip(n / 1e3)  + 'k';
        if (abs > 0 && abs < 1) {
            // decimales pequenos (p.ej. suerte 0.05): no abreviar, solo
            // quitar ceros a la derecha. toFixed(6) + parseFloat hace ese
            // recorte sin mates raras
            return parseFloat(n.toFixed(6)).toString();
        }
        return Math.round(n).toString();
    }

    // exponer por si otros scripts los quieren usar fuera de inputs
    window.parseAbbr = parseAbbr;
    window.fmtAbbr   = fmtAbbr;


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
        document.querySelectorAll('input.input-abbr').forEach(inp => {
            if (inp.type === 'number') inp.type = 'text';
            inp.setAttribute('inputmode', 'decimal');  // teclado numerico en movil
            inp.setAttribute('autocomplete', 'off');   // sin sugerencias del navegador

            // formatear valor inicial: si en la bd hay 500000000, el input
            // viene con ese crudo. lo paso a "500M" para que no asuste al
            // admin al abrir el form
            const rawInicial = parseFloat(inp.value);
            if (!isNaN(rawInicial) && inp.value.trim() !== '') {
                inp.value = fmtAbbr(rawInicial);
            }

            // validacion en vivo: marca el input en rojo si lo escrito no
            // es parseable. no bloquea nada, solo feedback visual para que
            // el admin se entere antes de darle a submit
            inp.addEventListener('input', () => {
                const p = parseAbbr(inp.value);
                inp.classList.toggle('input-abbr-invalid',
                    isNaN(p) && inp.value.trim() !== ''
                );
            });

            // normalizar al perder foco: pasa el valor por parseAbbr y lo
            // repinta con fmtAbbr, con lo cual "5.00B" queda "5B", "1500M"
            // queda "1.5B", etc. si no parseaba, lo deja tal cual (lo marco
            // con la clase roja y que el admin lo corrija)
            inp.addEventListener('blur', () => {
                const p = parseAbbr(inp.value);
                if (!isNaN(p)) {
                    inp.value = fmtAbbr(p);
                    inp.classList.remove('input-abbr-invalid');
                }
            });

            // convertir a crudo al enviar el form. engancho el submit UNA
            // sola vez por form (dataset.abbrHooked) aunque el form tenga
            // varios input-abbr. dentro del handler itero todos los inputs
            // de ese form y los paso a crudo
            const form = inp.closest('form');
            if (form && !form.dataset.abbrHooked) {
                form.dataset.abbrHooked = '1';
                form.addEventListener('submit', (e) => {
                    form.querySelectorAll('input.input-abbr').forEach(i => {
                        const p = parseAbbr(i.value);
                        if (!isNaN(p)) {
                            i.value = p;                // crudo, va al backend
                        } else if (i.value.trim() === '') {
                            i.value = '0';              // vacio = 0, no error
                        } else {
                            // invalido --> cancelo el submit y pongo foco
                            // en el input roto con la clase roja marcada
                            e.preventDefault();
                            i.classList.add('input-abbr-invalid');
                            i.focus();
                        }
                    });
                });
            }
        });
    }

    // arrancar cuando el DOM este listo. si el script carga en la cabecera
    // (readyState=loading) espero al DOMContentLoaded. si carga al final del
    // body (cualquier otro estado) ejecuto ya
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();


// ideas futuras / TODO:
//   - soportar notacion cientifica (5e9 --> 5000000000) para los pros que
//     la prefieren por costumbre
//   - placeholder dinamico que sugiera un ejemplo segun el rango esperado
//     del campo (p.ej. si es un multi, mostrar "ej: 1.5M")
//   - microflash al normalizar en blur, para que el admin vea que algo ha
//     cambiado y no piense que se rompio