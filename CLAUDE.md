# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

NIJAC is a PHP/MySQL web app for managing and nominating table-tennis referees (Juges-Arbitres, JA) for the Normandy League. Stack: PHP 8.1+, MySQL/MariaDB, Bootstrap 5, jQuery 3, no front-end build step.

## Documentation

| File | Content |
|------|---------|
| `README.md` | Architecture, installation, roles, security overview |
| `Ecrans.md` | All screens: number, title, file, feature summary |
| `SPECIFICATION.md` | Detailed specifications per screen (fields, AJAX actions, business rules) |

## Running locally

No build step. Requires WAMP (Apache + MySQL on port 3307).

```bash
composer install   # install PhpSpreadsheet + PHPMailer
```

Access via `http://localhost/NIJAC/`. The DB schema and all migrations run automatically on first page load via `config/app_config.php → initTableConfiguration()`.

No test suite exists in this project.

## Shared JS utilities

| Fichier | Chargé par | Fonctions |
|---------|-----------|-----------|
| `asset/js/nijac-csrf.js` | Chaque page | Préfiltre jQuery AJAX — injecte `X-CSRF-Token` |
| `asset/js/nijac-toast.js` | `includes/footer.php` | `nijacToast(msg, type, duration)` · `nijacConfirm(msg, onConfirm, onCancel)` — remplaçants de `alert()` / `confirm()` |

`nijacToast` types : `'success'` (vert) · `'danger'` (rouge) · `'warning'` (orange) · `'info'` (bleu).

`nijacConfirm(msg, onConfirm, onCancel, opts)` ouvre une modale Bootstrap centrée. `opts` : `{ type: 'question'|'danger'|'warning', title, confirmLabel, cancelLabel }`. Les suppressions passent `{type:'danger'}` (header rouge, bouton "Supprimer", icône poubelle).

## Screen numbering

Every screen has a code `EXXXX` defined in `$pageCode` before the `page_header.php` include. The code appears in the page header and as a small badge (top-right) on every menu button. When creating a new screen, assign the next available code and add it to `Ecrans.md` and `SPECIFICATION.md`.

| Range | Domain |
|-------|--------|
| E001–E019 | Admin / paramétrage |
| E020–E029 | Nominateur |
| E099 | Administration BDD (accès restreint) |

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
| `Classes/SecurePasswordHasher.php` | bcrypt wrapper — use `::hash($plain)` and `::verify($plain, $hash)` |
| `Classes/Distance.php` | GPS distance calculation between two lat/lon points |
| `includes/page_header.php` | Mutualized page header — set `$pageIcon`, `$pageTitle`, `$pageCode`, `$backUrl` before include; optional `$pageIconClass`, `$backBtnClass` |
| `includes/admin_required.php` | Redirects to `index.php` if not authenticated or not admin |
| `includes/auth_required.php` | Redirects to `$authRedirect` if not authenticated (any role) |
| `db-admin.php` | BDD admin interface (E099) — access restricted to `$_SESSION['utilisateur']['login'] === 'CHAUTARD'` |

### Page header usage

```php
$pageIcon  = 'bi-gear-fill';          // Bootstrap Icon class (without 'bi ')
$pageTitle = 'Configuration générale';
$pageCode  = 'E015';                  // Screen code — displayed in header and menu badges
$backUrl   = 'admin_menu.php';        // null for menu pages (no back button)
require __DIR__ . '/includes/page_header.php';
```

### AJAX pattern

All AJAX endpoints return `['ok' => true/false, ...]`. The JS side always checks `r.ok`. POST actions must call `csrfVerify(true)` before doing anything. The CSRF token is injected by `asset/js/nijac-csrf.js` as a jQuery AJAX prefilter (`X-CSRF-Token` header).

### Session structure

```php
$_SESSION['utilisateur'] = [
    'id'             => int,       // Id_Utilisateur
    'login'          => string,
    'nom'            => string,
    'prenom'         => string,
    'role'           => 'Administrateur' | 'Nominateur',
    'is_admin'       => bool,
    'id_departement' => string,    // e.g. '76'
    'change_login'   => bool,      // forces password change on next login
];
```

### Access control convention

- Admin-only pages: use `require __DIR__ . '/includes/admin_required.php'`
- Nominateur + admin pages: set `$authRedirect = '../index.php'` then `require __DIR__ . '/../includes/auth_required.php'`
- Admin-only AJAX actions within a shared page: checked individually with `in_array($action, $actionsAdmin) && !$isAdmin`
- E018 et E099 extra restriction: `$_SESSION['utilisateur']['login'] === 'CHAUTARD'`

### Database conventions

- Auto-column-add pattern: pages check `DESCRIBE ja` / `SHOW COLUMNS FROM` at request time and issue `ALTER TABLE ADD COLUMN IF NOT EXISTS` before using new columns. No migration files exist — everything is in `app_config.php` or inline.
- `laposte` table is the INSEE commune reference (CodePostal, Nom, GPS). JA rows link to it via `Id_LaPoste`; `Cp` and `Ville` columns on `ja` are fallback denormalized copies.
- Department filtering rule for Seine-Maritime (76): automatically includes Eure (27), configured in `regles_departements` JSON in `configuration` table.
- Always call `getDepartementsAutorises($id_departement)` to resolve the full list of departments for a given user — never hardcode department rules.

### Email mode

`etat_logiciel` config key controls routing: `Developpement` → all emails redirect to `email_developpement` address. Always call `getEmailDestinataire($email)` before sending, never use raw addresses. Use `getNijacMailer()` from `app_config.php` to get a pre-configured PHPMailer instance.

### Obfuscator usage

```php
require_once __DIR__ . '/../Classes/Obfuscator.php';
$o = new Obfuscator(OBFUSCATOR_SEED);
$token = $o->obfuscate($idJa);    // URL-safe 8-char string
$idJa  = $o->deobfuscate($token); // returns -1 if invalid
```

Requires PHP `bcmath` extension. Used to expose JA IDs in public convocation URLs without leaking real PKs.

### Menu button convention

Both menu pages (`admin_menu.php` E002 and `Nominateur/menu.php` E020) display a small screen code badge (top-right, `.btn-code` CSS class, `position: absolute`) inside each `.menu-btn`. When adding a new button to a menu, always include the `<span class="btn-code">EXXXX</span>` as the first child of the `<a>` element.

```html
<a href="mypage.php" class="menu-btn btn-mycolor">
    <span class="btn-code">E030</span>
    <div class="btn-icon"><img src="img/myicon.png" alt="..."></div>
    <span>Titre du bouton</span>
    <span class="btn-desc">Description courte</span>
</a>
```

Discution en Français
