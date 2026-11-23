<?php
/**
 * Controlador de Inicio de Sesión - EnglishFuture
 * Ubicación: controllers/seguridad/login.php
 *
 * Flujo: login.html --POST--> login.php --> consulta a MySQL --> sesión --> redirección
 */

// ============================================================
// BLOQUE 0. CONFIGURACIÓN GENERAL
// ============================================================

// Página a la que se envía al usuario cuando inicia sesión correctamente.
// OJO: el proyecto todavía no tiene una página principal definida para usuarios.
// Por ahora apunta a index.html. Cuando exista (por ejemplo views/estudiante/inicio.php),
// cambia SOLO esta línea.
const RUTA_DESTINO_EXITO = '../../index.html';

// Página del formulario de login (para volver cuando hay errores).
const RUTA_LOGIN = '../../views/seguridad/login.html';

// Nunca mostrar errores internos de PHP al usuario; se guardan en el log de Apache/PHP.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Redirige al formulario de login con un código de error y termina el script.
 * El código NO revela detalles internos (solo identifica el tipo de problema).
 */
function volverAlLogin(string $codigo): void
{
    header('Location: ' . RUTA_LOGIN . '?error=' . urlencode($codigo));
    exit;
}

// ============================================================
// BLOQUE 1. SOLO SE ACEPTA EL MÉTODO POST
// ============================================================
// Si alguien escribe la URL del controlador directamente en el navegador (método GET),
// no se procesa nada y se le devuelve al formulario.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    volverAlLogin('metodo_no_permitido');
}

// ============================================================
// BLOQUE 2. CAPTURA Y VALIDACIÓN DE LOS DATOS RECIBIDOS
// ============================================================
// trim() quita espacios sobrantes; el correo se pasa a minúsculas para comparar igual que en el registro.
// La contraseña NO se recorta ni se modifica: los espacios pueden ser parte de ella.
//
// IMPORTANTE: la variable NO se llama $password a propósito. El archivo config/database.php
// suele usar $password para la clave de MySQL (en XAMPP es vacía) y, al incluirlo,
// sobrescribiría esta variable dejándola vacía. Por eso se usa un nombre propio.
$correo             = strtolower(trim($_POST['email'] ?? ''));
$passwordIngresada  = $_POST['password'] ?? '';

// Ambos campos deben venir con contenido
if ($correo === '' || $passwordIngresada === '') {
    volverAlLogin('campos_vacios');
}

// Si el correo ni siquiera tiene formato válido, no vale la pena consultar la base de datos.
// Se usa el mismo mensaje genérico para no dar pistas.
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    volverAlLogin('credenciales_invalidas');
}

// ============================================================
// BLOQUE 3. CONEXIÓN A LA BASE DE DATOS (la que ya existe)
// ============================================================
require_once __DIR__ . '/../../config/database.php';

/** @var PDO|null $pdo */
if (!isset($pdo)) {
    if (isset($conn) && $conn instanceof PDO) {
        $pdo = $conn;
    } elseif (isset($conexion) && $conexion instanceof PDO) {
        $pdo = $conexion;
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('Login EnglishFuture: config/database.php no entregó una instancia PDO válida.');
    volverAlLogin('error_servidor');
}

// Que PDO lance excepciones ante cualquier error SQL (para capturarlas en el try/catch)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ============================================================
// BLOQUE 4. BÚSQUEDA DEL USUARIO Y VERIFICACIÓN DE CREDENCIALES
// ============================================================
try {
    // Consulta preparada: el correo viaja como parámetro (:correo) y nunca se pega
    // dentro del texto SQL. Así se evita la inyección SQL.
    // La columna `id_estudiante/docente` lleva comillas invertidas por contener una barra.
    $sql = "SELECT `id_estudiante/docente` AS id_usuario,
                   nombres,
                   apellidos,
                   contrasena,
                   estado,
                   id_rol
            FROM estudiante
            WHERE correo = :correo
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':correo', $correo, PDO::PARAM_STR);
    $stmt->execute();
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // password_verify() compara la contraseña escrita con el hash guardado.
    // Si el usuario no existe O la contraseña no coincide, se responde con el MISMO
    // mensaje genérico, para que un atacante no sepa cuál de los dos datos falló.
    if (!$usuario || !password_verify($passwordIngresada, $usuario['contrasena'])) {
        volverAlLogin('credenciales_invalidas');
    }

    // Las credenciales son correctas; ahora se revisa que la cuenta esté activa.
    // (Este mensaje solo lo ve quien ya conoce la contraseña correcta.)
    if ($usuario['estado'] !== 'activo') {
        volverAlLogin('cuenta_inactiva');
    }

} catch (PDOException $e) {
    // El detalle técnico va al log del servidor, nunca al navegador.
    error_log('Login EnglishFuture - error de base de datos: ' . $e->getMessage());
    volverAlLogin('error_servidor');
}

// ============================================================
// BLOQUE 5. CREACIÓN DE LA SESIÓN SEGURA
// ============================================================
// Cookie de sesión con protecciones:
//  - httponly: JavaScript no puede leer la cookie (frena el robo por XSS)
//  - samesite: el navegador no la envía desde otros sitios (frena CSRF)
//  - secure: solo por HTTPS (se activa automáticamente si el sitio usa HTTPS)
$usaHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $usaHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// No aceptar identificadores de sesión inventados por el navegador
ini_set('session.use_strict_mode', '1');

session_start();

// Regenerar el ID de sesión ANTES de guardar los datos del usuario.
// Evita el ataque de "fijación de sesión" (que alguien te imponga un ID conocido).
session_regenerate_id(true);

// Se guarda en la sesión SOLO lo necesario. Nunca la contraseña ni su hash.
// El rol sale de la base de datos, jamás de lo que envíe el navegador.
$_SESSION['id_usuario'] = (int) $usuario['id_usuario'];
$_SESSION['nombre']     = $usuario['nombres'] . ' ' . $usuario['apellidos'];
$_SESSION['id_rol']     = (int) $usuario['id_rol'];

// ============================================================
// BLOQUE 6. REDIRECCIÓN DEL USUARIO AUTENTICADO
// ============================================================
header('Location: ' . RUTA_DESTINO_EXITO);
exit;