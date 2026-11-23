// Controlador en el servidor con el que habla esta página
const API = '../../controllers/Categoria/categoria.php';

// Datos que vienen de la base de datos
let usuarios = [];

// Mensajes para los códigos que devuelve el PHP (nunca se muestra texto tomado de la URL)
const MENSAJES = {
    campos_vacios: 'Completa todos los campos obligatorios.',
    documento_invalido: 'El documento solo puede tener letras y números (5 a 30 caracteres).',
    nombre_invalido: 'Escribe al menos un nombre y un apellido (máximo 100 caracteres cada uno).',
    clase_invalida: 'La clase debe tener entre 2 y 100 caracteres.',
    rol_invalido: 'Selecciona un tipo válido.',
    estado_invalido: 'Selecciona un estado válido.',
    ya_registrado: 'Ya existe una persona registrada con ese documento.',
    no_encontrado: 'El registro ya no existe.',
    metodo_no_permitido: 'Acción no permitida.',
    error_servidor: 'Ocurrió un error en el servidor. Intenta de nuevo más tarde.'
};

document.addEventListener('DOMContentLoaded', () => {
    cargarComponentes();

    document.getElementById('inputBuscar').addEventListener('input', filtrarUsuarios);
    document.getElementById('filtroRol').addEventListener('change', filtrarUsuarios);
    document.getElementById('filtroEstado').addEventListener('change', filtrarUsuarios);
    document.getElementById('formUsuario').addEventListener('submit', guardarUsuario);

    mostrarMensajesDeLaUrl();
    cargarUsuarios();
});

function cargarComponentes() {
    fetch('../../components/header.html')
        .then(res => res.text())
        .then(data => {
            document.getElementById('header-container').innerHTML = data;

            const linkCategoria = document.getElementById('nav-categoria');
            if (linkCategoria) {
                linkCategoria.setAttribute('href', 'categoria.html');
            }
        })
        .catch(err => console.error('Error al cargar el header:', err));

    fetch('../../components/footer.html')
        .then(res => res.text())
        .then(data => {
            document.getElementById('footer-container').innerHTML = data;
        })
        .catch(err => console.error('Error al cargar el footer:', err));
}

/**
 * Escapa el texto antes de ponerlo en la tabla, para que ningún dato
 * pueda inyectar HTML o código en la página.
 */
function escaparHtml(texto) {
    return String(texto ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

/**
 * Muestra los avisos que deja el PHP en la dirección (?mensaje=registrado o ?error=...)
 * después de registrar con el formulario.
 */
function mostrarMensajesDeLaUrl() {
    const params = new URLSearchParams(window.location.search);
    const mensaje = params.get('mensaje');
    const error = params.get('error');

    if (mensaje === 'registrado') {
        mostrarNotificacion('Usuario registrado exitosamente', 'success');
    } else if (error) {
        mostrarNotificacion(MENSAJES[error] || 'No fue posible completar la acción.', 'danger');
    }

    // Limpia la dirección para que al recargar no se repita el aviso
    if (mensaje || error) {
        window.history.replaceState({}, document.title, window.location.pathname);
    }
}

/**
 * Pide al servidor la lista de encargados y llena la tabla.
 */
async function cargarUsuarios() {
    try {
        const respuesta = await fetch(`${API}?accion=listar`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });

        const datos = await respuesta.json();

        if (!datos.ok) {
            mostrarNotificacion(MENSAJES[datos.error] || MENSAJES.error_servidor, 'danger');
            renderizarTabla([]);
            return;
        }

        usuarios = datos.usuarios;
        filtrarUsuarios();
    } catch (error) {
        console.error('Error al cargar los usuarios:', error);
        mostrarNotificacion(MENSAJES.error_servidor, 'danger');
        renderizarTabla([]);
    }
}

function renderizarTabla(datos) {
    const tbody = document.getElementById('tablaUsuarios');
    tbody.innerHTML = '';

    if (datos.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4 text-muted">
                    <i class="bi bi-search display-6 d-block mb-2"></i>
                    No se encontraron docentes o estudiantes con los criterios ingresados.
                </td>
            </tr>`;
        return;
    }

    datos.forEach(usr => {
        const tr = document.createElement('tr');
        const badgeRolClass = usr.rol === 'Docente' ? 'badge-rol-docente' : 'badge-rol-estudiante';
        const badgeEstadoClass = usr.estado === 'Activo' ? 'badge-status-activo' : 'badge-status-inactivo';
        const id = Number(usr.id);

        tr.innerHTML = `
            <td class="fw-bold">${escaparHtml(usr.documento)}</td>
            <td>${escaparHtml(usr.nombre)}</td>
            <td>${escaparHtml(usr.clase)}</td>
            <td><span class="badge ${badgeRolClass} px-2 py-1">${escaparHtml(usr.rol)}</span></td>
            <td><span class="badge ${badgeEstadoClass} px-2 py-1">${escaparHtml(usr.estado)}</span></td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-primary me-1" onclick="prepararEdicion(${id})" title="Editar">
                    <i class="bi bi-pencil-fill"></i> Editar
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="prepararEliminacion(${id})" title="Eliminar">
                    <i class="bi bi-trash-fill"></i> Eliminar
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function filtrarUsuarios() {
    const textoBusqueda = document.getElementById('inputBuscar').value.toLowerCase().trim();
    const rolSeleccionado = document.getElementById('filtroRol').value;
    const estadoSeleccionado = document.getElementById('filtroEstado').value;

    const filtrados = usuarios.filter(usr => {
        const coincideTexto = usr.nombre.toLowerCase().includes(textoBusqueda) ||
                              usr.documento.toLowerCase().includes(textoBusqueda) ||
                              usr.clase.toLowerCase().includes(textoBusqueda);
        const coincideRol = (rolSeleccionado === 'todos') || (usr.rol === rolSeleccionado);
        const coincideEstado = (estadoSeleccionado === 'todos') || (usr.estado === estadoSeleccionado);
        return coincideTexto && coincideRol && coincideEstado;
    });

    renderizarTabla(filtrados);
}

function prepararModalRegistro() {
    document.getElementById('modalUsuarioLabel').textContent = 'Registrar Usuario';
    document.getElementById('formUsuario').reset();
    document.getElementById('usuarioId').value = '';

    // En el registro todos los campos se pueden escribir
    document.getElementById('docIdentidad').readOnly = false;
    document.getElementById('nombreUsuario').readOnly = false;
}

function prepararEdicion(id) {
    const usuario = usuarios.find(u => u.id === id);
    if (!usuario) return;

    document.getElementById('modalUsuarioLabel').textContent = 'Editar Usuario';
    document.getElementById('usuarioId').value = usuario.id;
    document.getElementById('docIdentidad').value = usuario.documento;
    document.getElementById('nombreUsuario').value = usuario.nombre;
    document.getElementById('claseUsuario').value = usuario.clase;
    document.getElementById('rolUsuario').value = usuario.rol;
    document.getElementById('estadoUsuario').value = usuario.estado;

    // El documento y el nombre identifican a la persona: al editar solo se cambian clase, tipo y estado
    document.getElementById('docIdentidad').readOnly = true;
    document.getElementById('nombreUsuario').readOnly = true;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUsuario'));
    modal.show();
}

function prepararEliminacion(id) {
    document.getElementById('idEliminar').value = id;
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEliminar'));
    modal.show();
}

/**
 * Envía una operación POST al servidor y devuelve la respuesta en JSON.
 */
async function enviarAccion(datos) {
    const respuesta = await fetch(API, {
        method: 'POST',
        body: datos,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    });
    return respuesta.json();
}

/**
 * Guardar:
 *  - Registro: el formulario se envía normalmente al PHP (que redirige de vuelta con un aviso).
 *  - Edición: se envía en segundo plano y se actualiza la tabla sin recargar la página.
 */
async function guardarUsuario(e) {
    const id = document.getElementById('usuarioId').value;

    // Registro: no se hace nada aquí, el formulario viaja solo al PHP
    if (!id) return;

    // Edición
    e.preventDefault();

    try {
        const datos = new FormData(document.getElementById('formUsuario'));
        datos.set('accion', 'editar');

        const resultado = await enviarAccion(datos);

        if (!resultado.ok) {
            mostrarNotificacion(MENSAJES[resultado.error] || MENSAJES.error_servidor, 'danger');
            return;
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUsuario')).hide();
        await cargarUsuarios();
        mostrarNotificacion('Registro actualizado exitosamente', 'success');
    } catch (error) {
        console.error('Error al editar:', error);
        mostrarNotificacion(MENSAJES.error_servidor, 'danger');
    }
}

async function confirmarEliminacion() {
    const id = document.getElementById('idEliminar').value;

    try {
        const datos = new FormData();
        datos.set('accion', 'eliminar');
        datos.set('id', id);

        const resultado = await enviarAccion(datos);

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEliminar')).hide();

        if (!resultado.ok) {
            mostrarNotificacion(MENSAJES[resultado.error] || MENSAJES.error_servidor, 'danger');
            await cargarUsuarios();
            return;
        }

        await cargarUsuarios();
        mostrarNotificacion('Usuario eliminado correctamente', 'danger', 'bi-trash-fill');
    } catch (error) {
        console.error('Error al eliminar:', error);
        mostrarNotificacion(MENSAJES.error_servidor, 'danger');
    }
}

function mostrarNotificacion(mensaje, tipo, icono) {
    const alertContainer = document.getElementById('alert-container');
    const iconoFinal = icono || (tipo === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill');

    const alert = document.createElement('div');
    alert.className = `alert alert-${tipo} alert-dismissible fade show shadow-sm`;
    alert.role = 'alert';

    const iconoEl = document.createElement('i');
    iconoEl.className = `bi ${iconoFinal} me-2`;

    const botonCerrar = document.createElement('button');
    botonCerrar.type = 'button';
    botonCerrar.className = 'btn-close';
    botonCerrar.setAttribute('data-bs-dismiss', 'alert');
    botonCerrar.setAttribute('aria-label', 'Close');

    // El mensaje se agrega como texto (no como HTML)
    alert.append(iconoEl, document.createTextNode(mensaje), botonCerrar);
    alertContainer.appendChild(alert);

    setTimeout(() => {
        alert.remove();
    }, 4000);
}