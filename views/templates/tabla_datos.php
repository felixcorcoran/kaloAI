<?php
/**
 * TABLA CON LOS DATOS DE UNA GRÁFICA (accesibilidad)
 * Las gráficas de Chart.js son un dibujo: un lector de pantalla no puede leer
 * sus valores. Debajo de cada una va esta tabla plegada («Ver los datos»), que
 * también sirve a quien prefiere ver las cifras exactas.
 *
 *   echo tablaDatos('Peso por fecha', ['Fecha', 'Peso'], [['3 nov', '68,2 kg'], ...]);
 */

function tablaDatos(string $titulo, array $cabeceras, array $filas): string {
    if (!$filas) return '';
    $html = '<details class="k-datos"><summary>' . icono('table', 'h-4 w-4') . 'Ver los datos</summary>'
          . '<div class="k-datos-cuerpo"><table class="k-table"><caption class="sr-only">' . e($titulo) . '</caption><thead><tr>';
    foreach ($cabeceras as $i => $c) $html .= '<th scope="col"' . ($i > 0 ? ' class="text-right"' : '') . '>' . e($c) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($filas as $fila) {
        $html .= '<tr>';
        foreach (array_values($fila) as $i => $valor) {
            $html .= $i === 0 ? '<th scope="row" class="font-normal k-strong">' . e($valor) . '</th>' : '<td class="text-right tabular-nums">' . e($valor) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div></details>';
}
