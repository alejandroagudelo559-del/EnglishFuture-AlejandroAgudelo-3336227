<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

// Si tu database.php llama a la conexión de otra forma, se busca aquí con los nombres más comunes
if (!isset($pdo)) {
    foreach (['conexion', 'conn', 'con', 'db', 'dbh'] as $nombreVariable) {
        if (isset($$nombreVariable) && $$nombreVariable instanceof PDO) {
            $pdo = $$nombreVariable;
            break;
        }
    }
}

const PAGINA_GESTION  = '../../views/categoria/categoria.html';
const ROLES_VALIDOS   = ['Estudiante', 'Docente'];
const ESTADOS_VALIDOS = ['Activo', 'Inactivo'];

/** Responde en JSON y termina. */
function responderJson(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Registro: vuelve a la página con un código de error. Las demás acciones: responde JSON. */
function fallo(string $codigo, int $http, bool $redirigir): void
{
    if ($redirigir) {
        header('Location: ' . PAGINA_GESTION . '?error=' . urlencode($codigo));
        exit;
    }
    responderJson(['ok' => false, 'error' => $codigo], $http);
}

/** Lee un campo POST como texto limpio. */
function campoPost(string $nombre): string
{
    return trim((string)($_POST[$nombre] ?? ''));
}

/**
 * Divide un nombre completo en nombres y apellidos "por intuición".
 * Las partículas (de, del, la...) se pegan a la palabra siguiente: "De La Cruz" cuenta como una pieza.
 * 2 piezas: 1 nombre + 1 apellido | 3 piezas: 1 nombre + 2 apellidos | 4 o más: 2 nombres + el resto.
 * Devuelve null si no hay al menos un nombre y un apellido.
 */
function dividirNombre(string $nombreCompleto): ?array
{
    $palabras = preg_split('/\s+/u', trim($nombreCompleto), -1, PREG_SPLIT_NO_EMPTY);
    $particulas = ['de', 'del', 'la', 'las', 'los', 'san', 'da', 'di', 'van', 'von'];

    $piezas = [];
    $pendiente = '';
    foreach ($palabras as $palabra) {
        $pendiente = ($pendiente === '' ? '' : $pendiente . ' ') . $palabra;
        if (in_array(mb_strtolower($palabra), $particulas, true)) {
            continue; // se pega a la palabra que sigue
        }
        $piezas[] = $pendiente;
        $pendiente = '';
    }
    if ($pendiente !== '') { // el texto terminó en una partícula: se pega a la última pieza
        if ($piezas) {
            $ultima = array_pop($piezas);
            $piezas[] = $ultima . ' ' . $pendiente;
        } else {
            $piezas[] = $pendiente;
        }
    }

    $total = count($piezas);
    if ($total < 2) {
        return null;
    }
    $corte = ($total >= 4) ? 2 : 1;

    return [
        'nombres'   => implode(' ', array_slice($piezas, 0, $corte)),
        'apellidos' => implode(' ', array_slice($piezas, $corte)),
    ];
}

/** Valida los datos. Devuelve el código de error o null si todo está bien. */
function validar(string $documento, string $nombre, string $clase, string $rol, string $estado, bool $esRegistro): ?string
{
    if ($clase === '' || $rol === '' || $estado === '' || ($esRegistro && ($documento === '' || $nombre === ''))) {
        return 'campos_vacios';
    }
    if ($esRegistro) {
        if (!preg_match('/^[A-Za-z0-9]{5,30}$/', $documento)) {
            return 'documento_invalido';
        }
        $partes = dividirNombre($nombre);
        if ($partes === null || mb_strlen($partes['nombres']) > 100 || mb_strlen($partes['apellidos']) > 100) {
            return 'nombre_invalido';
        }
    }
    $largoClase = mb_strlen($clase);
    if ($largoClase < 2 || $largoClase > 100) {
        return 'clase_invalida';
    }
    if (!in_array($rol, ROLES_VALIDOS, true)) {
        return 'rol_invalido';
    }
    if (!in_array($estado, ESTADOS_VALIDOS, true)) {
        return 'estado_invalido';
    }
    return null;
}

// ---------- 1. Qué acción piden y con qué método ----------
$accion     = $_POST['accion'] ?? $_GET['accion'] ?? '';
$esRegistro = ($accion === 'registrar');
$metodo     = $_SERVER['REQUEST_METHOD'] ?? '';

$metodoOk = ($accion === 'listar' && $metodo === 'GET')
         || (in_array($accion, ['registrar', 'editar', 'eliminar'], true) && $metodo === 'POST');

if (!$metodoOk) {
    fallo('metodo_no_permitido', 405, $esRegistro);
}

// ---------- 2. Conexión disponible ----------
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[categoria.php] database.php no entregó una conexión PDO en $pdo');
    fallo('error_servidor', 500, $esRegistro);
}

// ---------- 3. Ejecutar la acción ----------
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    switch ($accion) {

        // ===== LISTAR =====
        case 'listar':
            $stmt = $pdo->query(
                'SELECT id_nuevo_nombre, numero_documento, nombres, apellidos, clase, rol, estado
                   FROM encargado ORDER BY nombres, apellidos'
            );
            $usuarios = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $usuarios[] = [
                    'id'        => (int)$f['id_nuevo_nombre'],
                    'documento' => (string)$f['numero_documento'],
                    'nombre'    => trim($f['nombres'] . ' ' . $f['apellidos']), // nombre completo para la tabla
                    'clase'     => (string)($f['clase'] ?? ''),
                    'rol'       => (string)($f['rol'] ?? ''),
                    'estado'    => (string)$f['estado'],
                ];
            }
            responderJson(['ok' => true, 'usuarios' => $usuarios]);
            break;

        // ===== REGISTRAR (formulario normal + redirección) =====
        case 'registrar':
            $documento = campoPost('numero_documento');
            $nombre    = campoPost('nombre');
            $clase     = campoPost('clase');
            $rol       = campoPost('rol');
            $estado    = campoPost('estado');

            $error = validar($documento, $nombre, $clase, $rol, $estado, true);
            if ($error !== null) {
                fallo($error, 400, true);
            }

            // Ya validado: aquí siempre hay nombre y apellido
            $partes = dividirNombre($nombre) ?? ['nombres' => $nombre, 'apellidos' => ''];

            // Que ese documento no esté ya registrado
            $stmt = $pdo->prepare('SELECT 1 FROM encargado WHERE numero_documento = :doc LIMIT 1');
            $stmt->execute([':doc' => $documento]);
            if ($stmt->fetchColumn()) {
                fallo('ya_registrado', 409, true);
            }

            // Inserción con consulta preparada (correo y teléfono quedan en NULL)
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO encargado (numero_documento, nombres, apellidos, clase, rol, estado)
                     VALUES (:doc, :nombres, :apellidos, :clase, :rol, :estado)'
                );
                $stmt->execute([
                    ':doc'       => $documento,
                    ':nombres'   => $partes['nombres'],
                    ':apellidos' => $partes['apellidos'],
                    ':clase'     => $clase,
                    ':rol'       => $rol,
                    ':estado'    => $estado,
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { // documento duplicado
                    fallo('ya_registrado', 409, true);
                }
                throw $e;
            }

            header('Location: ' . PAGINA_GESTION . '?mensaje=registrado');
            exit;

        // ===== EDITAR (solo clase, rol y estado) =====
        case 'editar':
            $id     = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            $clase  = campoPost('clase');
            $rol    = campoPost('rol');
            $estado = campoPost('estado');

            if ($id === false || $id <= 0) {
                fallo('no_encontrado', 404, false);
            }
            $error = validar('', '', $clase, $rol, $estado, false);
            if ($error !== null) {
                fallo($error, 400, false);
            }

            $stmt = $pdo->prepare('SELECT 1 FROM encargado WHERE id_nuevo_nombre = :id');
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetchColumn()) {
                fallo('no_encontrado', 404, false);
            }

            $stmt = $pdo->prepare(
                'UPDATE encargado SET clase = :clase, rol = :rol, estado = :estado
                  WHERE id_nuevo_nombre = :id'
            );
            $stmt->execute([':clase' => $clase, ':rol' => $rol, ':estado' => $estado, ':id' => $id]);
            responderJson(['ok' => true]);
            break;

        // ===== ELIMINAR =====
        case 'eliminar':
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) {
                fallo('no_encontrado', 404, false);
            }
            $stmt = $pdo->prepare('DELETE FROM encargado WHERE id_nuevo_nombre = :id');
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() === 0) {
                fallo('no_encontrado', 404, false);
            }
            responderJson(['ok' => true]);
            break;
    }
} catch (PDOException $e) {
    // El detalle va al log del servidor; el usuario solo ve un mensaje genérico
    error_log('[categoria.php] ' . $e->getMessage());
    fallo('error_servidor', 500, $esRegistro);
}