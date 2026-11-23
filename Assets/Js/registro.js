document.addEventListener("DOMContentLoaded", () => {
    // 1. Carga dinámica de componentes reutilizables (Header y Footer)
    cargarComponentes();

    // 2. Funcionalidad de Mostrar / Ocultar Contraseña
    configurarVisibilidadPassword("password", "togglePassword", "iconPassword");
    configurarVisibilidadPassword("confirmPassword", "toggleConfirmPassword", "iconConfirmPassword");

    // 3. Validación interactiva del formulario y coincidencia de contraseñas
    configurarValidacionFormulario();
});

/**
 * Carga los archivos HTML parciales del header y footer mediante fetch()
 */
async function cargarComponentes() {
    try {
        // Carga del Header
        const headerResponse = await fetch('../../components/header.html');
        if (headerResponse.ok) {
            const headerHtml = await headerResponse.text();
            document.getElementById('header-container').innerHTML = headerHtml;
        }

        // Carga del Footer
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
 * Configura el botón para alternar entre mostrar y ocultar texto de contraseña
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
 * Controla la validación visual de Bootstrap y la comprobación de contraseñas iguales
 */
function configurarValidacionFormulario() {
    const form = document.getElementById("formRegistro");
    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirmPassword");
    const alertBox = document.getElementById("passwordMismatchAlert");

    form.addEventListener("submit", (event) => {
        let isValid = true;

        // Validar coincidencia de contraseñas
        if (password.value !== confirmPassword.value) {
            confirmPassword.classList.add("is-invalid");
            alertBox.classList.remove("d-none");
            isValid = false;
        } else {
            confirmPassword.classList.remove("is-invalid");
            alertBox.classList.add("d-none");
        }

        // Validaciones generales de campos requeridos de Bootstrap
        if (!form.checkValidity() || !isValid) {
            event.preventDefault();
            event.stopPropagation();
        }

        form.classList.add("was-validated");
    }, false);

    // Ocultar alerta de desajuste al escribir en cualquiera de los dos campos
    [password, confirmPassword].forEach(input => {
        input.addEventListener("input", () => {
            if (password.value === confirmPassword.value) {
                confirmPassword.classList.remove("is-invalid");
                alertBox.classList.add("d-none");
            }
        });
    });
}