document.addEventListener("DOMContentLoaded", () => {
    // Función para cargar componentes reutilizables
    const loadComponent = async (containerId, filePath) => {
        try {
            const response = await fetch(filePath);
            if (!response.ok) throw new Error(`No se pudo cargar ${filePath}`);
            const html = await response.text();
            document.getElementById(containerId).innerHTML = html;
        } catch (error) {
            console.error("Error al cargar el componente:", error);
        }
    };

    // Cargar Header y Footer en sus respectivos contenedores
    loadComponent("header-container", "components/header.html");
    loadComponent("footer-container", "components/footer.html");
});