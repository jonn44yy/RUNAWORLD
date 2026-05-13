# RUNAWORLD

RUNAWORLD es un juego web idle/gacha basado en runas, progresión incremental y animaciones visuales. El jugador lanza runas desde una interfaz principal, obtiene recursos, desbloquea mejoras, completa colecciones y progresa mediante monedas, puntos, bulk, suerte y sistemas de rareza.

El proyecto combina una interfaz oscura de estilo místico/gótico con lógica de juego persistente en backend, animaciones en Canvas/SVG y un panel de administración para gestionar contenido y usuarios.

---

## Enlace del proyecto

**Web:** https://runaworld.online

---

## Estado actual

**Versión actual:** `0.3V`  
**Estado:** publicado / en desarrollo activo  
**Tipo de proyecto:** juego web con backend PHP y base de datos MySQL

---

## Descripción general

RUNAWORLD se centra en una acción principal: lanzar runas. Cada tirada puede generar una runa de distinta rareza, desde runas comunes hasta runas especiales con animaciones propias. La progresión se apoya en:

- obtención de monedas y puntos;
- mejoras comprables en tienda;
- sistema de rarezas;
- multiplicadores de suerte;
- bulk de tiradas;
- colección de runas normales y corruptas;
- estadísticas persistentes;
- panel de administración.

El objetivo principal del proyecto es construir un juego sencillo de entender, pero con una arquitectura suficientemente sólida para soportar progresión, sincronización con servidor, colección, administración y futuras expansiones.

---

### Página de inicio / landing

<img width="1919" height="939" alt="image" src="https://github.com/user-attachments/assets/d5e18739-6d69-441e-91f8-d2ea74b4da29" />


---

### Login y registro

<img width="1919" height="945" alt="image" src="https://github.com/user-attachments/assets/f9c79a03-5bb4-40c9-a820-9d3ec494483c" />
<img width="1919" height="941" alt="image" src="https://github.com/user-attachments/assets/c201199a-c95d-4c2f-b9f3-89ad3ec6f254" />


---

### Tirada de runas

<img width="1919" height="944" alt="image" src="https://github.com/user-attachments/assets/dca72fb0-08db-4d23-a9ef-1f922c0f3118" />


---

### Colección de runas

<img width="1919" height="943" alt="image" src="https://github.com/user-attachments/assets/56bdc8d0-84db-441f-af37-2160b6286c04" />


---

### Colección móvil

<img width="613" height="940" alt="image" src="https://github.com/user-attachments/assets/90395bcd-4292-40d1-be6e-4496435d21d0" />


---

### Tienda de mejoras

<img width="1919" height="943" alt="image" src="https://github.com/user-attachments/assets/10a0c905-1d98-43a1-9636-64069c409aba" />


---

### Estadísticas del jugador

<img width="1919" height="939" alt="image" src="https://github.com/user-attachments/assets/db70a377-e769-4c76-bb0e-9cc0dc66d24d" />


---

### Nueva sección Engranajes

<img width="1919" height="944" alt="image" src="https://github.com/user-attachments/assets/5467e6f5-192d-491f-a8b0-7e46928c8279" />


---

### Ajustes

<img width="1918" height="946" alt="image" src="https://github.com/user-attachments/assets/002bae00-27d3-472b-9d75-28dbf70856e3" />


---

### Panel de administración

<img width="1919" height="939" alt="image" src="https://github.com/user-attachments/assets/b2154fa2-2ad6-4b46-9898-c5132e4b26ef" />


---

### Gestión de mensajes / solicitudes de admin

<img width="1919" height="943" alt="image" src="https://github.com/user-attachments/assets/be0e1df1-84c5-46f6-aa8a-39fd6d2e6b7a" />


---

## Características principales

### Juego

- Sistema de lanzamiento de runas.
- Rarezas diferenciadas por probabilidad.
- Runas comunes, poco comunes, raras, épicas, legendarias, míticas, divinas y eternas.
- Runas corruptas como variantes especiales.
- Animaciones especiales para rarezas importantes.
- Sistema de recursos con monedas y puntos.
- Producción por segundo.
- Bulk de tiradas.
- Multiplicadores de suerte.
- Estadísticas persistentes del jugador.

### Colección

- Listado de runas desbloqueadas y bloqueadas.
- Separación entre runas especiales y comunes.
- Vista de runas normales y corruptas.
- Bonus de colección.
- Vista previa de runas mediante iframe/canvas.
- Botón para ver animación completa.
- Toggle para activar/desactivar animaciones de runas.

### Tienda

- Sistema de mejoras comprables.
- Mejoras de producción.
- Mejoras de multiplicadores.
- Mejoras de bulk.
- Costes escalables.
- Actualización visual de nivel, coste y estado de cada mejora.

### Administración

- Panel privado para usuarios administradores.
- Gestión de usuarios.
- Edición de cuentas.
- Edición de progreso.
- Gestión de runas.
- Gestión de mejoras.
- Gestión de tienda.
- Panel de mensajes.
- Nuevo apartado para solicitudes de acceso a administración.

---

## Novedades de la versión 0.3V

La versión `0.3V` introduce mejoras importantes en interfaz, administración, rendimiento, colección y experiencia móvil.

### Cambios de administración

- Mejoras visuales y funcionales en el panel de administración.
- Actualización de páginas internas del panel admin.
- Mejora de la gestión de usuarios, runas, mejoras y progreso.
- Nuevo sistema para recibir solicitudes de administración desde el index.
- Nuevo apartado en `mensajes.php` para identificar solicitudes de admin.
- Las solicitudes aparecen con formato:

```text
Solicitud para ser admin de: usuario
Mensaje: mensaje enviado por el jugador
```

### Cambios en el index

- Actualización del changelog público a versión `0.3V`.
- Añadido resumen de los cambios principales.
- Añadida sección para solicitar acceso al panel de administración.
- Formulario conectado al sistema de mensajes del administrador.

### Cambios en móvil y responsive

- Revisión general del diseño móvil.
- Rediseño del topbar móvil.
- Mejor integración de los botones laterales en la barra superior.
- Ajustes para móvil y tablet.
- Corrección de problemas visuales entre resoluciones intermedias.
- Mejor separación entre paneles móviles.
- Corrección de títulos duplicados en drawer y colección.

### Cambios en colección

- Reorganización de la colección en móvil.
- Vista previa de runa más grande.
- Canvas de runa ampliado para mejorar lectura visual.
- Corrección de solapamientos entre canvas, botones y lista de runas.
- Mejor visualización de runas desbloqueadas y bloqueadas.
- Corrección de la visualización de bonus normales y corruptos.
- El bonus normal solo aparece en su variante correspondiente.
- El bonus corrupto no aparece junto al normal si no corresponde.

### Cambios visuales

- Corrección de botones de animación en colección.
- Ajuste de líneas neon que se cortaban visualmente.
- Mejora del estilo de grupos como `Runas Básicas` en móvil.
- Mejoras visuales en Ajustes.
- Revisión del botón/modal de eliminar cuenta para acercarlo al estilo general del juego.

### Cambios de rendimiento

- Optimización de `intro.html`.
- Reducción de filtros pesados.
- Reducción de sombras excesivas.
- Animación de entrada más ligera para móviles y equipos con menor rendimiento.
- Uso de animaciones más baratas basadas en `transform` y `opacity`.

### Cambios en ajustes

- Nueva opción: `Animación de entrada`.
- El jugador puede desactivar la intro después de haberla visto.
- La preferencia se guarda en el navegador mediante `localStorage`.

### Nueva sección Engranajes

- Añadida nueva pestaña `Engranajes`.
- De momento funciona como sección en construcción.
- Preparada para futuros sistemas del juego.

### Mantenimiento y backend

- Mejoras en endpoints relacionados con acciones del jugador.
- Revisión de flujos de packs/tiradas.
- Limpieza y preparación de scripts de mantenimiento.
- Mejor organización de cambios PHP para cuenta, progreso, mensajes y tiradas.

---

## Tecnologías utilizadas

### Frontend

- HTML5
- CSS3
- JavaScript
- Canvas
- SVG
- Diseño responsive para escritorio, móvil y tablet

### Backend

- PHP
- MySQL
- Sesiones PHP
- Endpoints internos para acciones del juego

### Base de datos

- MySQL / phpMyAdmin
- Tablas para usuarios, jugadores, runas, mejoras, estadísticas y mensajes

### Herramientas

- Visual Studio Code
- Git / GitHub
- XAMPP para entorno local
- Hostinger para despliegue

---

## Arquitectura resumida

RUNAWORLD separa la experiencia en varias capas:

1. **Interfaz del jugador**
   - `index.php`
   - `juego.php`
   - secciones de tirada, tienda, colección, estadísticas, ajustes y engranajes.

2. **Lógica visual**
   - Archivos JavaScript para tiradas, colección, tienda, móvil, ajustes y sincronización.
   - Animaciones en `ANIMACIONES_HTML` y `RUNAS_HTML`.

3. **Backend**
   - Endpoints PHP para tirar runas, comprar mejoras, editar cuenta, borrar progreso, eliminar cuenta, mensajes y administración.

4. **Persistencia**
   - Base de datos MySQL para guardar usuario, jugador, progreso, runas, mejoras y estadísticas.

5. **Administración**
   - Carpeta `ADMIN` con paneles para gestionar datos del juego.

---

## Estructura orientativa del proyecto

```text
PROYECTOFINAL_JON/
│
├── ADMIN/
│   ├── index.php
│   ├── mensajes.php
│   ├── usuarios.php
│   ├── runas.php
│   ├── tienda.php
│   └── ...
│
├── ANIMACIONES_HTML/
│   ├── intro.html
│   └── borrado_cuenta_animacion.html
│
├── CSS/
│   ├── style.css
│   ├── style_phone.css
│   └── ...
│
├── JS/
│   ├── tirada.js
│   ├── runa-sync.js
│   ├── coleccion.js
│   ├── mobile.js
│   ├── ajustes.js
│   ├── tienda.js
│   └── ui.js
│
├── PHP/
│   ├── tirar_runa.php
│   ├── comprar_mejora.php
│   ├── borrar_progreso.php
│   ├── eliminar_cuenta.php
│   ├── enviar_mensaje.php
│   └── ...
│
├── RUNAS_HTML/
│   ├── RUNAS/
│   ├── RUNAS_ANIMADAS/
│   └── ...
│
├── index.php
├── juego.php
├── login.php
├── registro.php
└── README.md
```

---

## Seguridad y sincronización

El juego usa el servidor como fuente principal de verdad para las acciones importantes. La lógica visual del cliente intenta responder rápido al jugador, pero los valores definitivos deben venir del backend.

Aspectos importantes:

- validación de acciones en servidor;
- control de sesión;
- endpoints PHP para acciones críticas;
- sistema de lotes/packs para reducir peticiones;
- sincronización visual mediante JavaScript;
- persistencia en base de datos.

---

## Roadmap

Ideas y mejoras futuras:

- Completar la sección `Engranajes`.
- Añadir nuevas runas corruptas.
- Mejorar animaciones especiales de rarezas altas.
- Añadir más eventos de desbloqueo.
- Mejorar el sistema de logros.
- Ampliar estadísticas del jugador.
- Mejorar el panel de administración.
- Añadir más herramientas de mantenimiento.
- Pulir el rendimiento móvil.
- Mejorar la experiencia de colección en pantallas pequeñas.

---

## Historial de versiones

### 0.3V

- Nueva sección Engranajes.
- Mejoras en móvil y tablet.
- Rediseño de colección móvil.
- Canvas de runas más grande.
- Corrección de bonus normal/corrupto.
- Optimización de intro.
- Toggle para desactivar animación de entrada.
- Mejoras en Ajustes.
- Mejoras en panel admin.
- Solicitudes para ser admin desde el index.
- Mejoras en mensajes del administrador.
- Limpieza visual y correcciones generales.

### 0.2V

- Mejoras en sistema de suerte.
- Ajustes de economía.
- Mejoras en tienda.
- Cambios en colección.
- Correcciones de sincronización.

### 0.1V

- Base inicial del juego.
- Login y registro.
- Tirada básica de runas.
- Sistema inicial de rarezas.
- Interfaz principal.
- Primeras animaciones.

---

## Autor

Proyecto desarrollado por:

**JONDRAR**

Diseño, programación, estructura, interfaz, lógica de juego y administración.

---

## Contacto y errores

Si encuentras un fallo técnico, puedes abrir una incidencia en GitHub o usar el sistema de mensajes integrado en el juego online.
