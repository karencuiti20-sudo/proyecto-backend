<?php
require_once "comun.php";      // CORS, responder(), validarId(), CARPETA_UPLOADS, borrarArchivoImagen()
require_once "conexion.php";
require_once "seguridad.php";  // requerirLogin(), requerirRol()

const TAMANO_MAXIMO = 2 * 1024 * 1024; // 2 MB
const LADO_MAXIMO   = 4000;            // píxeles de ancho o de alto

// Tipos permitidos: tipo REAL detectado => extensión que NOSOTROS elegimos
const TIPOS_PERMITIDOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp'
];

// Devuelve [id, imagen] del producto o false si no existe
function buscarProducto($conexion, $id) {
    $stmt = $conexion->prepare("SELECT id, imagen FROM productos WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {

        // POST /imagen.php?id=4 -> subir (o reemplazar) la foto del producto 4
        case 'POST':
            requerirRol($conexion, ['admin', 'editor']); // sin sesión: 401; sin permiso: 403

            $id = validarId();
            $producto = buscarProducto($conexion, $id);
            if (!$producto) {
                responder(404, ["mensaje" => "Producto no encontrado"]);
            }

            // Si el archivo supera post_max_size, PHP descarta TODO el cuerpo:
            // $_FILES y $_POST llegan vacíos aunque Content-Length sea grande.
            if (empty($_FILES) && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                responder(413, ["error" => "El archivo es demasiado grande (máximo 2 MB)"]);
            }

            // El archivo debe venir en el campo "imagen" y ser UNO solo
            if (!isset($_FILES['imagen']) || is_array($_FILES['imagen']['error'])) {
                responder(400, ["error" => "Debe enviar un archivo en el campo 'imagen'"]);
            }
            $archivo = $_FILES['imagen'];

            // 1) ¿PHP lo recibió bien?
            if ($archivo['error'] === UPLOAD_ERR_NO_FILE) {
                responder(400, ["error" => "Debe enviar un archivo en el campo 'imagen'"]);
            }
            if ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
                responder(413, ["error" => "El archivo es demasiado grande (máximo 2 MB)"]);
            }
            if ($archivo['error'] !== UPLOAD_ERR_OK) {
                responder(500, ["error" => "No se pudo recibir el archivo"]);
            }

            // 2) Tamaño y origen
            if ($archivo['size'] > TAMANO_MAXIMO) {
                responder(413, ["error" => "El archivo es demasiado grande (máximo 2 MB)"]);
            }
            if (!is_uploaded_file($archivo['tmp_name'])) {
                responder(400, ["error" => "Archivo no válido"]);
            }

            // 3) Tipo REAL: se lee el contenido del archivo. Ni el nombre ni
            //    $archivo['type'] sirven: los escribe el cliente y se falsifican.
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($archivo['tmp_name']);
            if (!isset(TIPOS_PERMITIDOS[$mime])) {
                responder(415, ["error" => "Formato no permitido. Usá JPG, PNG o WEBP"]);
            }

            // 4) Que de verdad sea una imagen decodificable y de tamaño razonable
            $info = getimagesize($archivo['tmp_name']);
            if ($info === false) {
                responder(415, ["error" => "El archivo no es una imagen válida"]);
            }
            if ($info[0] > LADO_MAXIMO || $info[1] > LADO_MAXIMO) {
                responder(400, ["error" => "La imagen no puede superar " . LADO_MAXIMO . " x " . LADO_MAXIMO . " píxeles"]);
            }

            // 5) Nombre aleatorio + extensión elegida por nosotros (nunca la del usuario)
            $nombreNuevo = bin2hex(random_bytes(16)) . '.' . TIPOS_PERMITIDOS[$mime];

            if (!is_dir(CARPETA_UPLOADS)) {
                mkdir(CARPETA_UPLOADS, 0755, true);
            }
            if (!move_uploaded_file($archivo['tmp_name'], CARPETA_UPLOADS . $nombreNuevo)) {
                responder(500, ["error" => "No se pudo guardar el archivo"]);
            }

            // 6) Guardamos el nombre en la base. Si falla, no dejamos el archivo huérfano.
            try {
                $stmt = $conexion->prepare("UPDATE productos SET imagen = ? WHERE id = ?");
                $stmt->execute([$nombreNuevo, $id]);
            } catch (PDOException $e) {
                borrarArchivoImagen($nombreNuevo);
                throw $e;
            }

            // 7) Recién ahora borramos la foto anterior (si había)
            borrarArchivoImagen($producto['imagen']);

            responder(200, [
                "mensaje" => "Imagen guardada correctamente",
                "imagen" => $nombreNuevo
            ]);
            break;

        // DELETE /imagen.php?id=4 -> quitar la foto del producto 4
        case 'DELETE':
            requerirRol($conexion, ['admin', 'editor']); // sin sesión: 401; sin permiso: 403

            $id = validarId();
            $producto = buscarProducto($conexion, $id);
            if (!$producto) {
                responder(404, ["mensaje" => "Producto no encontrado"]);
            }
            if (empty($producto['imagen'])) {
                responder(404, ["mensaje" => "El producto no tiene imagen"]);
            }

            $stmt = $conexion->prepare("UPDATE productos SET imagen = NULL WHERE id = ?");
            $stmt->execute([$id]);
            borrarArchivoImagen($producto['imagen']);

            responder(200, ["mensaje" => "Imagen eliminada correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $e) {
    responder(500, ["error" => "Error interno del servidor"]);
}