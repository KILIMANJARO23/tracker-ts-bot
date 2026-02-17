import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  // для прод-сборки, которая будет лежать под /app/
  base: "/app/",
  server: {
    port: 5173,
    host: true,
    // разрешаем доступ с домена ngrok (иначе 403 Forbidden)
    allowedHosts: true,
    proxy: {
      "/api": "http://localhost:3000",
    },
  },
});

