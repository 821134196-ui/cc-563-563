import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// 前端 5173；/api 与 /up 代理到 Laravel（8765）
export default defineConfig({
  plugins: [react()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': 'http://127.0.0.1:8765',
      '/up': 'http://127.0.0.1:8765',
    },
  },
})
