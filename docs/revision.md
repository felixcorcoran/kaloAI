# Revisión completa de KaloAI (noviembre de 2026)

Recorrido por toda la aplicación con los tres roles (persona usuaria, nutricionista y administración), en modo claro y oscuro, en escritorio (1440 px) y en móvil (390 px), con una cuenta llena de datos y con una cuenta recién creada.

## Lo que se ha encontrado y corregido

### Fallos

| Dónde | Qué pasaba | Arreglo |
| --- | --- | --- |
| «Hoy» en móvil | La página medía 481 px en una pantalla de 390: se podía desplazar hacia los lados. Un título largo en «Comidas de hoy» ensanchaba su tarjeta. | Regla general: una tarjeta dentro de una rejilla nunca se ensancha por un texto largo. Revisadas las demás páginas a 390 px: ninguna se sale. |
| Ficha del paciente en móvil | Lo mismo, por 2 px, en «Lo último que ha apuntado». | La misma regla. |
| Contacto | El formulario no enviaba nada y mostraba un correo de soporte que no existe. | Los mensajes se guardan y el administrador los ve en su panel (y le llegan por correo si se configura `CORREO_CONTACTO`). Con campo trampa para robots y un mensaje por minuto como mucho. |
| Progreso | Si ese día ya había un registro, decía «Borra el anterior para actualizar». | Ahora lo sustituye y avisa. |
| Cuenta bloqueada | El aviso pedía escribir a un «soporte» inexistente. | Remite a la página de contacto. |
| Cabecera | Se cargaba SweetAlert2 desde un CDN en todas las páginas sin usarse. | Eliminado: una petición externa menos en cada página. |

### Experiencia de uso

| Mejora | Por qué |
| --- | --- |
| **Primeros pasos** en «Hoy» | Una cuenta nueva veía un panel lleno de ceros. Ahora ve cinco pasos (perfil, alergias, despensa, plan, apuntar comidas) que se marcan solos y desaparecen al completar los imprescindibles. |
| **Entrar al registrarse** | Antes, tras crear la cuenta, había que volver a escribir correo y contraseña. Ahora entra directamente y va al perfil con un mensaje de bienvenida. |
| **Registrar el peso desde «Hoy»** | Ventana rápida en el propio panel (peso y cintura), sin ir a otra página. |
| **Añadir al plan desde la receta** | Antes una receta guardada solo se podía poner en el plan desde un hueco vacío. Ahora, desde su detalle: semana, día y comida. |
| **«Comidas de hoy» primero en móvil** | Es lo que más se usa a diario; en móvil quedaba debajo de dos gráficas. |
| **Accesos rápidos sin cortar** | Los textos se cortaban («Tus menús de los …»). |
| **Portada** | Solo contaba los tres pasos del principio. Nueva sección con todo lo que hace ahora: semana con IA, lo que comes, compra por secciones, progreso, nutricionista y app en el móvil. |

## Propuestas para las siguientes rondas

Ordenadas por lo que aportan frente a lo que cuestan.

1. **Resumen semanal por correo.** Cada lunes, un correo con lo que se comió frente al objetivo, el cambio de peso y un enlace para preparar la semana. InfinityFree no tiene tareas programadas, pero GitHub Actions puede llamar cada semana a una dirección protegida de la web, y Resend ya está listo.
2. **Responder a las notas del nutricionista.** Ahora las notas van en un solo sentido; con respuestas sería una conversación sencilla dentro de la ficha.
3. **Plantillas de semana.** Guardar una semana que salió bien («Semana de entreno», «Semana ligera») y aplicarla en un clic, además de «Copiar la semana anterior».
4. **Raciones por comida en el plan.** La columna ya existe; faltaría poder decir «esta cena, ración y media» desde el plan.
5. **Añadir a la despensa con una foto del tique** usando la IA con visión de Gemini.
6. **Compartir la lista de la compra** con un enlace de solo lectura para otra persona de la casa.
7. **Accesibilidad.** Auditoría con lector de pantalla y teclado de los modales y de las gráficas (añadir una tabla alternativa a cada gráfica).
8. **Rendimiento.** Servir Chart.js y las fuentes desde la propia web en vez de un CDN, para que la app instalada funcione mejor con mala conexión.
