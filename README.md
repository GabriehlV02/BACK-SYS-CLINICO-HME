# Sistema Clínico HME · API

API del Sistema Clínico HME, desarrollada con Laravel. Proporciona autenticación y servicios clínicos para el [frontend del Sistema Clínico HME](https://github.com/GabriehlV02/FRONT-SYS-CLINICO-HME), además de puntos de integración con el sistema contable.

[![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![CI](https://github.com/GabriehlV02/BACK-SYS-CLINICO-HME/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/GabriehlV02/BACK-SYS-CLINICO-HME/actions/workflows/ci.yml)

## Responsabilidades

- Inicio de sesión, sesión compartida y recuperación de acceso.
- Servicios de pacientes, citas, profesionales, diagnósticos y recepción.
- Flujos de laboratorio, signos vitales, farmacia, emergencias e internación.
- Validación y persistencia de solicitudes mediante Laravel y Eloquent.
- Integración autenticada con el sistema contable para datos compartidos.

Las rutas HTTP se definen en `routes/api.php`. La autorización, las validaciones y la persistencia se implementan en `app/` y `database/`.

## Requisitos

- PHP 8.3 o posterior y Composer.
- Base de datos configurada en `.env` (la plantilla utiliza SQLite).
- Node.js y npm únicamente si vas a compilar los recursos incluidos en Laravel.
- Servicios externos opcionales, como Orthanc, según los módulos que vayas a utilizar.

## Instalación local

Desde la raíz del repositorio:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve --host 127.0.0.1 --port 5005
```

La API quedará disponible en `http://127.0.0.1:5005`. Si cambias el puerto, actualiza `VITE_API_TARGET` en el frontend clínico.

Configura en `.env` los valores de base de datos, integración contable y servicios externos que utilice tu entorno. Mantén las credenciales fuera de Git y usa `APP_DEBUG=false` en entornos publicados.

## Comandos útiles

```powershell
php artisan route:list --path=api
php artisan migrate:status
php artisan test
```

## Estructura

```text
app/          # dominio, flujos, controladores y servicios
routes/       # rutas HTTP
database/     # migraciones, factories y seeders
tests/        # pruebas automatizadas
config/       # configuración de Laravel
```

## Seguridad

No publiques `.env`, tokens de integración, claves de servicios ni datos personales o clínicos. Antes de compartir registros de errores, elimina identificadores y contenido sensible.
