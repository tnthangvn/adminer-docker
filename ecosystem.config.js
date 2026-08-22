// PM2 process definitions for the RongViet backend (API + worker).
//
// The Node source/build lives in ../rongviet-backend; this file only orchestrates
// the two long-running processes. TLS/`wss` is terminated by a reverse proxy in
// front of the API (nginx/Caddy → http://127.0.0.1:3002), so no HTTPS config is
// needed here and no backend code change is required — `trust proxy` is already
// set in src/index.ts.
//
// Prerequisites (run inside ../rongviet-backend):
//   npm ci
//   npm run build            # produces dist/index.js (API)
//   npm run build:worker     # produces dist-worker/worker/worker.js (worker)
//   npm run db:migrate       # once, if the schema is not up to date
//
// Usage (from this directory):
//   pm2 start ecosystem.config.js
//   pm2 logs / pm2 status / pm2 restart ecosystem.config.js
//   pm2 save && pm2 startup   # persist across reboots

const path = require('node:path')

// Absolute path to the Node backend repo. Its .env is loaded via dotenv, so the
// process cwd MUST be this directory.
const BACKEND_DIR = path.resolve(__dirname, '../rongviet-backend')
const LOG_DIR = path.join(__dirname, 'logs')

// Shared runtime env. The backend reads the rest of its config from
// rongviet-backend/.env (PORT=3002, DB, Redis, Centrifugo, …). TZ=UTC mirrors the
// package.json `start` / `start:worker` scripts.
const sharedEnv = {
  TZ: 'UTC',
  NODE_ENV: 'development',
  REDIS_URL: 'redis://:7e69cb0a22bd944106d23c363ad3b43aecf58058c6b9d606@rong-viet-redis:6379/0',
}

module.exports = {
  apps: [
    {
      name: 'rongviet-api',
      cwd: BACKEND_DIR,
      script: 'dist/index.js',
      // fork (not cluster): Socket.IO keeps per-process in-memory state; running
      // multiple unsticky instances would break realtime without a Redis adapter.
      exec_mode: 'fork',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '1G',
      kill_timeout: 10000,
      time: true,
      merge_logs: true,
      env: sharedEnv,
      error_file: path.join(LOG_DIR, 'api.error.log'),
      out_file: path.join(LOG_DIR, 'api.out.log'),
    },
    {
      name: 'rongviet-worker',
      cwd: BACKEND_DIR,
      script: 'dist-worker/worker/worker.js',
      exec_mode: 'fork',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '1G',
      kill_timeout: 10000,
      time: true,
      merge_logs: true,
      env: sharedEnv,
      error_file: path.join(LOG_DIR, 'worker.error.log'),
      out_file: path.join(LOG_DIR, 'worker.out.log'),
    },
  ],
}
