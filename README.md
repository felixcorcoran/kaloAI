# KaloAI

[![Tests](https://github.com/felixcorcoran/kaloAI/actions/workflows/tests.yml/badge.svg)](https://github.com/felixcorcoran/kaloAI/actions/workflows/tests.yml)

Plataforma de nutrición con recetas generadas por IA según las calorías, los objetivos y las intolerancias de cada persona. Incluye plan semanal, inventario con lista de la compra y tres roles: usuario, nutricionista y administración.

**Demo:** [kaloai.infinityfreeapp.com](https://kaloai.infinityfreeapp.com/?i=1) · La interfaz está en español.

| Modo claro | Modo oscuro |
| --- | --- |
| ![KaloAI en modo claro](docs/kalo-claro.jpg) | ![KaloAI en modo oscuro](docs/kalo-oscuro.jpg) |

## Qué hace

- **Recetas con IA:** Google Gemini genera recetas a partir del perfil (calorías, macros, alergias e intolerancias), de lo que ya hay planificado ese día y del inventario. Las alergias van en el prompt y, además, el servidor revisa la receta que devuelve la IA: si incluye un alimento prohibido, la descarta y pide otra.
- **Plan semanal, inventario y lista de la compra:** la lista se descarga en PDF (Dompdf).
- **Progreso:** gráficos de peso y macros con Chart.js.
- **Roles:** el nutricionista vincula pacientes y puede gestionar su cuenta en un modo aparte, con una barra que lo indica; el administrador gestiona usuarios.
- **Modo claro y oscuro:** sigue el tema del sistema o el que elijas con el botón de la cabecera, y lo recuerda.

## Cómo está hecho

- **PHP 8 sin framework**, con la lógica en `core/` y las vistas en `views/` (por rol: `user`, `nutri`, `admin`, `auth`).
- **MySQL con PDO** y sentencias preparadas reales; contraseñas con `password_hash` / `password_verify`.
- **Tailwind CSS** compilado (`public/css/tailwind.css`, solo con las clases que se usan) y JavaScript sin dependencias para la interfaz.
- **Modo oscuro** en `public/css/oscuro.css`: da su versión oscura a las clases de Tailwind que usa la web, sin repetir variantes en cada vista.
- **Seguridad:** token CSRF en todo POST (comprobado en un único punto, `core/seguridad.php`), acciones que cambian datos solo por POST, texto escapado al mostrarlo (`e()` y `json_html()`), cada consulta filtrada por el usuario dueño de los datos y la clave de Gemini en cabecera con TLS verificado.
- **Tests:** PHPUnit con tests unitarios y de integración contra MariaDB, ejecutados en GitHub Actions en cada push.

```
core/        conexión, seguridad, utilidades, recetas y plan, llamadas a Gemini
views/       páginas por rol y plantillas comunes (cabecera, pie, tema)
public/      CSS, JavaScript, contacto y privacidad
database/    esquema, datos de ejemplo y migraciones
tests/       tests unitarios y de integración (PHPUnit)
images/      logotipo y vista previa para redes
```

## Ponerlo en marcha en local

**Con Docker**, un solo comando:

```bash
GEMINI_API_KEY=tu_clave docker compose up --build
```

Abre <http://localhost:8080>. La clave es opcional: sin ella funciona todo menos la generación de recetas.

**Sin Docker** (PHP 8.1+ y MySQL 8 o MariaDB 10.6+):

1. Instala las dependencias: `composer install`.
2. Crea la base de datos y carga el esquema y los datos de ejemplo:
   `mysql -u root -p kaloai_db < database/schema.sql` y después `database/datos_demo.sql`.
3. Copia `core/configuracion-example.php` como `core/configuracion.php`. Lee la base de datos y la clave de Gemini de variables de entorno (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `GEMINI_API_KEY`), o puedes escribirlas en el propio archivo, que no se sube al repositorio.
4. Arranca un servidor PHP en la carpeta del proyecto: `php -S localhost:8000`. La URL base se ajusta sola en local, también si el proyecto está en una subcarpeta de XAMPP.

### Estilos

Tailwind se compila a `public/css/tailwind.css`, que se sube al repositorio para que el hosting no necesite Node. Si cambias clases en una vista, recompílalo (la CI avisa si se te olvida):

```bash
npm install
npm run build:css     # o npm run watch:css mientras desarrollas
```

### Cuentas de prueba

Todas con la contraseña `kaloai1234`:

| Correo | Rol |
| --- | --- |
| `laura@kaloai.test` | usuaria con perfil, inventario, recetas, plan y progreso |
| `marcos@kaloai.test` | usuario recién registrado, con una invitación pendiente |
| `nutri@kaloai.test` | nutricionista, con Laura como paciente |
| `admin@kaloai.test` | administración |

### Tests

```bash
vendor/bin/phpunit --testsuite unitarios      # sin base de datos
KALOAI_TEST_DB_NAME=kaloai_test DB_USER=root DB_PASS=... vendor/bin/phpunit
```

Los de integración borran y vuelven a crear las tablas de la base de datos indicada, así que su nombre tiene que terminar en `_test`.

## Despliegue

Automático: al fusionar en `main`, GitHub Actions pasa los tests y, si van bien, sube a InfinityFree por FTP solo lo que ha cambiado (`.github/workflows/tests.yml`). Tu `core/configuracion.php` del servidor nunca se sobrescribe.

**Base de datos:** no hay que hacer nada a mano. Las migraciones de `database/migraciones/` las aplica la propia web en la primera visita tras el despliegue (InfinityFree no deja conectarse a MySQL desde fuera). Cada una se aplica una sola vez y queda apuntada en la tabla `migraciones`; si una falla, se registra en el log de errores y la web sigue funcionando.

**Configuración inicial (una sola vez)**, en GitHub → *Settings* → *Secrets and variables* → *Actions*:

| Secreto | Valor |
| --- | --- |
| `FTP_USERNAME` | el usuario FTP de tu cuenta de InfinityFree (empieza por `if0_`) |
| `FTP_PASSWORD` | la contraseña de esa cuenta (la del panel del hosting, no la del área de cliente) |

Opcionales, en la pestaña *Variables*: `FTP_SERVER` (por defecto `ftpupload.net`), `FTP_DIR` (por defecto `htdocs/`) y `FTP_PROTOCOL` (por defecto `ftps`; pon `ftp` solo si tu cuenta no admite conexiones cifradas). Sin los secretos, el despliegue se salta con un aviso. Para volver a desplegar sin cambios: pestaña *Actions* → *Tests* → *Run workflow* sobre `main`.

**Para escribir una migración nueva:** crea `database/migraciones/003_lo_que_hace.sql`, aplica el mismo cambio en `database/schema.sql` y añade el nombre del archivo a la lista del final de ese esquema (un test avisa si se olvida).

---

Hecho por [Félix Corcoran](https://dev.felixcorcoran.es) · desarrollador full stack e integración de IA.
