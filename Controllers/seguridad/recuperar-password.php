<?php
/**
 * Controlador de Recuperación de Contraseña - EnglishFuture
 * Ubicación: controllers/seguridad/recuperar-password.php
 *
 * Un solo archivo con CUATRO operaciones. Cada formulario indica cuál con el campo oculto "accion":
 *
 *   accion = solicitar    -> recibe el correo y muestra un captcha (una suma sencilla)
 *   accion = captcha      -> comprueba la respuesta (5 intentos; si se agotan, espera 1 minuto)
 *   accion = documento    -> comprueba el número de documento de esa cuenta (5 intentos; luego espera 1 minuto)
 *   accion = restablecer  -> guarda la contraseña nueva (solo si pasó los pasos anteriores)
 *
 * El correo se escribe UNA sola vez (paso 1) y queda guardado en la sesión del servidor.
 */

// ============================================================
// BLOQUE 0. CONFIGURACIÓN
// ============================================================
const MAX_INTENTOS        = 5;   // intentos permitidos en el captcha y en el documento
const MINUTOS_BLOQUEO     = 1;   // espera cuando se agotan los intentos
const MINUTOS_PARA_TERMINAR = 10; // tiempo para poner la contraseña nueva tras verificar el documento

const RUTA_PAGINA = '../../views/seguridad/recuperar-password.html';

// Nunca mostrar errores internos al usuario; se guardan en el log del servidor.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ============================================================
// BLOQUE 1. FUNCIONES DE APOYO
// ============================================================

/**
 * Vuelve a la página de recuperación con parámetros en la dirección
 * (por ejemplo "paso=documento&error=datos_invalidos") y termina el script.
 * Esos parámetros solo sirven para que la página muestre el paso y el mensaje correctos;
 * la seguridad real la decide este archivo con la sesión y la base de datos.
 */
function volver(string $parametros = ''): void
{
    header('Location: ' . RUTA_PAGINA . ($parametros !== '' ? '?' . $parametros : ''));
    exit;
}

/**
 * Inicia la sesión PHP con cookie protegida. Aquí se recuerda en qué paso va el usuario.
 */
function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $usaHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $usaHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/**
 * Borra de la sesión los datos del proceso de recuperación.
 * (rec_captcha_bloqueo NO se borra: así el bloqueo de 1 minuto no se evita reiniciando el proceso.)
 */
function limpiarSesionRecuperacion(): void
{
    unset(
        $_SESSION['rec_correo'],
        $_SESSION['rec_captcha_respuesta'],
        $_SESSION['rec_captcha_intentos'],
        $_SESSION['rec_captcha_ok'],
        $_SESSION['rec_verificado_id'],
        $_SESSION['rec_verificado_hasta']
    );
}

/**
 * Reutiliza la conexión PDO que ya existe en config/database.php.
 * Se hace dentro de una función para que las variables de ese archivo
 * (por ejemplo $password) no choquen con las de este controlador.
 */
function obtenerConexion(): PDO
{
    require_once __DIR__ . '/../../config/database.php';

    // database.php puede llamar a su conexión $pdo, $conn o $conexion; se acepta cualquiera.
    if (!isset($pdo)) {
        if (isset($conn) && $conn instanceof PDO) {
            $pdo = $conn;
        } elseif (isset($conexion) && $conexion instanceof PDO) {
            $pdo = $conexion;
        }
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        error_log('Recuperación EnglishFuture: config/database.php no entregó una instancia PDO válida.');
        volver('error=error_servidor');
    }

    // Que PDO lance excepciones ante cualquier error SQL (se capturan con try/catch)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Crea una suma sencilla, guarda la respuesta correcta en la sesión del servidor
 * y devuelve los números para mostrarlos (por ejemplo "a=7&b=4").
 */
function nuevaPregunta(): string
{
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['rec_captcha_respuesta'] = $a + $b;
    return 'a=' . $a . '&b=' . $b;
}

// ============================================================
// BLOQUE 2. PASO 1: RECIBIR EL CORREO Y MOSTRAR EL CAPTCHA
// ============================================================
function iniciarRecuperacion(): void
{
    iniciarSesion();

    $correo = strtolower(trim($_POST['email'] ?? ''));

    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        volver('error=correo_invalido');
    }

    // Si se agotaron los intentos del captcha, hay que esperar a que pase el minuto.
    if ((int) ($_SESSION['rec_captcha_bloqueo'] ?? 0) > time()) {
        volver('error=captcha_bloqueado');
    }

    // Se empieza el proceso de cero. No se consulta la base de datos en este paso:
    // así la página responde igual exista o no el correo y no revela qué correos tienen cuenta.
    session_regenerate_id(true);
    limpiarSesionRecuperacion();
    $_SESSION['rec_correo'] = $correo;
    $_SESSION['rec_captcha_intentos'] = 0;

    volver('paso=captcha&' . nuevaPregunta());
}

// ============================================================
// BLOQUE 3. PASO 2: COMPROBAR EL CAPTCHA
// ============================================================
function verificarCaptcha(): void
{
    iniciarSesion();

    if (!isset($_SESSION['rec_correo'], $_SESSION['rec_captcha_respuesta'])) {
        volver('error=sesion_expirada');
    }

    if ((int) ($_SESSION['rec_captcha_bloqueo'] ?? 0) > time()) {
        volver('error=captcha_bloqueado');
    }

    // La respuesta se compara con la que quedó guardada en el servidor (no con lo que muestre la página).
    $respuesta  = trim($_POST['captcha'] ?? '');
    $esCorrecta = preg_match('/^[0-9]{1,2}$/', $respuesta) === 1
               && (int) $respuesta === (int) $_SESSION['rec_captcha_respuesta'];

    if ($esCorrecta) {
        session_regenerate_id(true);
        unset($_SESSION['rec_captcha_respuesta']);
        $_SESSION['rec_captcha_intentos'] = 0;
        $_SESSION['rec_captcha_ok'] = true;
        volver('paso=documento');
    }

    // Respuesta incorrecta: se cuenta el intento y se genera una pregunta nueva.
    $intentos = (int) ($_SESSION['rec_captcha_intentos'] ?? 0) + 1;
    $_SESSION['rec_captcha_intentos'] = $intentos;

    if ($intentos >= MAX_INTENTOS) {
        // Se agotaron los intentos: bloqueo de 1 minuto y se vuelve al inicio.
        $_SESSION['rec_captcha_bloqueo'] = time() + (MINUTOS_BLOQUEO * 60);
        limpiarSesionRecuperacion();
        volver('error=captcha_bloqueado');
    }

    volver('paso=captcha&' . nuevaPregunta() . '&error=captcha_invalido&restan=' . (MAX_INTENTOS - $intentos));
}

// ============================================================
// BLOQUE 4. PASO 3: COMPROBAR EL NÚMERO DE DOCUMENTO
// ============================================================
function verificarDocumento(): void
{
    iniciarSesion();

    // Solo se llega aquí habiendo pasado el captcha en esta misma sesión.
    if (!isset($_SESSION['rec_correo']) || ($_SESSION['rec_captcha_ok'] ?? false) !== true) {
        volver('error=sesion_expirada');
    }

    $correo = $_SESSION['rec_correo'];

    // El número de documento se guarda en mayúsculas y sin símbolos (así lo hace el registro).
    $documento = strtoupper(trim($_POST['numero_documento'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{5,20}$/', $documento)) {
        volver('paso=documento&error=datos_invalidos');
    }

    $pdo = obtenerConexion();

    try {
        $stmt = $pdo->prepare("SELECT `id_estudiante/docente` AS id_usuario, numero_documento
                               FROM estudiante
                               WHERE correo = :correo AND estado = 'activo'
                               LIMIT 1");
        $stmt->bindValue(':correo', $correo, PDO::PARAM_STR);
        $stmt->execute();
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Mismo mensaje si la cuenta no existe: no se revela nada.
        if (!$usuario) {
            volver('paso=documento&error=datos_invalidos');
        }
        $idUsuario = (int) $usuario['id_usuario'];

        // Los intentos con el documento se cuentan en la BASE DE DATOS (por cuenta), no en la sesión,
        // para que no se puedan evitar abriendo otro navegador o borrando las cookies.

        // a) Si el bloqueo ya terminó, se reinician los intentos.
        $stmtReinicio = $pdo->prepare("UPDATE estudiante
                                       SET intentos_recuperacion = 0, bloqueo_recuperacion = NULL
                                       WHERE `id_estudiante/docente` = :id
                                         AND bloqueo_recuperacion IS NOT NULL
                                         AND bloqueo_recuperacion <= NOW()");
        $stmtReinicio->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $stmtReinicio->execute();

        // b) Se cuenta este intento ANTES de comparar. Solo cuenta si no hay bloqueo y quedan intentos.
        $stmtIntento = $pdo->prepare("UPDATE estudiante
                                      SET intentos_recuperacion = intentos_recuperacion + 1
                                      WHERE `id_estudiante/docente` = :id
                                        AND bloqueo_recuperacion IS NULL
                                        AND intentos_recuperacion < " . MAX_INTENTOS);
        $stmtIntento->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $stmtIntento->execute();

        if ($stmtIntento->rowCount() !== 1) {
            // La cuenta está bloqueada (o ya agotó sus intentos): se asegura el bloqueo.
            $stmtBloqueo = $pdo->prepare("UPDATE estudiante
                                          SET bloqueo_recuperacion = DATE_ADD(NOW(), INTERVAL " . MINUTOS_BLOQUEO . " MINUTE)
                                          WHERE `id_estudiante/docente` = :id AND bloqueo_recuperacion IS NULL");
            $stmtBloqueo->bindValue(':id', $idUsuario, PDO::PARAM_INT);
            $stmtBloqueo->execute();
            limpiarSesionRecuperacion();
            volver('error=bloqueado');
        }

        // c) Se compara el documento escrito con el guardado (comparación de tiempo constante).
        if (hash_equals(strtoupper((string) $usuario['numero_documento']), $documento)) {
            // Correcto: se reinician los intentos de esa cuenta.
            $stmtOk = $pdo->prepare("UPDATE estudiante
                                     SET intentos_recuperacion = 0, bloqueo_recuperacion = NULL
                                     WHERE `id_estudiante/docente` = :id");
            $stmtOk->bindValue(':id', $idUsuario, PDO::PARAM_INT);
            $stmtOk->execute();

            session_regenerate_id(true);
            $_SESSION['rec_verificado_id']    = $idUsuario;
            $_SESSION['rec_verificado_hasta'] = time() + (MINUTOS_PARA_TERMINAR * 60);
            volver('paso=clave');
        }

        // Incorrecto: si fue el último intento permitido, se bloquea de una vez.
        $stmtUltimo = $pdo->prepare("UPDATE estudiante
                                     SET bloqueo_recuperacion = DATE_ADD(NOW(), INTERVAL " . MINUTOS_BLOQUEO . " MINUTE)
                                     WHERE `id_estudiante/docente` = :id
                                       AND bloqueo_recuperacion IS NULL
                                       AND intentos_recuperacion >= " . MAX_INTENTOS);
        $stmtUltimo->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $stmtUltimo->execute();

        if ($stmtUltimo->rowCount() === 1) {
            limpiarSesionRecuperacion();
            volver('error=bloqueado');
        }

        // Todavía quedan intentos: se calcula cuántos para avisar.
        $stmtCuenta = $pdo->prepare("SELECT intentos_recuperacion FROM estudiante WHERE `id_estudiante/docente` = :id");
        $stmtCuenta->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $stmtCuenta->execute();
        $restan = max(0, MAX_INTENTOS - (int) $stmtCuenta->fetchColumn());

        volver('paso=documento&error=datos_invalidos&restan=' . $restan);

    } catch (PDOException $e) {
        error_log('Recuperación EnglishFuture (documento) - error de base de datos: ' . $e->getMessage());
        volver('paso=documento&error=error_servidor');
    }
}

// ============================================================
// BLOQUE 5. PASO 4: GUARDAR LA CONTRASEÑA NUEVA
// ============================================================
function restablecerPassword(): void
{
    iniciarSesion();

    // Solo se puede llegar aquí si el paso 3 fue superado en esta misma sesión.
    $idUsuario    = (int) ($_SESSION['rec_verificado_id'] ?? 0);
    $vigenteHasta = (int) ($_SESSION['rec_verificado_hasta'] ?? 0);

    if ($idUsuario <= 0 || $vigenteHasta < time()) {
        limpiarSesionRecuperacion();
        volver('error=sesion_expirada');
    }

    // Las contraseñas NO se llaman $password a propósito: config/database.php puede usar ese nombre.
    $passwordNueva      = $_POST['password'] ?? '';
    $passwordConfirmada = $_POST['confirmPassword'] ?? '';

    if ($passwordNueva === '' || $passwordConfirmada === '') {
        volver('paso=clave&error=campos_vacios');
    }

    if (mb_strlen($passwordNueva, 'UTF-8') < 8) {
        volver('paso=clave&error=password_corta');
    }

    if ($passwordNueva !== $passwordConfirmada) {
        volver('paso=clave&error=password_no_coincide');
    }

    $pdo = obtenerConexion();

    try {
        // La contraseña nueva se cifra con password_hash() antes de guardarse.
        $passwordHash = password_hash($passwordNueva, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE estudiante
                               SET contrasena = :contrasena
                               WHERE `id_estudiante/docente` = :id
                                 AND estado = 'activo'");
        $stmt->bindValue(':contrasena', $passwordHash, PDO::PARAM_STR);
        $stmt->bindValue(':id', $idUsuario, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() !== 1) {
            limpiarSesionRecuperacion();
            volver('error=sesion_expirada');
        }

    } catch (PDOException $e) {
        error_log('Recuperación EnglishFuture (restablecer) - error de base de datos: ' . $e->getMessage());
        volver('paso=clave&error=error_servidor');
    }

    // Proceso terminado: se borran los datos de la sesión (así no se puede repetir) y la página muestra el éxito.
    limpiarSesionRecuperacion();
    volver('exito=1');
}

// ============================================================
// BLOQUE 6. PUNTO DE ENTRADA: SOLO POST Y SEGÚN LA ACCIÓN
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    volver('error=metodo_no_permitido');
}

$accion = $_POST['accion'] ?? '';

if ($accion === 'solicitar') {
    iniciarRecuperacion();
} elseif ($accion === 'captcha') {
    verificarCaptcha();
} elseif ($accion === 'documento') {
    verificarDocumento();
} elseif ($accion === 'restablecer') {
    restablecerPassword();
} else {
    // Acción desconocida o ausente: no se hace nada.
    volver('error=metodo_no_permitido');
}