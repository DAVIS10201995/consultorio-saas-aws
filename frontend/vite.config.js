import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// En local, /api se reenvía al contenedor de la API (mismo origen, sin CORS).
// En AWS, CloudFront hace lo mismo: /* va a S3 y /api/* a la EC2.
export default defineConfig({
  plugins: [react()],
  server: {
    host: true,
    port: 5173,
    proxy: { '/api': process.env.API_PROXY || 'http://localhost:8080' },
  },
  build: { outDir: 'dist', sourcemap: false },
})
