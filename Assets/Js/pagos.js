// Controlador en el servidor con el que habla esta página
const API = '../../controllers/Pagos/pagos.php';

// Datos que vienen de la base de datos
let pagos = [];
let idPagoSeleccionadoAnular = null;

// Mensajes para los códigos que devuelve el PHP (nunca se muestra texto tomado de la URL)
const MENSAJES = {
  campos_vacios: 'Completa todos los campos requeridos.',
  estudiante_invalido: 'El nombre del estudiante debe tener entre 2 y 100 caracteres.',
  monto_invalido: 'El monto debe ser mayor que cero y tener máximo 2 decimales.',
  fecha_invalida: 'La fecha de pago no es válida.',
  fecha_futura: 'La fecha de pago no puede ser futura.',
  metodo_invalido: 'Selecciona un método de pago válido.',
  estado_invalido: 'Selecciona un estado válido.',
  concepto_invalido: 'El concepto no puede superar 255 caracteres.',
  ya_anulado: 'Ese pago ya estaba anulado.',
  no_encontrado: 'El pago ya no existe.',
  metodo_no_permitido: 'Acción no permitida.',
  error_servidor: 'Ocurrió un error en el servidor. Intenta de nuevo más tarde.'
};

document.addEventListener('DOMContentLoaded', () => {
  cargarComponente('../../components/header.html', 'header-container');
  cargarComponente('../../components/footer.html', 'footer-container');

  document.getElementById('fechaPago').value = fechaDeHoy();

  cargarPagos();

  document.getElementById('inputBusqueda').addEventListener('input', filtrarPagos);
  document.getElementById('selectEstado').addEventListener('change', filtrarPagos);
  document.getElementById('selectMetodo').addEventListener('change', filtrarPagos);

  document.getElementById('formRegistroPago').addEventListener('submit', registrarNuevoPago);
  document.getElementById('btnConfirmarAnular').addEventListener('click', confirmarAnulacion);
});

function cargarComponente(ruta, idContenedor) {
  fetch(ruta)
    .then(response => response.text())
    .then(data => document.getElementById(idContenedor).innerHTML = data)
    .catch(error => console.warn('Aviso componentes:', error));
}

/**
 * Fecha de hoy en hora LOCAL (formato AAAA-MM-DD).
 * toISOString() usa hora UTC y en la noche daría la fecha de mañana.
 */
function fechaDeHoy() {
  const d = new Date();
  const mes = String(d.getMonth() + 1).padStart(2, '0');
  const dia = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${mes}-${dia}`;
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
 * Pide al servidor la lista de pagos y llena la tabla.
 */
async function cargarPagos() {
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

    pagos = datos.pagos;
    filtrarPagos();
  } catch (error) {
    console.error('Error al cargar los pagos:', error);
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

function renderizarTabla(datos) {
  const tbody = document.getElementById('tablaPagosBody');
  const sinResultados = document.getElementById('sinResultados');
  tbody.innerHTML = '';

  if (datos.length === 0) {
    sinResultados.classList.remove('d-none');
    actualizarEstadisticas([]);
    return;
  }
  sinResultados.classList.add('d-none');

  datos.forEach(item => {
    const tr = document.createElement('tr');
    const id = Number(item.id);

    tr.innerHTML = `
      <td class="ps-4"><span class="badge badge-id px-2 py-1">${escaparHtml(item.codigo)}</span></td>
      <td class="fw-bold text-dark">${escaparHtml(item.estudiante)}</td>
      <td class="fw-semibold text-primary-custom">$${Number(item.monto).toFixed(2)}</td>
      <td><i class="bi bi-calendar3 me-1 text-muted"></i>${escaparHtml(item.fecha)}</td>
      <td>${escaparHtml(item.metodo)}</td>
      <td><span class="badge ${obtenerClaseEstado(item.estado)} px-2 py-1">${escaparHtml(item.estado)}</span></td>
      <td class="text-end pe-4">
        <div class="btn-group btn-group-sm">
          <!-- Redirige a la interfaz de comprobante independiente -->
          <button class="btn btn-outline-primary" onclick="irAComprobante(${id})" title="Ver / Imprimir Recibo">
            <i class="bi bi-file-earmark-text"></i>
          </button>
          <button class="btn btn-outline-danger" onclick="prepararAnulacion(${id})" title="Anular" ${item.estado === 'Anulado' ? 'disabled' : ''}>
            <i class="bi bi-x-circle"></i>
          </button>
        </div>
      </td>
    `;
    tbody.appendChild(tr);
  });
  actualizarEstadisticas(datos);
}

function obtenerClaseEstado(estado) {
  switch (estado) {
    case 'Completado': return 'badge-completado';
    case 'Pendiente': return 'badge-pendiente';
    case 'Anulado': return 'badge-anulado';
    default: return 'bg-secondary';
  }
}

function actualizarEstadisticas(datos) {
  const total = datos.filter(i => i.estado === 'Completado').reduce((s, i) => s + Number(i.monto), 0);
  document.getElementById('statTotalRecaudado').textContent = `$${total.toFixed(2)}`;
  document.getElementById('statCompletados').textContent = datos.filter(i => i.estado === 'Completado').length;
  document.getElementById('statPendientes').textContent = datos.filter(i => i.estado === 'Pendiente').length;
}

function filtrarPagos() {
  const texto = document.getElementById('inputBusqueda').value.toLowerCase().trim();
  const estado = document.getElementById('selectEstado').value;
  const metodo = document.getElementById('selectMetodo').value;

  const resultado = pagos.filter(i => {
    const cTexto = i.estudiante.toLowerCase().includes(texto) || i.codigo.toLowerCase().includes(texto);
    const cEstado = estado === 'todos' || i.estado === estado;
    const cMetodo = metodo === 'todos' || i.metodo === metodo;
    return cTexto && cEstado && cMetodo;
  });
  renderizarTabla(resultado);
}

async function registrarNuevoPago(e) {
  e.preventDefault();
  const estudiante = document.getElementById('nombreEstudiante').value.trim();
  const monto = parseFloat(document.getElementById('montoPago').value);
  const fecha = document.getElementById('fechaPago').value;
  const metodo = document.getElementById('metodoPago').value;
  const estado = document.getElementById('estadoPago').value;
  const concepto = document.getElementById('conceptoPago').value.trim();

  // Revisión rápida en el navegador (el servidor vuelve a validar todo)
  if (!estudiante || isNaN(monto) || !fecha || !metodo) {
    mostrarAlerta(MENSAJES.campos_vacios, 'warning');
    return;
  }

  const datos = new FormData();
  datos.set('accion', 'registrar');
  datos.set('estudiante', estudiante);
  datos.set('valor_pagado', String(monto));
  datos.set('fecha_pago', fecha);
  datos.set('medio_pago', metodo);
  datos.set('estado', estado);
  datos.set('concepto', concepto);

  try {
    const resultado = await enviarAccion(datos);

    if (!resultado.ok) {
      mostrarAlerta(MENSAJES[resultado.error] || MENSAJES.error_servidor, 'danger');
      return;
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRegistroPago')).hide();

    // Deja el formulario limpio para el próximo pago
    document.getElementById('formRegistroPago').reset();
    document.getElementById('fechaPago').value = fechaDeHoy();

    await cargarPagos();
    mostrarAlerta('Pago registrado con éxito.', 'success');

    // Redirigir de una vez al comprobante recién creado
    setTimeout(() => irAComprobante(resultado.id), 1000);
  } catch (error) {
    console.error('Error al registrar el pago:', error);
    mostrarAlerta(MENSAJES.error_servidor, 'danger');
  }
}

/**
 * Redirige a la página independiente del comprobante pasando los datos por parámetros URL
 */
function irAComprobante(id) {
  const pago = pagos.find(p => p.id === id);
  if (!pago) return;
  const url = `comprobante.html?id=${encodeURIComponent(pago.codigo)}` +
              `&estudiante=${encodeURIComponent(pago.estudiante)}` +
              `&monto=${pago.monto}` +
              `&fecha=${encodeURIComponent(pago.fecha)}` +
              `&metodo=${encodeURIComponent(pago.metodo)}` +
              `&estado=${encodeURIComponent(pago.estado)}` +
              `&concepto=${encodeURIComponent(pago.concepto)}`;
  window.location.href = url;
}

function prepararAnulacion(id) {
  idPagoSeleccionadoAnular = id;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAnularPago')).show();
}

async function confirmarAnulacion() {
  if (!idPagoSeleccionadoAnular) return;

  try {
    const datos = new FormData();
    datos.set('accion', 'anular');
    datos.set('id', idPagoSeleccionadoAnular);

    const resultado = await enviarAccion(datos);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAnularPago')).hide();
    idPagoSeleccionadoAnular = null;

    if (!resultado.ok) {
      mostrarAlerta(MENSAJES[resultado.error] || MENSAJES.error_servidor, 'danger');
      await cargarPagos();
      return;
    }

    await cargarPagos();
    mostrarAlerta('El pago ha sido marcado como Anulado.', 'danger');
  } catch (error) {
    console.error('Error al anular el pago:', error);
    mostrarAlerta(MENSAJES.error_servidor, 'danger');
  }
}

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
  setTimeout(() => wrapper.remove(), 4000);
}