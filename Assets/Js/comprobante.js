// Controlador en el servidor que entrega los datos del comprobante
const API = '../../controllers/Pagos/comprobante.php';

// Texto que se muestra si el pago no tiene concepto
const CONCEPTO_POR_DEFECTO = 'Abono / Mensualidad de Inglés - EnglishFuture';

// Mensajes para los códigos que devuelve el PHP
const MENSAJES = {
  no_encontrado: 'No se encontró el comprobante de este pago.',
  metodo_no_permitido: 'Acción no permitida.',
  error_servidor: 'Ocurrió un error en el servidor. Intenta de nuevo más tarde.'
};

document.addEventListener('DOMContentLoaded', () => {
  // De la URL solo se usa el id; todos los datos vienen de la base de datos
  const params = new URLSearchParams(window.location.search);
  const id = obtenerIdPago(params.get('id'));

  if (id === null) {
    mostrarError(MENSAJES.no_encontrado);
    return;
  }

  cargarComprobante(id);
});

/**
 * Acepta el id como "7" o como el código del recibo "REC-2026-007"
 * (en ese caso toma el número del final). Devuelve null si no es válido.
 */
function obtenerIdPago(valor) {
  const texto = String(valor ?? '').trim();
  const coincidencia = texto.match(/^(?:REC-\d{4}-)?(\d+)$/);
  if (!coincidencia) return null;

  const id = parseInt(coincidencia[1], 10);
  return id > 0 ? id : null;
}

async function cargarComprobante(id) {
  try {
    const respuesta = await fetch(`${API}?id=${encodeURIComponent(id)}`, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const datos = await respuesta.json();

    if (!datos.ok) {
      mostrarError(MENSAJES[datos.error] || MENSAJES.error_servidor);
      return;
    }

    pintarComprobante(datos.comprobante);
  } catch (error) {
    console.error('Error al cargar el comprobante:', error);
    mostrarError(MENSAJES.error_servidor);
  }
}

/**
 * Pone un texto en un elemento (si existe). Se usa textContent, no innerHTML,
 * para que ningún dato pueda inyectar código en la página.
 */
function poner(idElemento, texto) {
  const elemento = document.getElementById(idElemento);
  if (elemento) {
    elemento.textContent = texto;
  }
}

function pintarComprobante(c) {
  poner('lblReciboId', c.codigo);
  poner('lblFecha', c.fecha);
  poner('lblEstudiante', c.estudiante);
  poner('lblMetodo', c.metodo);
  poner('lblConcepto', c.concepto || CONCEPTO_POR_DEFECTO);

  const lblEstado = document.getElementById('lblEstado');
  if (lblEstado) {
    lblEstado.textContent = c.estado;
    if (c.estado === 'Completado') {
      lblEstado.className = 'badge bg-success';
    } else if (c.estado === 'Pendiente') {
      lblEstado.className = 'badge bg-warning text-dark';
    } else {
      lblEstado.className = 'badge bg-danger';
    }
  }

  const montoFormateado = `$${Number(c.monto).toFixed(2)}`;
  poner('lblMontoSub', montoFormateado);
  poner('lblMontoTotal', montoFormateado);
}

/**
 * Muestra el aviso de error y esconde el recibo (para no dejar un recibo vacío o falso).
 */
function mostrarError(mensaje) {
  const aviso = document.getElementById('msgError');
  const recibo = document.getElementById('reciboBox');
  if (aviso) {
    aviso.textContent = mensaje;
    aviso.classList.remove('d-none');
  }
  if (recibo) {
    recibo.classList.add('d-none');
  }
}