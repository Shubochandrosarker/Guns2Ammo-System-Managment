import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/wp-json': {
        target: process.env.G2A_WP_URL || 'https://guns2ammo.com',
        changeOrigin: true,
        secure: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    // Never publish production sourcemaps — they hand attackers the exact
    // auth implementation (auth plan risk R6 / decision D7).
    sourcemap: false,
    target: 'es2020',
    rollupOptions: {
      output: {
        // Pages are already route-split, but everything they share landed in
        // one ~595KB entry chunk that changes on every application edit —
        // so each deploy re-downloaded React and Recharts too. Splitting the
        // two big third-party trees out lets them stay in cache across
        // deploys, which is what actually matters on shop wifi.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          charts: ['recharts'],
        },
      },
    },
  },
})
