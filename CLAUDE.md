# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

NIJAC is a PHP/MySQL web app for managing and nominating table-tennis referees (Juges-Arbitres, JA) for the Normandy League. Stack: PHP 8.1+, MySQL/MariaDB, Bootstrap 5, jQuery 3, no front-end build step.

## Running locally

No build step. Requires WAMP (Apache + MySQL on port 3307).

```bash
composer install   # install PhpSpreadsheet + PHPMailer
```

Access via `http://localhost/NIJAC/`. The DB schema and all migrations run automatically on first page load via `config/app_config.php → initTableConfiguration()`.

No test suite exists in this project.

## Architecture

### Request lifecycle

Every PHP page is self-contained and dual-mode: it handles AJAX actions at the top (checking `$action = $_POST['action'] ?? $_GET['action'] ?? ''`) and falls through to HTML rendering at the bottom. There is no router, framework, or front controller.

### Key shared files

| File | Role |
|---|---|
| `config/db.php` | PDO singleton (`getPDO()`), env detection (WAMP vs production via `NIJAC_ENV` or `.env.production`) |
| `config/csrf.php` | `csrfToken()`, `csrfField()`, `csrfVerify(bool $json)` — every POST endpoint must call `csrfVerify(true)` |
| `config/app_config.php` | DB migrations, all config helpers (`getConfig()`, `getDeptActifs()`, `getDepartementsAutorises()`), PHPMailer factory (`getNijacMailer()`), rate-limit helpers |
| `Classes/Obfuscator.php` | Bi-directional integer ↔ 8-char token (bcmath + Knuth multiplicative hash, seed = `OBFUSCATOR_SEED = 167`) — used to expose JA IDs in public URLs without leaking real PKs |
| `includes/page_header.php` | Mutualized page header component — requires `$pageIcon`, `$pageTitle`, `$pageCode`, `$backUrl` to be set before include |

### AJAX pattern

All AJAX endpoints return `['ok' => true/false, ...]`. The JS side always checks `r.ok`. POST actions must call `csrfVerify(true)` before doing anything. The CSRF token is injected by `asset/js/nijac-csrf.js` as a jQuery AJAX prefilter (`X-CSRF-Token` header).

### Session structure

```php
$_SESSION['utilisateur'] = [
    'id_utilisateur' => int,
    'nom'            => string,
    'prenom'         => string,
    'role'           => 'Administrateur' | 'Nominateur',
    'is_admin'       => bool,
    'id_departement' => string,   // e.g. '76'
    'change_login'   => bool,     // forces password change on next login
];
```

### Access control convention

- Admin-only pages: `if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin']))`
- Nominateur + admin pages: `if (!isset($_SESSION['utilisateur']))`
- Admin-only AJAX actions within a shared page: checked individually with `in_array($action, $actionsAdmin) && !$isAdmin`

### Database conventions

- Auto-column-add pattern: pages check `DESCRIBE ja` / `SHOW COLUMNS FROM` at request time and issue `ALTER TABLE ADD COLUMN IF NOT EXISTS` before using new columns. No migration files exist — everything is in `app_config.php` or inline.
- `laposte` table is the INSEE commune reference (CodePostal, Nom, GPS). JA rows link to it via `Id_LaPoste`; `Cp` and `Ville` columns on `ja` are fallback denormalized copies.
- Department filtering rule for Seine-Maritime (76): automatically includes Eure (27), configured in `regles_departements` JSON in `configuration` table.

### Email mode

`etat_logiciel` config key controls routing: `Developpement` → all emails redirect to `email_developpement` address. Always call `getEmailDestinataire($email)` before sending, never use raw addresses.

### Obfuscator usage

```php
require_once __DIR__ . '/../Classes/Obfuscator.php';
$o = new Obfuscator(OBFUSCATOR_SEED);
$token = $o->obfuscate($idJa);   // URL-safe 8-char string
$idJa  = $o->deobfuscate($token); // returns -1 if invalid
```

Requires PHP `bcmath` extension.
