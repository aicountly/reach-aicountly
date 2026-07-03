import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// cPanel production uses base '/'. GitHub Pages project sites use VITE_BASE=/repo-name/.
const base = process.env.VITE_BASE || '/';

// https://vitejs.dev/config/
export default defineConfig({
  base,
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: false,
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
  },
});
