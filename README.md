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
- Angular staff and Player frontend
- phpMyAdmin (development only)
- Initial database schema
- Development-only Admin seed data
- Standalone HTTPS/WSS production deployment
- OpenSwoole heartbeat, graceful lifecycle, readiness and private runtime metrics
- Reproducible correctness/load harness and opt-in bounded profiling

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

A fresh production database intentionally contains no default administrator.
After starting the production stack, an operator with container-shell access
must run the documented one-time `bootstrap-initial-admin.php` command. The
operator supplies the identity and enters the password through hidden terminal
input; the resulting active Admin must change that password at first login.
Development seed credentials must never be reused in production.

The reproducible production-path performance harness is documented in
[load-testing/README.md](load-testing/README.md). On the recorded local Docker
Desktop environment, valid CLASSROOM and BURST runs completed with 500 Players
across 20 Sessions; this is environment-specific evidence, not a universal
production-capacity claim. The final verified fact sheet is
[docs/13-final-validation-and-evidence.md](docs/13-final-validation-and-evidence.md).
