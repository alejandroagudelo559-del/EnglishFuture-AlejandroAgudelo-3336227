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

const DIAS_VALIDOS    = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const ESTADOS_VALIDOS = ['Activo', 'Inactivo'];

/** Responde en JSON y termina. */
function responderJson(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Responde con un error en JSON (el JS lo traduce a un mensaje). */
function fallo(string $codigo, int $http): void
{
    responderJson(['ok' => false, 'error' => $codigo], $http);
}

/** Lee un campo POST como texto limpio. */
function campoPost(string $nombre): string
{
    return trim((string)($_POST[$nombre] ?? ''));
}

/** Valida los datos del horario. Devuelve el código de error o null si todo está bien. */
function validar(string $curso, string $docente, string $dia, string $inicio, string $fin, string $estado): ?string
{
    if ($curso === '' || $docente === '' || $dia === '' || $inicio === '' || $fin === '' || $estado === '') {
        return 'campos_vacios';
    }
    $largoCurso = mb_strlen($curso);
    if ($largoCurso < 2 || $largoCurso > 100) {
        return 'curso_invalido';
    }
    $largoDocente = mb_strlen($docente);
    if ($largoDocente < 2 || $largoDocente > 100) {
        return 'docente_invalido';
    }
    if (!in_array($dia, DIAS_VALIDOS, true)) {
        return 'dia_invalido';
    }
    if (!in_array($estado, ESTADOS_VALIDOS, true)) {
        return 'estado_invalido';
    }
    $formatoHora = '/^([01]\d|2[0-3]):[0-5]\d$/';
    if (!preg_match($formatoHora, $inicio) || !preg_match($formatoHora, $fin)) {
        return 'hora_invalida';
    }
    if ($fin <= $inicio) { // "HH:MM" se puede comparar como texto
        return 'rango_horas_invalido';
    }
    return null;
}

/** ¿El docente ya tiene otra clase ACTIVA ese día que se cruce con este horario? */
function hayCruce(PDO $pdo, string $docente, string $dia, string $inicio, string $fin, int $idExcluir): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM clase
          WHERE docente = :docente AND dia = :dia AND estado = \'Activo\'
            AND hora_inicio < :fin AND hora_fin > :inicio
            AND id_clase <> :id
          LIMIT 1'
    );
    $stmt->execute([':docente' => $docente, ':dia' => $dia, ':inicio' => $inicio, ':fin' => $fin, ':id' => $idExcluir]);
    return (bool)$stmt->fetchColumn();
}

// ---------- 1. Qué acción piden y con qué método ----------
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';

$metodoOk = ($accion === 'listar' && $metodo === 'GET')
         || (in_array($accion, ['registrar', 'editar', 'eliminar'], true) && $metodo === 'POST');

if (!$metodoOk) {
    fallo('metodo_no_permitido', 405);
}

// ---------- 2. Conexión disponible ----------
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[horarios.php] database.php no entregó una conexión PDO en $pdo');
    fallo('error_servidor', 500);
}

// ---------- 3. Ejecutar la acción ----------
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    switch ($accion) {

        // ===== LISTAR =====
        case 'listar':
            $stmt = $pdo->query(
                'SELECT id_clase, nombre_grupo, docente, dia, hora_inicio, hora_fin, estado
                   FROM clase
                  ORDER BY FIELD(dia, \'Lunes\', \'Martes\', \'Miércoles\', \'Jueves\', \'Viernes\', \'Sábado\'),
                           hora_inicio, nombre_grupo'
            );
            $horarios = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $id = (int)$f['id_clase'];
                $horarios[] = [
                    'id'      => $id,
                    'codigo'  => 'ENG-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT), // lo que ve la tabla
                    'curso'   => (string)$f['nombre_grupo'],
                    'docente' => (string)$f['docente'],
                    'dia'     => (string)$f['dia'],
                    'inicio'  => $f['hora_inicio'] !== null ? substr((string)$f['hora_inicio'], 0, 5) : '',
                    'fin'     => $f['hora_fin'] !== null ? substr((string)$f['hora_fin'], 0, 5) : '',
                    'estado'  => (string)$f['estado'],
                ];
            }
            responderJson(['ok' => true, 'horarios' => $horarios]);
            break;

        // ===== REGISTRAR =====
        case 'registrar':
            $curso   = campoPost('nombre_grupo');
            $docente = campoPost('docente');
            $dia     = campoPost('dia');
            $inicio  = campoPost('hora_inicio');
            $fin     = campoPost('hora_fin');
            $estado  = campoPost('estado');

            $error = validar($curso, $docente, $dia, $inicio, $fin, $estado);
            if ($error !== null) {
                fallo($error, 400);
            }
            if ($estado === 'Activo' && hayCruce($pdo, $docente, $dia, $inicio, $fin, 0)) {
                fallo('horario_cruzado', 409);
            }

            // El texto de "horario" lo arma el servidor (id_nivel, id_encargado, cupo y aula quedan en NULL)
            $horario = $dia . ' ' . $inicio . ' - ' . $fin;

            $stmt = $pdo->prepare(
                'INSERT INTO clase (nombre_grupo, docente, horario, dia, hora_inicio, hora_fin, estado)
                 VALUES (:curso, :docente, :horario, :dia, :inicio, :fin, :estado)'
            );
            $stmt->execute([
                ':curso'   => $curso,
                ':docente' => $docente,
                ':horario' => $horario,
                ':dia'     => $dia,
                ':inicio'  => $inicio,
                ':fin'     => $fin,
                ':estado'  => $estado,
            ]);
            responderJson(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        // ===== EDITAR =====
        case 'editar':
            $id      = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            $curso   = campoPost('nombre_grupo');
            $docente = campoPost('docente');
            $dia     = campoPost('dia');
            $inicio  = campoPost('hora_inicio');
            $fin     = campoPost('hora_fin');
            $estado  = campoPost('estado');

            if ($id === false || $id <= 0) {
                fallo('no_encontrado', 404);
            }
            $error = validar($curso, $docente, $dia, $inicio, $fin, $estado);
            if ($error !== null) {
                fallo($error, 400);
            }

            $stmt = $pdo->prepare('SELECT 1 FROM clase WHERE id_clase = :id');
            $stmt->execute([':id' => $id]);
            if (!$stmt->fetchColumn()) {
                fallo('no_encontrado', 404);
            }
            if ($estado === 'Activo' && hayCruce($pdo, $docente, $dia, $inicio, $fin, $id)) {
                fallo('horario_cruzado', 409);
            }

            $stmt = $pdo->prepare(
                'UPDATE clase
                    SET nombre_grupo = :curso, docente = :docente, horario = :horario,
                        dia = :dia, hora_inicio = :inicio, hora_fin = :fin, estado = :estado
                  WHERE id_clase = :id'
            );
            $stmt->execute([
                ':curso'   => $curso,
                ':docente' => $docente,
                ':horario' => $dia . ' ' . $inicio . ' - ' . $fin,
                ':dia'     => $dia,
                ':inicio'  => $inicio,
                ':fin'     => $fin,
                ':estado'  => $estado,
                ':id'      => $id,
            ]);
            responderJson(['ok' => true]);
            break;

        // ===== ELIMINAR =====
        case 'eliminar':
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) {
                fallo('no_encontrado', 404);
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM clase WHERE id_clase = :id');
                $stmt->execute([':id' => $id]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { // otra tabla usa esta clase
                    fallo('clase_en_uso', 409);
                }
                throw $e;
            }
            if ($stmt->rowCount() === 0) {
                fallo('no_encontrado', 404);
            }
            responderJson(['ok' => true]);
            break;
    }
} catch (PDOException $e) {
    // El detalle va al log del servidor; el usuario solo ve un mensaje genérico
    error_log('[horarios.php] ' . $e->getMessage());
    fallo('error_servidor', 500);
}