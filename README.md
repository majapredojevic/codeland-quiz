# CodeLand Quiz

CodeLand Quiz is a real-time classroom platform that demonstrates how asynchronous PHP with OpenSwoole can be used to build scalable, low-latency educational applications using REST APIs and WebSockets.

## Technology Stack

- PHP 8.3
- OpenSwoole
- Angular
- MySQL
- Docker
- Composer
- Nginx (production edge)
- phpMyAdmin (development only)

## Current Status

The project currently includes:

- Docker development environment
- PHP/OpenSwoole backend container
- MySQL database container
- phpMyAdmin
- Initial database schema
- Admin seed data
- Standalone HTTPS/WSS production deployment

## Local Development

Start the project:

```bash
docker compose up -d
```

Run Angular development separately:

```bash
cd frontend
npm start
```

Production uses a separate three-service stack and never inherits development
ports or phpMyAdmin. See [docs/09-deployment.md](docs/09-deployment.md) before
creating `.env.production` or mounting a real TLS certificate.
