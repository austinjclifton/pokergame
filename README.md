# pokergame

A 1v1 poker simulation game built with application security as its main objective.

## Quick Start

**Frontend:**
```bash
cd frontend
npm run dev
```

**Backend API:**
```bash
php -S localhost:8000 -t backend/public
```

**WebSocket Server:**
```bash
php backend/ws/server.php
```

## Local Development

The database configuration defaults to the RIT Apache VM settings (`root`/`student`). For local development, you can override these with environment variables:

```bash
# Example: Local MySQL with root user and no password
DB_USER=root DB_PASS= php backend/ws/server.php

# Or set them in your shell session
export DB_USER=root
export DB_PASS=
php backend/ws/server.php
```

Available environment variables:
- `DB_HOST` (default: `127.0.0.1`)
- `DB_NAME` (default: `pokergame`)
- `DB_USER` (default: `root`)
- `DB_PASS` (default: `student`)
