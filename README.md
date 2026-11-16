# KaloAI

[![Tests](https://github.com/felixcorcoran/kaloAI/actions/workflows/tests.yml/badge.svg)](https://github.com/felixcorcoran/kaloAI/actions/workflows/tests.yml)

Plataforma de nutrición con recetas generadas por IA según las calorías, los objetivos y las intolerancias de cada persona. Incluye plan semanal, inventario con lista de la compra y tres roles: usuario, nutricionista y administración.

**Demo:** [kaloai.infinityfreeapp.com](https://kaloai.infinityfreeapp.com/?i=1) · La interfaz está en español.

| Modo claro | Modo oscuro |
| --- | --- |
| ![KaloAI en modo claro](docs/kalo-claro.jpg) | ![KaloAI en modo oscuro](docs/kalo-oscuro.jpg) |

## Qué hace

- **Recetas con IA:** Google Gemini genera recetas a partir del perfil (calorías, macros, alergias e intolerancias), de lo que ya hay planificado ese día y del inventario. Las alergias van en el prompt y, además, el servidor revisa la receta que devuelve la IA: si incluye un alimento prohibido, la descarta y pide otra.
- **Rellenar la semana con IA:** en una sola llamada, la IA propone varias recetas para cada comida elegida y se reparten por los huecos libres del plan, sin tocar lo ya planificado ni pasar del objetivo diario de calorías.
- **Lo que se come de verdad:** cada comida del plan se marca como «Hecho» y lo que se toma fuera del plan se apunta a mano (la IA puede estimar sus calorías). El panel «Hoy» y la gráfica de la semana comparan lo planificado con lo comido, y el nutricionista ve cuántos días apunta cada paciente.
- **Plan por semanas:** cada semana tiene su plan; se puede volver a las pasadas (con lo que se comió cada día), preparar las siguientes y copiar la semana anterior en los huecos libres.
- **Raciones y plantillas:** cada comida del plan puede ser de ½ a 3 raciones (las calorías, lo comido y la lista de la compra lo multiplican). Una semana que ha salido bien se guarda como plantilla («Semana de entreno») y se aplica a otra en un clic, en los huecos libres o sustituyendo la semana.
- **Inventario y lista de la compra** de esta semana o de la que viene, agrupada por secciones del súper; la lista se descarga en PDF (Dompdf) o se comparte con un enlace de solo lectura para otra persona de la casa (sin cuenta; lo que marca solo se tacha en su móvil).
- **Despensa desde el tique:** una foto del tique de la compra y Gemini (visión) saca los productos con sus cantidades; la persona los revisa antes de guardarlos. La foto se reduce en el navegador antes de subirla y no se guarda.
- **Favoritas y comensales:** cada receta se puede marcar como favorita (filtro propio y primeras al elegir receta para el plan). En el perfil se elige para cuántas personas se cocina: la lista de la compra multiplica las cantidades y el detalle de una receta se puede ver para N personas.
- **Recetas escritas a mano:** la IA puede calcular sus calorías y macros por ración a partir de los ingredientes.
- **Resumen semanal por correo** (si se activa en el perfil): cada lunes, lo que se comió frente al objetivo, el cambio de peso y un enlace para preparar la semana, con baja en un clic. InfinityFree no tiene tareas programadas y bloquea las peticiones que no vienen de un navegador, así que los envíos se hacen desde las visitas: a partir del lunes a las 7:00, cada visita envía unos pocos cuando ya ha respondido (`core/resumen_semanal.php`).
- **Recuperar la contraseña:** enlace por correo (Resend) que caduca en una hora y sirve una sola vez; en la base de datos solo se guarda su hash.
- **Tu cuenta:** cada persona puede descargar todos sus datos en JSON o borrar su cuenta (con su contraseña).
- **App instalable (PWA):** se puede añadir a la pantalla de inicio del móvil; sin conexión muestra un aviso. El service worker solo guarda estilos, scripts e iconos, nunca páginas con datos personales.
- **Progreso:** gráficos de peso y macros con Chart.js.
- **Roles:** el nutricionista vincula pacientes, sigue su evolución en una ficha (peso, lo que come cada día, constancia y plan de la semana) sin entrar en su cuenta, les deja notas que pueden responder (una conversación sencilla, con aviso de mensajes nuevos en su lista de pacientes) y, si hace falta, gestiona su cuenta en un modo aparte con una barra que lo indica; el administrador gestiona usuarios.
- **Accesibilidad:** cada gráfica tiene debajo sus datos en una tabla («Ver los datos») para lectores de pantalla, y en los modales el tabulador no se escapa y el foco vuelve al botón que los abrió.
- **Sin CDNs:** Chart.js y las tipografías se sirven desde la propia web (`public/lib`, `public/fonts`); Chart.js solo se carga en las páginas con gráficas, y el service worker guarda esas copias para que la app instalada vaya bien con mala conexión.
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

Toda la interfaz usa un mismo sistema de componentes (`k-*`) e iconos; la guía para pantallas nuevas está en [`docs/estilo.md`](docs/estilo.md).

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
| `RESEND_API_KEY` | clave de [Resend](https://resend.com) para enviar correos (recuperar la contraseña). Sin ella, la web funciona igual pero no envía correos |

El despliegue escribe la clave de Resend en `core/secretos.php` (fuera del repositorio) y la sube con lo demás. Opcionales, en la pestaña *Variables*: `CORREO_CONTACTO` (adónde se avisan los mensajes de la página de contacto; sin ella solo se ven en el panel de administración), `CORREO_REMITENTE` (por defecto `KaloAI <no-reply@felixcorcoran.es>`; tiene que ser de un dominio verificado en Resend), `FTP_SERVER` (por defecto `ftpupload.net`), `FTP_DIR` (por defecto `htdocs/`) y `FTP_PROTOCOL` (por defecto `ftps`; pon `ftp` solo si tu cuenta no admite conexiones cifradas). Sin los secretos, el despliegue se salta con un aviso. Para volver a desplegar sin cambios: pestaña *Actions* → *Tests* → *Run workflow* sobre `main`.

**Para escribir una migración nueva:** crea `database/migraciones/009_lo_que_hace.sql`, aplica el mismo cambio en `database/schema.sql` y añade el nombre del archivo a la lista del final de ese esquema (un test avisa si se olvida).

---

Hecho por [Félix Corcoran](https://dev.felixcorcoran.es) · desarrollador full stack e integración de IA.
