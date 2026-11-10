<?php
// Compatibilidad: toda la lógica de sesión vive en auth.php.
// Este archivo NO redefine obtenerToken(), hashToken(), crearSesion() ni usuarioActual().
require_once __DIR__ . '/auth.php';

// Roles de la aplicación, de menos a más permisos (coinciden con auth.php)
const ROLES = ['usuario', 'encargado', 'administrador'];

// 401: "no sé quién sos" (falta iniciar sesión o el token venció)
function requerirLogin($conexion)
{
    $usuario = usuarioActual($conexion);
    if ($usuario === null) {
        header("WWW-Authenticate: Bearer");
        rechazar(401, "Debe iniciar sesión");
    }
    return $usuario; // ['id' => ..., 'usuario' => ..., 'rol' => ...]
}

// 403: "sé quién sos, pero tu rol no puede hacer esto"
// Ejemplo: requerirRol($conexion, ['administrador']);
function requerirRol($conexion, $rolesPermitidos)
{
    $usuario = requerirLogin($conexion);
    if (!in_array($usuario['rol'], $rolesPermitidos, true)) {
        rechazar(403, "No tiene permisos para esta acción");
    }
    return $usuario;
}