document.addEventListener("DOMContentLoaded", () => {
    // 1. Carga del componente Footer Reutilizable mediante Fetch
    loadFooter();

    // 2. Evento para mostrar/ocultar contraseña
    setupPasswordToggle();

    // 3. Validación frontend del formulario (si todo está bien, se envía al PHP)
    setupLoginForm();

    // 4. Muestra los mensajes que devuelve el PHP (?error=... o ?registro=exitoso)
    showServerMessages();
});

/**
 * Carga el componente footer.html dinámicamente
 */
async function loadFooter() {
    const footerContainer = document.getElementById("footer-container");
    if (!footerContainer) return;

    try {
        // La ruta relativa hacia components/footer.html desde views/seguridad/
        const response = await fetch("../../components/footer.html");
        if (response.ok) {
            const footerHTML = await response.text();
            footerContainer.innerHTML = footerHTML;
        } else {
            console.warn("No se pudo cargar el archivo footer.html");
        }
    } catch (error) {
        console.error("Error al realizar fetch de footer.html:", error);
    }
}

/**
 * Alterna el estado del input password
 */
function setupPasswordToggle() {
    const togglePasswordBtn = document.getElementById("togglePassword");
    const passwordInput = document.getElementById("password");
    const toggleIcon = document.getElementById("toggleIcon");

    if (togglePasswordBtn && passwordInput && toggleIcon) {
        togglePasswordBtn.addEventListener("click", () => {
            const isPassword = passwordInput.getAttribute("type") === "password";

            passwordInput.setAttribute("type", isPassword ? "text" : "password");

            // Cambiar icono
            toggleIcon.classList.toggle("bi-eye-slash", !isPassword);
            toggleIcon.classList.toggle("bi-eye", isPassword);
        });
    }
}

/**
 * Valida el formulario en el navegador y, si está correcto, lo envía al PHP.
 * IMPORTANTE: el frontend ya NO decide si las credenciales son correctas;
 * eso lo decide controllers/seguridad/login.php consultando la base de datos.
 */
function setupLoginForm() {
    const loginForm = document.getElementById("loginForm");
    const alertContainer = document.getElementById("alert-container");

    if (!loginForm) return;

    loginForm.addEventListener("submit", (e) => {
        const email = document.getElementById("email").value.trim();
        const password = document.getElementById("password").value;

        // Limpiar alertas previas
        alertContainer.innerHTML = "";

        // Validaciones frontend básicas: si fallan, se bloquea el envío
        if (!email || !password) {
            e.preventDefault();
            showAlert("Por favor, completa todos los campos requeridos.", "danger");
            return;
        }

        if (!validateEmail(email)) {
            e.preventDefault();
            showAlert("Por favor, ingresa un correo electrónico válido.", "warning");
            return;
        }

        // Si todo está bien NO se llama a preventDefault():
        // el formulario se envía por POST a controllers/seguridad/login.php
    });
}

/**
 * Lee los parámetros de la URL que deja el PHP y muestra el mensaje correspondiente
 */
function showServerMessages() {
    const params = new URLSearchParams(window.location.search);
    const error = params.get("error");
    const registro = params.get("registro");

    // Mensajes fijos (nunca se inserta en la página texto tomado de la URL)
    const errores = {
        credenciales_invalidas: "Correo o contraseña incorrectos.",
        campos_vacios: "Por favor, completa todos los campos requeridos.",
        cuenta_inactiva: "Tu cuenta está inactiva. Contacta al administrador.",
        metodo_no_permitido: "Debes iniciar sesión desde el formulario.",
        error_servidor: "Ocurrió un error en el servidor. Intenta de nuevo más tarde."
    };

    if (error) {
        showAlert(errores[error] || "No fue posible iniciar sesión.", "danger");
    } else if (registro === "exitoso") {
        showAlert("¡Cuenta creada con éxito! Ya puedes iniciar sesión.", "success");
    } else {
        return;
    }

    // Limpia la URL para que al recargar la página no se repita el mensaje
    window.history.replaceState({}, document.title, window.location.pathname);
}

/**
 * Inyecta una alerta de Bootstrap en el DOM
 */
function showAlert(message, type) {
    const alertContainer = document.getElementById("alert-container");
    alertContainer.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show rounded-3 py-2 px-3 small" role="alert">
            <i class="bi ${type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'} me-2"></i>
            ${message}
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
}

/**
 * Validación simple de estructura de e-mail
 */
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}