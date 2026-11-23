# EnglishFuture

Aplicación web para la gestión de un centro de enseñanza de inglés. Incluye una página de inicio y módulos para categorías, horarios, pagos y gestión de usuarios.

## Tecnologías

- HTML, CSS y JavaScript
- PHP
- MySQL mediante PDO
- Bootstrap 5 y Bootstrap Icons desde CDN

## Estructura

```text
Assets/          Estilos, scripts, imágenes y fuentes
Components/      Componentes compartidos, como encabezado y pie de página
Config/          Configuración de la conexión a la base de datos
Controllers/     Endpoints PHP para los módulos
Views/           Páginas HTML de la aplicación
index.html       Página de inicio
```

## Requisitos

- XAMPP (Apache, PHP y MySQL)
- Navegador web
- Conexión a Internet para cargar Bootstrap y Bootstrap Icons desde sus CDN

## Ejecución local

1. Copia o clona el proyecto dentro de la carpeta `htdocs` de XAMPP.
2. Inicia Apache y MySQL desde el panel de XAMPP.
3. Configura los datos de conexión en `Config/database.php` para que coincidan con tu instancia de MySQL.
4. Asegúrate de que la base de datos y las tablas requeridas por los controladores estén creadas.
5. Abre `http://localhost/EnglishFuture-Alejandro%20Agudelo-3336227/` en el navegador.

## Módulo de pagos y comprobantes

La gestión de pagos está en `Views/Pagos/pagos.html`. Permite consultar pagos, filtrar por estado y método, registrar pagos y anular registros. La información se obtiene y modifica mediante `Controllers/Pagos/pagos.php`.

Para consultar un comprobante, abre `Views/Pagos/comprobante.html` con el identificador del pago en la URL:

```text
http://localhost/EnglishFuture-Alejandro%20Agudelo-3336227/Views/Pagos/comprobante.html?id=7
```

También se acepta el código de recibo, por ejemplo `?id=REC-2026-007`. La página solicita los datos a `Controllers/Pagos/comprobante.php`; desde el comprobante se puede volver a la gestión de pagos o usar **Imprimir Comprobante**.

El controlador del comprobante consulta la tabla `pago` y espera las columnas `id_pago`, `estudiante`, `valor_pagado`, `fecha_pago`, `medio_pago`, `estado` y `concepto`. El código de conexión está en `Config/database.php`; ajusta sus valores según tu instalación local.

## Otros módulos

- `Views/Categoria/`: categorías de cursos.
- `Views/Horarios/`: consulta y gestión de horarios.
- `Views/Seguridad/`: inicio de sesión, registro y recuperación de contraseña.

