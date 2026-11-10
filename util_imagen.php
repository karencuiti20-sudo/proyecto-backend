<?php
// Funciones compartidas por foto_perfil.php y foto_equipo.php.
// Las imágenes viajan como "data URL" (data:image/jpeg;base64,....) y se guardan como bytes en MySQL.

const TAMANO_MAXIMO_IMAGEN = 1048576;   // 1 MB
const LADO_MAXIMO_IMAGEN   = 4000;      // píxeles
const MIME_IMAGEN = ['image/jpeg', 'image/png', 'image/webp'];

// Recibe "data:image/...;base64,XXXX" (o solo el base64), valida y devuelve los bytes
function decodificarImagen($valor) {
    if (!is_string($valor) || $valor === '') {
        responder(400, ["error" => "Debe enviar la imagen"]);
    }
    if (preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $valor)) {
        $valor = substr($valor, strpos($valor, ',') + 1);
    }
    $bin = base64_decode($valor, true);
    if ($bin === false || $bin === '') {
        responder(400, ["error" => "La imagen debe estar codificada en base64"]);
    }
    if (strlen($bin) > TAMANO_MAXIMO_IMAGEN) {
        responder(413, ["error" => "La imagen es demasiado grande (máximo 1 MB)"]);
    }
    // El tipo REAL sale del contenido, no de lo que diga el cliente
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);
    if (!in_array($mime, MIME_IMAGEN, true)) {
        responder(415, ["error" => "Formato no permitido. Usá JPG, PNG o WEBP"]);
    }
    $info = getimagesizefromstring($bin);
    if ($info === false) {
        responder(415, ["error" => "El archivo no es una imagen válida"]);
    }
    if ($info[0] > LADO_MAXIMO_IMAGEN || $info[1] > LADO_MAXIMO_IMAGEN) {
        responder(400, ["error" => "La imagen no puede superar " . LADO_MAXIMO_IMAGEN . " x " . LADO_MAXIMO_IMAGEN . " píxeles"]);
    }
    return $bin;
}

// Bytes guardados en MySQL -> "data:image/jpeg;base64,...." (o null si no hay imagen)
function aDataUrl($bin) {
    if ($bin === false || $bin === null || $bin === '') {
        return null;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);
    return "data:$mime;base64," . base64_encode($bin);
}