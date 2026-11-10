<?php
// Utilidades para los reportes: filtro por rango de fechas y exportación a CSV.
// Se incluye DESPUÉS de definir responder() en el archivo que lo usa.

// Lee ?desde=YYYY-MM-DD&hasta=YYYY-MM-DD (ambos opcionales, ambos inclusive).
// Devuelve ['desde' => 'Y-m-d 00:00:00'|null, 'hasta' => 'Y-m-d 23:59:59'|null]
function leerRangoFechas() {
    $r = ['desde' => null, 'hasta' => null];
    foreach (['desde', 'hasta'] as $campo) {
        if (!isset($_GET[$campo]) || $_GET[$campo] === '') {
            continue;
        }
        $v = $_GET[$campo];
        $d = is_string($v) ? DateTime::createFromFormat('Y-m-d', $v) : false;
        if (!$d || $d->format('Y-m-d') !== $v) {
            responder(400, ["error" => "$campo debe tener formato YYYY-MM-DD (ejemplo: 2026-10-28)"]);
        }
        $r[$campo] = $v . ($campo === 'desde' ? ' 00:00:00' : ' 23:59:59');
    }
    if ($r['desde'] !== null && $r['hasta'] !== null && $r['desde'] > $r['hasta']) {
        responder(400, ["error" => "La fecha 'desde' no puede ser posterior a la fecha 'hasta'"]);
    }
    return $r;
}

// Agrega al WHERE el rango de fechas sobre la columna $columna (viene siempre del código)
function agregarRango(&$where, &$params, $columna, $rango) {
    if ($rango['desde'] !== null) {
        $where[] = "$columna >= ?";
        $params[] = $rango['desde'];
    }
    if ($rango['hasta'] !== null) {
        $where[] = "$columna <= ?";
        $params[] = $rango['hasta'];
    }
}

// true si el cliente pidió ?formato=csv; responde 400 si pidió otro formato
function quiereCsv() {
    if (!isset($_GET['formato'])) {
        return false;
    }
    $f = is_string($_GET['formato']) ? strtolower($_GET['formato']) : '';
    if ($f === 'csv') {
        return true;
    }
    if ($f === 'json') {
        return false;
    }
    responder(400, ["error" => "formato debe ser 'csv' o 'json'"]);
}

// Envía las filas como archivo CSV y termina.
// UTF-8 con BOM y separador ';' para que Excel en español muestre bien tildes y columnas.
function enviarCsv($nombreArchivo, $columnas, $filas) {
    header("Content-Type: text/csv; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    http_response_code(200);
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $columnas, ';', '"', '');
    foreach ($filas as $fila) {
        $linea = [];
        foreach ($columnas as $c) {
            $valor = $fila[$c] ?? '';
            // Evita que Excel ejecute una celda que empieza con = + - @ (inyección de fórmulas)
            if (is_string($valor) && $valor !== '' && strpos("=+-@", $valor[0]) !== false) {
                $valor = "'" . $valor;
            }
            $linea[] = $valor;
        }
        fputcsv($out, $linea, ';', '"', '');
    }
    fclose($out);
    exit;
}
