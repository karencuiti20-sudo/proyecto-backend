<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");


// Preflight: el navegador pregunta con OPTIONS antes de enviar la petición real.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Envía una respuesta JSON con el código HTTP indicado y termina el script
function responder($codigo, $datos) {
    http_response_code($codigo);
    echo json_encode($datos);
    exit;
}

// Lee el body de la petición y comprueba que sea un JSON válido
function leerJson() {
    $datos = json_decode(file_get_contents("php://input"), true);
    if (!is_array($datos)) {
        responder(400, ["error" => "El cuerpo de la petición debe ser un JSON válido"]);
    }
    return $datos;
}

// Comprueba que venga un id y que sea un entero positivo
function validarId() {
    if (!isset($_GET['id'])) {
        responder(400, ["error" => "Debe indicar el ID del producto"]);
    }
    if (!is_string($_GET['id']) || !ctype_digit($_GET['id'])) {
        responder(400, ["error" => "El id debe ser un número entero"]);
    }
    return (int) $_GET['id'];
}

// ---- Fotos de los productos ----

// Carpeta donde se guardan las fotos subidas
const CARPETA_UPLOADS = __DIR__ . '/uploads/';

// Borra del disco el archivo de una foto (si existe). basename() evita rutas raras.
function borrarArchivoImagen($nombre) {
    if (!empty($nombre)) {
        $ruta = CARPETA_UPLOADS . basename($nombre);
        if (is_file($ruta)) {
            unlink($ruta);
        }
    }
}