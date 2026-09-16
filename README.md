# BACK-SYS-CLINICO-HME

Backend del sistema clinico HME.

## Requisitos

- Node.js 20+
- pnpm 9+

## Instalacion

```powershell
pnpm install
Copy-Item .env.example .env
pnpm dev
```

La API escucha por defecto en `http://localhost:5005`.

## Scripts

```powershell
pnpm dev
pnpm build
pnpm typecheck
pnpm lint
```

## Variables de entorno

- `PORT`: puerto HTTP del backend.
- `AUTH_SECRET`: secreto para firmar sesiones. En produccion debe tener al menos 32 caracteres y no debe subirse al repo.
- `ADMIN_PASSWORD`: clave inicial del administrador.
- `CORS_ORIGINS`: origenes permitidos para el frontend, separados por coma.
- `TRUST_PROXY`: usar `1` cuando el backend este detras de proxy confiable.

## Datos locales

El backend guarda datos locales en `data/db.json`. Ese archivo puede existir en desarrollo, pero no debe contener datos reales ni credenciales.
