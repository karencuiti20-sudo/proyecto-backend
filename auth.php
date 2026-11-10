<?php
// Autenticación por TOKEN (Authorization: Bearer <token>) y autorización por rol.
// Se incluye DESPUÉS de conexion.php y se usa con: autorizar('nombre_del_recurso');

const DURACION_SESION = 7200; // 2 horas, en segundos

// Clave que usa el Arduino para registrar alertas (header X-API-KEY). CAMBIARLA.
const CLAVE_DISPOSITIVO = 'cambiar-esta-clave-del-arduino';

// Rol del sistema según la tabla de rol que tenga el usuario, de mayor a menor privilegio.
const ROLES_TABLAS = [
    'administrador' => 'Funcionarios',
    'encargado'     => 'Docentes',
    'usuario'       => 'Estudiantes'
];

// Qué roles pueden usar cada método HTTP de cada recurso
const PERMISOS = [
    'equipos' => [
        'GET'    => ['administrador', 'encargado', 'usuario'],
        'POST'   => ['administrador', 'encargado'],
        'PUT'    => ['administrador', 'encargado'],
        'DELETE' => ['administrador']
    ],
    'prestamos' => [
        // El rol "usuario" ve, pide y devuelve solo SUS préstamos
        // (esa restricción la aplica prestamos.php).
        'GET'    => ['administrador', 'encargado', 'usuario'],
        'POST'   => ['administrador', 'encargado', 'usuario'],
        'PUT'    => ['administrador', 'encargado', 'usuario'],
        'DELETE' => ['administrador']
    ],
    'usuarios' => [
        'GET'    => ['administrador', 'encargado'],
        'POST'   => ['administrador'],
        'PUT'    => ['administrador'],
        'DELETE' => ['administrador']
    ],
    'telefonos' => [
        'GET'    => ['administrador', 'encargado'],
        'POST'   => ['administrador'],
        'PUT'    => ['administrador'],
        'DELETE' => ['administrador']
    ],
    'roles' => [
        'GET'    => ['administrador'],
        'POST'   => ['administrador'],
        'DELETE' => ['administrador']
    ],
    'cursos' => [
        'GET'    => ['administrador', 'encargado', 'usuario'],
        'POST'   => ['administrador'],
        'PUT'    => ['administrador'],
        'DELETE' => ['administrador']
    ],
    'inscripciones' => [
        'GET'    => ['administrador', 'encargado'],
        'POST'   => ['administrador'],
        'DELETE' => ['administrador']
    ],
    'reportes' => [
        'GET'    => ['administrador', 'encargado'],
        'POST'   => ['administrador', 'encargado'],
        'PUT'    => ['administrador', 'encargado'],
        'DELETE' => ['administrador']
    ],
    'alertas' => [
        'GET'    => ['administrador', 'encargado'],
        'POST'   => ['administrador', 'encargado'],   // el Arduino entra con X-API-KEY
        'PUT'    => ['administrador', 'encargado'],
        'DELETE' => ['administrador']
    ]
];

function rechazar($codigo, $mensaje) {
    http_response_code($codigo);
    echo json_encode(["error" => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

// Lee el token de la cabecera "Authorization: Bearer <token>".
// Devuelve el token (64 caracteres hexadecimales) o null si no vino o es inválido.
function obtenerToken() {
    $cabecera = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    // Algunos Apache no pasan la cabecera a PHP: probamos con getallheaders()
    if ($cabecera === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $nombre => $valor) {
            if (strtolower($nombre) === 'authorization') {
                $cabecera = $valor;
            }
        }
    }
    if (preg_match('/^Bearer ([a-f0-9]{64})$/', $cabecera, $m)) {
        return $m[1];
    }
    return null;
}

// En la base se guarda el HASH del token, nunca el token en sí
function hashToken($token) {
    return hash('sha256', $token);
}

// Crea una sesión nueva y devuelve el token (se le envía al cliente una sola vez)
function crearSesion($conexion, $idUsuario) {
    $token = bin2hex(random_bytes(32)); // 256 bits aleatorios
    $expira = date('Y-m-d H:i:s', time() + DURACION_SESION);
    $stmt = $conexion->prepare(
        "INSERT INTO Sesiones (token_hash, fk_id_usuario, expira_en) VALUES (?, ?, ?)"
    );
    $stmt->execute([hashToken($token), $idUsuario, $expira]);
    return $token;
}

function claveDispositivoValida() {
    $clave = $_SERVER['HTTP_X_API_KEY'] ?? '';
    return $clave !== '' && hash_equals(CLAVE_DISPOSITIVO, $clave);
}

// Devuelve el rol de mayor privilegio de un usuario, o null si no tiene ninguno
function buscarRol($conexion, $idUsuario) {
    foreach (ROLES_TABLAS as $rol => $tabla) {
        $stmt = $conexion->prepare("SELECT 1 FROM $tabla WHERE fk_id_usuario = ?");
        $stmt->execute([$idUsuario]);
        if ($stmt->fetchColumn()) {
            return $rol;
        }
    }
    return null;
}

// Devuelve [id, usuario, rol] del dueño del token, o null si no hay sesión válida
// (sin token, token desconocido, vencido, o usuario inactivo)
function usuarioActual($conexion) {
    $token = obtenerToken();
    if ($token === null) {
        return null;
    }
    $stmt = $conexion->prepare(
        "SELECT u.id_usuario, u.usuario
         FROM Sesiones s
         JOIN Usuarios u ON u.id_usuario = s.fk_id_usuario
         WHERE s.token_hash = ? AND s.expira_en > ? AND u.estado = 'Activo'"
    );
    $stmt->execute([hashToken($token), date('Y-m-d H:i:s')]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }
    $rol = buscarRol($conexion, $u['id_usuario']);
    if ($rol === null) {
        return null;
    }
    return [
        'id'      => (int) $u['id_usuario'],
        'usuario' => $u['usuario'],
        'rol'     => $rol
    ];
}

// Corta la petición si no hay token válido o si el rol no tiene permiso para ese método
function autorizar($recurso) {
    global $conexion;
    $metodo = $_SERVER['REQUEST_METHOD'];

    // El Arduino puede registrar alertas con su clave, sin token
    if ($recurso === 'alertas' && $metodo === 'POST' && claveDispositivoValida()) {
        return;
    }

    if (!isset(PERMISOS[$recurso][$metodo])) {
        return; // método no soportado: el propio archivo responde 405
    }

    $usuario = usuarioActual($conexion);
    if ($usuario === null) {
        header("WWW-Authenticate: Bearer");
        rechazar(401, "Debe iniciar sesión");
    }

    if (!in_array($usuario['rol'], PERMISOS[$recurso][$metodo], true)) {
        rechazar(403, "No tiene permisos para esta acción");
    }
}