# Portal de cliente (SPA React)

SPA del área de cliente de Casanova Golf, con el lenguaje visual **Casanova 2026**
(tokens `--cg-*`, Satoshi + Playfair Display, iconos Tabler).

## Compilar

```bash
npm run build:wp
```

Extrae los textos traducibles (`includes/generated/js-i18n-literals.php`), compila con Vite
en `dist/` y copia el resultado a `assets/` (copia de respaldo; el plugin sirve `dist/`).

Desarrollo con servidor de Vite: `npm run dev:wp`.

## Cómo se carga en WordPress

`casanova-portal-giav.php` encola `dist/portal-app.js` en las páginas con `[casanova_portal_app]`:

- Se carga como **`<script type="module">`**: la entrada es mínima e importa
  `dist/chunks/core-[hash].js` (React y todo lo compartido), que se precarga con
  `<link rel="modulepreload">`.
- Las vistas que no son el Inicio (Viajes, ficha, Mensajes, Mulligans, Perfil, Seguridad)
  van en trozos propios que se descargan al abrirlas o en segundo plano cuando el navegador
  está libre.
- **Nunca** hay que hacer que un trozo importe `portal-app.js`: WordPress lo sirve con
  `?ver=` y el navegador lo trataría como otro módulo (React duplicado). `vite.config.js`
  lo evita mandando todo lo compartido a `core` (`manualChunks`).
- En la página del portal se quitan los CSS de terceros que no usa
  (filtro `casanova_portal_app_dequeue_styles`).

**Al desplegar hay que subir `dist/` completo, incluida `dist/chunks/`.** Si un plugin de
caché u optimización combina o minifica JS, hay que excluir `react-app-template/dist/`
(no admite módulos ES).

## Estilos

Todos los CSS se importan en `src/main.jsx`, en este orden:

| Archivo | Contenido |
|---|---|
| `components.css` | Base: botones, tarjetas, chips, insignias, avisos, vacíos, skeletons, formularios, toast |
| `styles.css` | Tokens (`:root` → `--cg-*`), tablas, barra de progreso y utilidades |
| `home.css` | Inicio |
| `trips.css` | Listado de viajes y ficha del viaje |
| `payments.css` | Pestaña Pagos de la ficha |
| `account.css` | Mensajes, Mulligans, Perfil y Seguridad |
| `shell.css` | Estructura: barra lateral, barra superior, pestañas móviles y pie |

Reglas: solo tokens (nada de hexadecimales sueltos), un único acento cálido (arena),
iconos de `src/components/Icon.jsx` (Tabler), objetivos táctiles de 44 px como mínimo.

## Modo prueba

`?mock=1` (solo administradores) lee `includes/mock/dashboard.json`, `trip.json`
y `messages.json`.
