document.addEventListener("DOMContentLoaded", () => {
    // 1. Carga dinámica de Header y Footer
    cargarComponentes();

    // 2. Funcionalidad para mostrar / ocultar contraseñas
    configurarVisibilidadPassword("newPassword", "toggleNewPassword", "iconNewPassword");
    configurarVisibilidadPassword("confirmPassword", "toggleConfirmPassword", "iconConfirmPassword");

    // 3. Validación interactiva de requisitos en tiempo real
    configurarValidacionRequisitos();

    // 4. Validación de cada paso antes de enviarlo al PHP
    configurarFormularioCorreo();
    configurarFormularioCaptcha();
    configurarFormularioDocumento();
    configurarEnvioFormulario();

    // 5. Decide qué paso mostrar y qué mensaje del servidor enseñar
    configurarPasoYMensajes();
});

/**
 * Carga los componentes HTML reutilizables del header y footer
 */
async function cargarComponentes() {
    try {
        const headerResponse = await fetch('../../components/header.html');
        if (headerResponse.ok) {
            const headerHtml = await headerResponse.text();
            document.getElementById('header-container').innerHTML = headerHtml;
        }

        const footerResponse = await fetch('../../components/footer.html');
        if (footerResponse.ok) {
            const footerHtml = await footerResponse.text();
            document.getElementById('footer-container').innerHTML = footerHtml;
        }
    } catch (error) {
        console.error("Error al cargar los componentes reutilizables:", error);
    }
}

/**
 * Alterna el tipo de input entre 'password' y 'text' y actualiza el icono
 */
function configurarVisibilidadPassword(inputId, buttonId, iconId) {
    const input = document.getElementById(inputId);
    const button = document.getElementById(buttonId);
    const icon = document.getElementById(iconId);

    if (input && button && icon) {
        button.addEventListener("click", () => {
            const isPassword = input.type === "password";
            input.type = isPassword ? "text" : "password";
            icon.classList.toggle("bi-eye", !isPassword);
            icon.classList.toggle("bi-eye-slash", isPassword);
        });
    }
}

/**
 * Comprueba en tiempo real si se cumple el requisito de longitud mínima
 */
function configurarValidacionRequisitos() {
    const newPassword = document.getElementById("newPassword");
    const reqMinLength = document.getElementById("reqMinLength");
    const iconReq = document.getElementById("iconReqMinLength");

    if (newPassword && reqMinLength && iconReq) {
        newPassword.addEventListener("input", () => {
            const esValida = newPassword.value.length >= 8;

            if (esValida) {
                reqMinLength.classList.remove("text-muted", "req-invalid");
                reqMinLength.classList.add("req-valid", "fw-semibold");
                iconReq.classList.remove("bi-circle");
                iconReq.classList.add("bi-check-circle-fill");
            } else {
                reqMinLength.classList.remove("req-valid", "fw-semibold");
                reqMinLength.classList.add("text-muted", "req-invalid");
                iconReq.classList.remove("bi-check-circle-fill");
                iconReq.classList.add("bi-circle");
            }
        });
    }
}

/**
 * PASO 1: valida el correo en el navegador. Si está bien, el formulario se envía al PHP.
 */
function configurarFormularioCorreo() {
    const form = document.getElementById("formSolicitar");
    const email = document.getElementById("email");
    if (!form || !email) return;

    form.addEventListener("submit", (event) => {
        const valor = email.value.trim();
        const formatoValido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valor);

        if (!valor || !formatoValido) {
            event.preventDefault();
            email.classList.add("is-invalid");
            return;
        }

        email.classList.remove("is-invalid");
        // Todo correcto: NO se llama a preventDefault(); el formulario viaja por POST al PHP
    });

    email.addEventListener("input", () => email.classList.remove("is-invalid"));
}

/**
 * PASO 2: valida que se haya escrito un número. El PHP comprueba si es el resultado correcto.
 */
function configurarFormularioCaptcha() {
    const form = document.getElementById("formCaptcha");
    const captcha = document.getElementById("captcha");
    if (!form || !captcha) return;

    form.addEventListener("submit", (event) => {
        if (!/^[0-9]{1,2}$/.test(captcha.value.trim())) {
            event.preventDefault();
            captcha.classList.add("is-invalid");
            return;
        }

        captcha.classList.remove("is-invalid");
    });

    // Solo se permiten números y se quita el aviso al corregir
    captcha.addEventListener("input", () => {
        captcha.value = captcha.value.replace(/[^0-9]/g, "");
        captcha.classList.remove("is-invalid");
    });
}

/**
 * PASO 3: valida el formato del número de documento. El PHP comprueba si coincide con la cuenta.
 */
function configurarFormularioDocumento() {
    const form = document.getElementById("formDocumento");
    const documento = document.getElementById("numero_documento");
    if (!form || !documento) return;

    form.addEventListener("submit", (event) => {
        if (!/^[A-Za-z0-9]{5,20}$/.test(documento.value.trim())) {
            event.preventDefault();
            documento.classList.add("is-invalid");
            return;
        }

        documento.classList.remove("is-invalid");
    });

    documento.addEventListener("input", () => documento.classList.remove("is-invalid"));
}

/**
 * PASO 4: valida longitud y coincidencia de contraseñas. Si todo está bien,
 * el formulario se envía al PHP (ya NO se simula el éxito).
 */
function configurarEnvioFormulario() {
    const form = document.getElementById("formRecuperar");
    const newPassword = document.getElementById("newPassword");
    const confirmPassword = document.getElementById("confirmPassword");
    const mismatchAlert = document.getElementById("passwordMismatchAlert");

    if (!form) return;

    form.addEventListener("submit", (event) => {
        const contrasenasCoinciden = newPassword.value === confirmPassword.value && confirmPassword.value !== "";
        const cumpleRequisito = newPassword.value.length >= 8;

        // Comprobación de coincidencia
        if (!contrasenasCoinciden) {
            confirmPassword.classList.add("is-invalid");
            mismatchAlert.classList.remove("d-none");
        } else {
            confirmPassword.classList.remove("is-invalid");
            mismatchAlert.classList.add("d-none");
        }

        // Si algo falla, se bloquea el envío y se muestran las validaciones visuales
        if (!form.checkValidity() || !contrasenasCoinciden || !cumpleRequisito) {
            event.preventDefault();
            event.stopPropagation();
            form.classList.add("was-validated");
            return;
        }

        // Todo correcto: el formulario viaja por POST al PHP
    });

    // Ocultar alerta de desajuste cuando el usuario rectifique la contraseña
    [newPassword, confirmPassword].forEach(input => {
        input.addEventListener("input", () => {
            if (newPassword.value === confirmPassword.value) {
                confirmPassword.classList.remove("is-invalid");
                mismatchAlert.classList.add("d-none");
            }
        });
    });
}

/**
 * Decide qué formulario se ve según la dirección de la página y muestra
 * los mensajes que devuelve el PHP (?paso=..., ?error=..., ?restan=..., ?exito=1).
 * OJO: este "paso" solo cambia lo que se ve. La seguridad real la comprueba el PHP.
 */
function configurarPasoYMensajes() {
    const params = new URLSearchParams(window.location.search);

    const formularios = {
        correo: document.getElementById("formSolicitar"),
        captcha: document.getElementById("formCaptcha"),
        documento: document.getElementById("formDocumento"),
        clave: document.getElementById("formRecuperar")
    };
    const textos = {
        correo: document.getElementById("textoPasoCorreo"),
        captcha: document.getElementById("textoPasoCaptcha"),
        documento: document.getElementById("textoPasoDocumento"),
        clave: document.getElementById("textoPasoClave")
    };

    // --- Qué paso mostrar (solo se aceptan estos valores) ---
    const pasoUrl = params.get("paso");
    let paso = ["captcha", "documento", "clave"].includes(pasoUrl) ? pasoUrl : "correo";

    // El captcha necesita los dos números de la suma, que vienen en la dirección (a y b)
    const a = params.get("a") || "";
    const b = params.get("b") || "";
    const preguntaValida = /^[0-9]{1,2}$/.test(a) && /^[0-9]{1,2}$/.test(b);

    if (paso === "captcha" && !preguntaValida) {
        paso = "correo";
    }
    if (paso === "captcha") {
        document.getElementById("preguntaCaptcha").textContent = `${a} + ${b} =`;
    }

    Object.keys(formularios).forEach((clave) => {
        formularios[clave].classList.toggle("d-none", clave !== paso);
        textos[clave].classList.toggle("d-none", clave !== paso);
    });

    // --- Mensajes fijos (nunca se pone en la página texto tomado de la URL) ---
    const errores = {
        correo_invalido: "Ingresa un correo electrónico válido.",
        campos_vacios: "Por favor completa todos los campos.",
        captcha_invalido: "Respuesta incorrecta. Intenta con esta nueva operación.",
        captcha_bloqueado: "Superaste los 5 intentos del captcha. Espera 1 minuto e inténtalo de nuevo.",
        datos_invalidos: "Los datos no coinciden. Verifica e intenta de nuevo.",
        bloqueado: "Superaste los 5 intentos. Espera 1 minuto e inténtalo de nuevo.",
        sesion_expirada: "Tu solicitud venció. Vuelve a comenzar.",
        password_corta: "La contraseña debe tener al menos 8 caracteres.",
        password_no_coincide: "Las contraseñas no coinciden.",
        metodo_no_permitido: "Usa el formulario para continuar.",
        error_servidor: "Ocurrió un error en el servidor. Intenta de nuevo más tarde."
    };

    const error = params.get("error");
    const restanUrl = params.get("restan") || "";
    const restan = /^[0-9]$/.test(restanUrl) ? Number(restanUrl) : null;

    if (error) {
        let mensaje = errores[error] || "No fue posible completar la solicitud.";
        if (restan !== null && (error === "captcha_invalido" || error === "datos_invalidos")) {
            mensaje += ` Te ${restan === 1 ? "queda 1 intento" : "quedan " + restan + " intentos"}.`;
        }
        mostrarAlerta(mensaje, "danger");
    } else if (params.get("exito") === "1") {
        // Cambio de contraseña confirmado por el servidor: se oculta todo y se va al login
        Object.values(formularios).forEach((form) => form.classList.add("d-none"));
        mostrarAlerta("¡Tu contraseña ha sido actualizada correctamente! Redirigiendo al inicio de sesión...", "success");
        setTimeout(() => {
            window.location.href = "login.html";
        }, 2500);
    }

    // --- Limpia la dirección para que al recargar no se repita el mensaje (se conserva el paso) ---
    if (error || params.has("restan") || params.has("exito")) {
        let nuevaUrl = window.location.pathname;
        if (paso !== "correo") {
            nuevaUrl += "?paso=" + paso;
            if (paso === "captcha") {
                nuevaUrl += "&a=" + a + "&b=" + b;
            }
        }
        window.history.replaceState({}, document.title, nuevaUrl);
    }
}

/**
 * Inyecta una alerta de Bootstrap en el contenedor de mensajes
 */
function mostrarAlerta(mensaje, tipo) {
    const contenedor = document.getElementById("alert-container");
    const iconos = {
        success: "bi-check-circle-fill",
        info: "bi-info-circle-fill",
        danger: "bi-exclamation-triangle-fill"
    };
    contenedor.innerHTML = `
        <div class="alert alert-${tipo} alert-dismissible fade show rounded-3 py-2 px-3 small" role="alert">
            <i class="bi ${iconos[tipo] || iconos.danger} me-2"></i>
            ${mensaje}
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
}