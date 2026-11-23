// Controlador en el servidor con el que habla esta página
const API = '../../controllers/Horarios/horarios.php';

// Datos que vienen de la base de datos
let horarios = [];
let idEliminarSeleccionado = null;

// Mensajes para los códigos que devuelve el PHP (nunca se muestra texto tomado de la URL)
const MENSAJES = {
  campos_vacios: 'Por favor completa todos los campos requeridos.',
  curso_invalido: 'El nombre del curso debe tener entre 2 y 100 caracteres.',
  docente_invalido: 'El nombre del docente debe tener entre 2 y 100 caracteres.',
  dia_invalido: 'Selecciona un día válido.',
  estado_invalido: 'Selecciona un estado válido.',
  hora_invalida: 'Las horas no tienen un formato válido.',
  rango_horas_invalido: 'La hora de fin debe ser posterior a la hora de inicio.',
  horario_cruzado: 'Ese docente ya tiene una clase activa que se cruza con ese horario.',
  clase_en_uso: 'No se puede eliminar: la clase tiene registros asociados.',
  no_encontrado: 'El horario ya no existe.',
  metodo_no_permitido: 'Acción no permitida.',
  error_servidor: 'Ocurrió un error en el servidor. Intenta de nuevo más tarde.'
};

document.addEventListener('DOMContentLoaded', () => {
  // 1. Cargar Componentes Reutilizables Header y Footer (Subiendo dos niveles)
  cargarComponente('../../components/header.html', 'header-container');
  cargarComponente('../../components/footer.html', 'footer-container');

  // 2. Pedir los horarios a la base de datos
  cargarHorarios();

  // 3. Listeners de búsqueda y filtros
  document.getElementById('inputBusqueda').addEventListener('input', filtrarHorarios);
  document.getElementById('selectDia').addEventListener('change', filtrarHorarios);
  document.getElementById('selectEstado').addEventListener('change', filtrarHorarios);

  // 4. Listener del Formulario de Registro/Edición
  document.getElementById('formHorario').addEventListener('submit', guardarHorario);

  // 5. Botón de confirmar eliminación
  document.getElementById('btnConfirmarEliminar').addEventListener('click', confirmarEliminacion);
});

/**
 * Carga un componente HTML mediante fetch()
 */
function cargarComponente(ruta, idContenedor) {
  fetch(ruta)
    .then(response => {
      if (!response.ok) throw new Error(`No se pudo cargar: ${ruta}`);
      return response.text();
    })
    .then(data => {
      document.getElementById(idContenedor).innerHTML = data;
    })
    .catch(error => console.warn('Aviso componentes:', error.message));
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
 * Pide al servidor la lista de horarios y llena la tabla.
 */
async function cargarHorarios() {
  try {
    const respuesta = await fetch(`${API}?accion=listar`, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const datos = await respuesta.json();

    if (!datos.ok) {
      mostrarAlerta(MENSAJES[datos.error] || MENSAJES.error_servidor, 'danger');
      renderizarTabla([]);
      return;
    }

    horarios = datos.horarios;
    filtrarHorarios();
  } catch (error) {
    console.error('Error al cargar los horarios:', error);
    mostrarAlerta(MENSAJES.error_servidor, 'danger');
    renderizarTabla([]);
  }
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
 * Renderiza los horarios en la tabla horizontal
 */
function renderizarTabla(datos) {
  const tbody = document.getElementById('tablaHorariosBody');
  const sinResultados = document.getElementById('sinResultados');
  tbody.innerHTML = '';

  if (datos.length === 0) {
    sinResultados.classList.remove('d-none');
    return;
  }
  sinResultados.classList.add('d-none');

  datos.forEach(item => {
    const tr = document.createElement('tr');
    const id = Number(item.id);
    const horasTexto = item.inicio && item.fin ? `${item.inicio} - ${item.fin}` : 'Sin horario';

    tr.innerHTML = `
      <td class="ps-4 fw-bold text-dark">${escaparHtml(item.curso)}</td>
      <td>
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-person-circle text-secondary"></i>
          <span>${escaparHtml(item.docente)}</span>
        </div>
      </td>
      <td><span class="badge badge-id px-2 py-1">${escaparHtml(item.codigo)}</span></td>
      <td><span class="fw-semibold">${escaparHtml(item.dia)}</span></td>
      <td><i class="bi bi-clock me-1 text-muted"></i>${escaparHtml(horasTexto)}</td>
      <td>
        <span class="badge ${item.estado === 'Activo' ? 'badge-activo' : 'badge-inactivo'} px-2 py-1">
          ${escaparHtml(item.estado)}
        </span>
      </td>
      <td class="text-end pe-4">
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-primary" onclick="prepararEdicion(${id})" title="Editar">
            <i class="bi bi-pencil"></i>
          </button>
          <button class="btn btn-outline-danger" onclick="prepararEliminacion(${id})" title="Eliminar">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </td>
    `;
    tbody.appendChild(tr);
  });
}

/**
 * Filtra los horarios en tiempo real
 */
function filtrarHorarios() {
  const textoBusqueda = document.getElementById('inputBusqueda').value.toLowerCase().trim();
  const diaFiltro = document.getElementById('selectDia').value;
  const estadoFiltro = document.getElementById('selectEstado').value;

  const resultado = horarios.filter(item => {
    const coincideTexto = item.curso.toLowerCase().includes(textoBusqueda) ||
                          item.docente.toLowerCase().includes(textoBusqueda) ||
                          item.codigo.toLowerCase().includes(textoBusqueda);
    const coincideDia = diaFiltro === 'todos' || item.dia === diaFiltro;
    const coincideEstado = estadoFiltro === 'todos' || item.estado === estadoFiltro;

    return coincideTexto && coincideDia && coincideEstado;
  });

  renderizarTabla(resultado);
}

/**
 * Prepara el modal para registrar un nuevo horario
 */
function prepararCreacion() {
  document.getElementById('formHorario').reset();
  document.getElementById('horarioId').value = '';
  document.getElementById('modalHorarioLabel').textContent = 'Registrar Horario';
}

/**
 * Prepara el modal con los datos para editar
 */
function prepararEdicion(id) {
  const item = horarios.find(h => h.id === id);
  if (!item) return;

  document.getElementById('horarioId').value = item.id;
  document.getElementById('nombreClase').value = item.curso;
  document.getElementById('nombreDocente').value = item.docente;
  document.getElementById('diaClase').value = item.dia;
  document.getElementById('estadoClase').value = item.estado;
  document.getElementById('horaInicio').value = item.inicio;
  document.getElementById('horaFin').value = item.fin;

  document.getElementById('modalHorarioLabel').textContent = 'Editar Horario';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHorario')).show();
}

/**
 * Guarda (Crea o Edita) un horario
 */
async function guardarHorario(e) {
  e.preventDefault();
  const id = document.getElementById('horarioId').value;
  const curso = document.getElementById('nombreClase').value.trim();
  const docente = document.getElementById('nombreDocente').value.trim();
  const dia = document.getElementById('diaClase').value;
  const estado = document.getElementById('estadoClase').value;
  const inicio = document.getElementById('horaInicio').value;
  const fin = document.getElementById('horaFin').value;

  // Revisión rápida en el navegador (el servidor vuelve a validar todo)
  if (!curso || !docente || !dia || !inicio || !fin) {
    mostrarAlerta(MENSAJES.campos_vacios, 'warning');
    return;
  }
  if (fin <= inicio) {
    mostrarAlerta(MENSAJES.rango_horas_invalido, 'warning');
    return;
  }

  const datos = new FormData();
  datos.set('accion', id ? 'editar' : 'registrar');
  datos.set('id', id);
  datos.set('nombre_grupo', curso);
  datos.set('docente', docente);
  datos.set('dia', dia);
  datos.set('hora_inicio', inicio);
  datos.set('hora_fin', fin);
  datos.set('estado', estado);

  try {
    const resultado = await enviarAccion(datos);

    if (!resultado.ok) {
      mostrarAlerta(MENSAJES[resultado.error] || MENSAJES.error_servidor, 'danger');
      return;
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHorario')).hide();
    await cargarHorarios();
    mostrarAlerta(id ? 'Horario actualizado con éxito.' : 'Nuevo horario registrado con éxito.', 'success');
  } catch (error) {
    console.error('Error al guardar el horario:', error);
    mostrarAlerta(MENSAJES.error_servidor, 'danger');
  }
}

/**
 * Abre la confirmación de eliminación
 */
function prepararEliminacion(id) {
  idEliminarSeleccionado = id;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEliminar')).show();
}

/**
 * Acción del botón del modal de eliminación
 */
async function confirmarEliminacion() {
  if (!idEliminarSeleccionado) return;

  try {
    const datos = new FormData();
    datos.set('accion', 'eliminar');
    datos.set('id', idEliminarSeleccionado);

    const resultado = await enviarAccion(datos);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEliminar')).hide();
    idEliminarSeleccionado = null;

    if (!resultado.ok) {
      mostrarAlerta(MENSAJES[resultado.error] || MENSAJES.error_servidor, 'danger');
      await cargarHorarios();
      return;
    }

    await cargarHorarios();
    mostrarAlerta('El horario ha sido eliminado correctamente.', 'danger');
  } catch (error) {
    console.error('Error al eliminar el horario:', error);
    mostrarAlerta(MENSAJES.error_servidor, 'danger');
  }
}

/**
 * Muestra alertas flotantes para respuestas visuales del usuario
 */
function mostrarAlerta(mensaje, tipo) {
  const alertContainer = document.getElementById('alertContainer');
  const wrapper = document.createElement('div');
  wrapper.innerHTML = `
    <div class="alert alert-${tipo} alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi ${tipo === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'} me-2"></i>
      ${mensaje}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  `;
  alertContainer.appendChild(wrapper);

  setTimeout(() => {
    wrapper.remove();
  }, 4000);
}