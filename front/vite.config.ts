import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    // Le frontend reste accessible sur son port Vite, tandis que /api est
    // relayé vers Laravel. Cela évite les erreurs CORS et les URLs localhost
    // codées en dur dans le navigateur.
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
      },
    },
  },
});
