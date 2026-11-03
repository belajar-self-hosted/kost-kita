# KostKita

KostKita is a Laravel and Inertia application for kost owners. Phase 1 establishes owner and Super Admin accounts, tenant isolation, audit records, and property management. Tenant and staff application access is not part of the product.

## Local setup

Requirements: PHP 8.3+, Composer, Node.js, and npm.

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run dev
```

In another terminal, run `php artisan serve` to serve the application locally. Local development uses SQLite. Verification email is written to the local log mailer unless mail is configured.

Owners can register through the public registration page. Create the initial Super Admin from a trusted shell with:

```sh
php artisan app:create-super-admin
```

The command asks for the account name, email, and password interactively. Do not place the password in command arguments or source files.

## Production data store

Production uses PostgreSQL. Configure its connection through deployment secrets; do not put credentials in `.env.example` or commit a real `.env` file. The Proxmox and Docker deployment source of truth is [docs/deployment/HOMELAB-SSOT.md](docs/deployment/HOMELAB-SSOT.md), with application decisions indexed in [docs/README.md](docs/README.md).
