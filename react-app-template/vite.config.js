import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Módulos que solo usan las vistas que se cargan bajo demanda (React.lazy en App.jsx).
const LAZY_ONLY = [
  '/src/components/TripsList.jsx',
  '/src/components/trip/',
  '/src/components/InboxView.jsx',
  '/src/components/ChatThread.jsx',
  '/src/components/MulligansView.jsx',
  '/src/components/ProfileView.jsx',
  '/src/components/SecurityView.jsx',
  '/src/components/ProposalsView.jsx',
  '/src/components/RequestDialog.jsx',
  '/src/components/EmailVerifyNotice.jsx',
  '/src/components/Field.jsx',
  '/src/components/BadgeLabel.jsx',
  '/src/lib/statusBadges.js',
];

export default defineConfig({
  plugins: [react()],
  // Rutas relativas: los trozos se resuelven desde la URL del propio portal-app.js,
  // esté el plugin donde esté. Requiere cargarlo como <script type="module"> (ver PHP).
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'portal-app.js',
        // Con hash: portal-app.js se versiona con ?ver=filemtime y los trozos por contenido.
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: 'portal-app.[ext]',
        // Todo lo compartido (React incluido) va a chunks/core: así los trozos nunca importan
        // portal-app.js, que WordPress carga con ?ver= (otra URL = React duplicado).
        manualChunks(id) {
          const path = id.split('\\').join('/');
          if (path.endsWith('/src/main.jsx')) return undefined;
          if (path.includes('/node_modules/')) return 'core';
          if (path.includes('/src/') && !LAZY_ONLY.some((lazy) => path.includes(lazy))) return 'core';
          return undefined;
        },
      },
    },
  },
});
