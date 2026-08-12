# Deployment and environment

> Status: Implemented

## Docker services

| Service | Purpose | Host port | Mounts |
| --- | --- | ---: | --- |
| `backend` | PHP 8.3/OpenSwoole application | `9501` | `./backend:/var/www/backend` (including persistent `storage/question-images`) |
| `mysql` | MySQL 8.4 | `3307` → container `3306` | `./docker/mysql/data`, `./docker/mysql/init`, read-only host localtime |
| `phpmyadmin` | Local database administration | `8081` | None |

Compose contains local development credentials; deployment credentials must come from protected environment configuration. The repository does not contain a production reverse proxy.

## Backend startup

The backend container works in `/var/www/backend`. `server.php` sets PHP UTC, loads Composer, enables OpenSwoole coroutine runtime hooks, constructs `Application`, registers HTTP/WebSocket callbacks and starts port 9501. OpenSwoole remains one worker with coroutine callbacks enabled.

MySQL starts with UTF-8 and `--default-time-zone=+00:00`. Schema init files are mounted at `/docker-entrypoint-initdb.d`; existing data volumes are not automatically reinitialized. Every PDO connection also sets its session time zone to UTC.

## Environment categories

Use `backend/.env.example` as the variable reference; never commit real secrets.

- Application: `APP_NAME`, `APP_ENV`, `APP_URL`.
- Database: host, port, database, username, password.
- Staff auth: access/refresh/CSRF cookie names, refresh HMAC key, JWT secret/algorithm/lifetimes.
- Participant auth: separate participant secret and TTL.
- Login protection: attempt limit and lock duration.
- Cookies: path, Secure, HttpOnly and SameSite.
- Application limits/defaults: upload metadata settings, question default, nickname limit, pagination.

`QUESTION_IMAGE_STORAGE_PATH` selects the backend-controlled Question image
directory. Relative values resolve against the backend project root and default
to `storage/question-images`. The development bind mount persists that directory
across backend restarts; uploaded assets are ignored by Git. Deployments must
mount the configured directory on durable storage and route same-origin `/media`
requests to the OpenSwoole backend.

Secrets must be random and deployment-specific. Staff and participant JWT secrets require at least 32 characters; staff algorithm is HS256.

## Planned Angular development

The Angular development server should proxy `/api`, `/ws` and `/media` to
`localhost:9501`. This keeps browser requests effectively same-origin and avoids
adding CORS solely for development. With plain HTTP, configure non-`__Host`
cookie names and `COOKIE_SECURE=false` through local environment values.

Production is expected to use HTTPS, Secure cookies, `__Host-` cookie names and a same-origin frontend/backend layout or suitable reverse proxy. That infrastructure is an external deployment requirement, not an implemented repository service.
