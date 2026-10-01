# Development-only test stack (not deployed)

    docker build -t hrms-php82 hrms-php/dev
    docker run -d --name hrms-web -p 8082:80 --add-host=host.docker.internal:host-gateway \
      -v "$PWD/hrms-php:/home/app" hrms-php82

The app reads `hrms-php/.env` (create it from `.env.example`, pointing DB_HOST at
`host.docker.internal` and the test MariaDB container's port).
