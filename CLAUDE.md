# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

NIJAC is a PHP/MySQL web app for managing and nominating table-tennis referees (Juges-Arbitres, JA) for the Normandy League. Stack: PHP 8.2+, CodeIgniter 4, MySQL/MariaDB, Bootstrap 5, jQuery 3, no front-end build step.

The application is entirely implemented in `ci4/` (CodeIgniter 4). There is no more legacy stand-alone-PHP-page app — it was fully replaced by the CI4 port (see `git log` around "Version avec Ci4"). The repository root still holds shared, framework-agnostic pieces the CI4 app depends on: `config/` (`db.php`, `app_config.php`, `helpers.php`), `Classes/` (`Obfuscator.php`, `SecurePasswordHasher.php`, `Distance.php`), and `asset/` (CSS/JS served straight from the root, not from `ci4/public/`).

### FFTT API client

All FFTT calls go through the composer package `alamirault/fftt-api` (ci4/composer.json — pulls in Guzzle 6.x, an EOL branch with known CVEs; see `config.audit.ignore` in that file). Two entry points, both authenticated with `getFfttAppId()`/`getFfttAppKey()` (config/db.php, ROT47-decoded from `.env`):

- **High-level facade** — `new \Alamirault\FFTTApi\Service\FFTTApi(getFfttAppId(), getFfttAppKey())`, instantiated directly at each call site (no shared factory). Covers clubs, joueurs, organismes, épreuves, équipes, classement, actualités.
- **Low-level raw client** — `getFfttRawClient()` (config/app_config.php) returns `App\Libraries\FfttRawClient`, for endpoints the facade doesn't expose: `xml_division`, `xml_result_equ` with the `cx_poule` mechanic, `xml_poule` (endpoint doesn't actually exist server-side), `xml_chp_renc`, `xml_licence`, and any raw field the typed facade models drop (e.g. `xml_club_detail` returning multiple salles for one club, or `xml_licence_b` fields — email/Cp/Ville — the facade's `JoueurDetails` doesn't expose). Built on the library's own `UriGenerator` (same auth scheme as the facade) + Guzzle directly, so it stays a thin wrapper rather than reimplementing auth.

`Classes/FfttApi.php` (raw cURL, separate `serie`/app-key signing scheme) has been removed — every controller that used it (`ClubController`, `SalleController`, `JugearbitreController`, `ImportRencontresController`, `ImportRencontresNatController`, `FfttTestController` / E018) now uses one or both of the above.

## Documentation

| File | Content |
|------|---------|
| `README.md` | Architecture, installation, roles, security overview |
| `Ecrans.md` | All screens: number, title, file, feature summary |
| `SPECIFICATION.md` | Detailed specifications per screen (fields, AJAX actions, business rules) — some entries are known to be stale versus the CI4 port (see inline `Routes.php` comments) |

## Running locally

No build step. Requires WAMP (Apache + MySQL on port 3307).

```bash
composer install          # root: PhpSpreadsheet + PHPMailer, used by config/app_config.php
cd ci4 && composer install # ci4/: CodeIgniter 4 framework itself
```

Access via `http://localhost/NIJAC/`. The root `.htaccess` transparently forwards everything to `ci4/public/index.php` (the CI4 front controller) except real files/folders (`asset/`, `img/`, `SQL/`, `Importation/`, `logs/`, `ci4/` itself), which are served as-is. No `RewriteBase` is hard-coded, so the same `.htaccess` works whether this folder is a vhost docroot (WAMP) or a subfolder (production).

The DB schema migrations run via `config/app_config.php → initTableConfiguration()`, no CI4 migration files are used. Only `DbAdminController` (E099) calls it, so a new column/table gets created the first time an admin loads the E099 screen after deploying a change — not automatically on every page load like before. Several controllers also run their own inline `DESCRIBE`/`ALTER TABLE ADD COLUMN` checks unrelated to `initTableConfiguration()` (e.g. `DesiderataClubController` for `salle.Cp`) — those still self-heal on their own page load regardless.

No test suite exists for the application itself (`ci4/tests/` is the untouched CodeIgniter starter scaffold).

## Shared JS utilities

Served from the root `asset/` folder via `base_url('asset/js/...')` — not duplicated under `ci4/public/`.

| Fichier | Chargé par | Fonctions |
|---------|-----------|-----------|
| `asset/js/nijac-csrf.js` | Chaque vue (`<script src="<?= base_url('asset/js/nijac-csrf.js') ?>">`) | Préfiltre jQuery AJAX — lit `<meta name="csrf-token">` et injecte le header `X-CSRF-Token` |
| `asset/js/nijac-toast.js` | Chaque vue | `nijacToast(msg, type, duration)` · `nijacConfirm(msg, onConfirm, onCancel)` — remplaçants de `alert()` / `confirm()` |
| `asset/js/nijac-sortable-table.js` | Vues avec tableaux triables | Tri de colonnes côté client |

`nijacToast` types : `'success'` (vert) · `'danger'` (rouge) · `'warning'` (orange) · `'info'` (bleu).

`nijacConfirm(msg, onConfirm, onCancel, opts)` ouvre une modale Bootstrap centrée. `opts` : `{ type: 'question'|'danger'|'warning', title, confirmLabel, cancelLabel }`. Les suppressions passent `{type:'danger'}` (header rouge, bouton "Supprimer", icône poubelle).

## Screen numbering

Every screen has a code `EXXXX`. It's hard-coded directly into each view's `<title>`/header markup (no shared header partial — see "View / header convention" below) and referenced in the matching `Routes.php` comment block. When creating a new screen, assign the next available code and add it to `Ecrans.md` and `SPECIFICATION.md`.

| Range | Domain |
|-------|--------|
| E001–E019 | Admin / paramétrage |
| E020–E029 | Nominateur |
| E030–E033 | JA / public (fiche JA, convocation, disponibilité, changement mdp) |
| E099 | Administration BDD (accès restreint) |

## Architecture

### Request lifecycle

Standard CodeIgniter 4 MVC: `ci4/app/Config/Routes.php` maps each URL to a `Controller::method`. Every controller extends `BaseController` and requires the legacy shared config files itself in its constructor (see below) — there's no bootstrap/autoloaded global include. Most screens are still dual-mode in spirit: one `index()` action renders the HTML view, and sibling actions (`data`, `store`, `update`, `delete`, ...) serve AJAX, mirroring the old "one file, `$action` dispatch" pattern but split into real controller methods and real routes instead of a single `$_POST['action']` switch.

### Key shared files (root, outside `ci4/`)

| File | Role |
|---|---|
| `config/db.php` | PDO singleton (`getPDO()`), env detection (WAMP vs production via `NIJAC_ENV` or `.env.production`), `rot47()` decoding of DB/SMTP/FFTT secrets from `.env` |
| `config/app_config.php` | DB migrations (`initTableConfiguration()`), all config helpers (`getConfig()`, `getDeptActifs()`, `getDepartementsAutorises()`), PHPMailer factory (`getNijacMailer()`), rate-limit helpers |
| `config/helpers.php` | Misc shared helpers used by a handful of controllers (adresse/commune/salle lookups) |
| `Classes/Obfuscator.php` | Bi-directional integer ↔ 8-char token (bcmath + Knuth multiplicative hash, seed = `OBFUSCATOR_SEED = 167`) — used to expose JA IDs in public URLs without leaking real PKs |
| `Classes/SecurePasswordHasher.php` | bcrypt wrapper — use `::hash($plain)` and `::verify($plain, $hash)` |
| `Classes/Distance.php` | GPS distance calculation between two lat/lon points |
| `tools/rot47.php` | Standalone CLI (`php tools/rot47.php valeur`) to pre-compute a ROT47-encoded value to paste into `.env` — not called by the app itself; the `rot47()` function it demonstrates is duplicated inside `config/db.php`, which the app does use |

Every CI4 controller pulls in `config/db.php` (and most also `config/app_config.php`, some also `config/helpers.php`) via `require_once __DIR__ . '/../../../config/xxx.php';` in its constructor — that relative path is `ci4/app/Controllers/` → repo root.

### Key CI4 files

| File | Role |
|---|---|
| `ci4/app/Config/Routes.php` | All routes; each screen's block is commented with its `EXXXX` code and access rule |
| `ci4/app/Config/Filters.php` | Filter aliases (`auth`, `adminauth`, `csrf`, ...) and globals — `csrf` filter is applied globally to all POST/PUT/PATCH/DELETE |
| `ci4/app/Config/Security.php` | CSRF config — `csrfProtection = 'cookie'` (double-submit, no CI4 session dependency), `regenerate = false` (stable token per session, screens fire multiple sequential AJAX POSTs), header name `X-CSRF-Token` |
| `ci4/app/Config/Database.php` | CI4 DB config — does **not** define its own credentials; `require_once`s the root `config/db.php` and copies `DB_HOST`/`DB_USER`/`DB_PASS`/`DB_NAME`/`DB_CHARSET`/`DB_PORT` into `$default` (single source of truth for DB config stays in the legacy file) |
| `ci4/app/Filters/AdminAuth.php` | Redirects to `login` unless `$_SESSION['utilisateur']['is_admin']` is true. Starts the session with **native** `session_start()`, not CI4's Session service — CI4's `FileHandler` names session files `PHPSESSID<id>` (vs PHP's own `sess_<id>`), so using CI4's service would silently stop sharing sessions with anything reading the raw `$_SESSION` global the same way the old app did |
| `ci4/app/Filters/Auth.php` | Redirects to `login` unless authenticated (any role); redirects role `JA` to `info-rencontre` (its only allowed screen) |
| `ci4/app/Controllers/BaseController.php` | Empty CI4 starter base — no shared NIJAC logic lives here (that's why every controller requires the config files itself) |

### View / header convention

There is no shared header/layout partial (no equivalent of the old `includes/page_header.php`). Each view file (`ci4/app/Views/xxx_index.php`) is a full standalone HTML document that inlines its own `<head>`, its own `#page-header` markup (title, `EXXXX` badge, back link via `site_url(...)`), and its own `<style>` block. New screens should copy the header block from an existing view (e.g. [region_index.php](ci4/app/Views/region_index.php)) rather than trying to factor it out.

The CSRF token is exposed via `<meta name="csrf-token" content="<?= csrf_hash() ?>">` in every view's `<head>`, and `asset/js/nijac-csrf.js` reads that meta tag to inject the `X-CSRF-Token` header on jQuery AJAX calls.

### AJAX pattern

All AJAX endpoints return `['ok' => true/false, ...]` as JSON (`$this->response->setJSON(...)`). The JS side always checks `r.ok`. CSRF verification is handled globally by CI4's `csrf` filter (see `Config\Filters`) — controllers don't call anything themselves, unlike the old per-endpoint `csrfVerify(true)`.

### Session structure

Still plain PHP session (native `session_start()`/`$_SESSION`, not CI4's Session service — see `AdminAuth.php` above):

```php
$_SESSION['utilisateur'] = [
    'id'             => int,       // Id_Utilisateur
    'login'          => string,
    'nom'            => string,
    'prenom'         => string,
    'role'           => 'Administrateur' | 'Nominateur' | 'JA',
    'is_admin'       => bool,
    'id_departement' => string,    // e.g. '76'
    'change_login'   => bool,      // forces password change on next login
    'email'          => string,    // Utilisateur.Email — Reply-To + Cc prefill in Centre d'envoi (E024), empty for JA role
];
```

### Access control convention

- Admin-only routes: `['filter' => 'adminauth']` in `Routes.php`
- Nominateur + admin routes: `['filter' => 'auth']` in `Routes.php` (also redirects role `JA` to its own screen)
- Admin-only AJAX actions within a shared controller: checked individually inside the method, same idea as before (e.g. `SalleController`/`JugearbitreController`/`MessagerieController` use route filter `auth` but gate specific write actions to admin in code)
- E018 (FfttTestController) and E099 (DbAdminController) extra restriction, checked manually in the controller: `$_SESSION['utilisateur']['login'] === 'CHAUTARD'`
- Public (tokenized or fully open) routes have no filter at all in `Routes.php` — e.g. E023 `desiderata-club`, E029 `adresse-ja` (index), E031 `convocation-ja`, E032 `disponibilite-ja`, E030 `info-rencontre` (session checked manually in-controller because both filters would redirect role `JA` away)

### Database conventions

- Auto-column-add pattern: controllers check `DESCRIBE ja` / `SHOW COLUMNS FROM` at request time and issue `ALTER TABLE ADD COLUMN IF NOT EXISTS` before using new columns. No CI4 migration files are used for this — everything is in `config/app_config.php` or inline in the controller.
- `laposte` table is the INSEE commune reference (CodePostal, Nom, GPS). JA rows link to it via `Id_LaPoste`; `Cp` and `Ville` columns on `ja` are fallback denormalized copies.
- Department filtering rule for Seine-Maritime (76): automatically includes Eure (27), configured in `regles_departements` JSON in the `configuration` table.
- Always call `getDepartementsAutorises($id_departement)` to resolve the full list of departments for a given user — never hardcode department rules.

### Email mode

`etat_logiciel` config key controls routing: `Developpement` → all emails redirect to the `email_developpement` address. Always call `getEmailDestinataire($email)` before sending, never use raw addresses. Use `getNijacMailer()` from `config/app_config.php` to get a pre-configured PHPMailer instance.

### Obfuscator usage

```php
require_once __DIR__ . '/../../../Classes/Obfuscator.php'; // from ci4/app/Controllers/
$o = new Obfuscator(OBFUSCATOR_SEED);
$token = $o->obfuscate($idJa);    // URL-safe 8-char string
$idJa  = $o->deobfuscate($token); // returns -1 if invalid
```

Requires PHP `bcmath` extension. Used to expose JA IDs in public convocation/disponibilité/adresse URLs without leaking real PKs.

### Menu button convention

Both menu views (`admin_menu_index.php` E002 and `nominateur_menu_index.php` E020) display a small screen code badge (top-right, `.btn-code` CSS class, `position: absolute`) inside each `.menu-btn`. Links now point to CI4 routes via `site_url(...)` instead of static filenames. When adding a new button to a menu, always include the `<span class="btn-code">EXXXX</span>` as the first child of the `<a>` element.

```html
<a href="<?= site_url('mypage') ?>" class="menu-btn btn-mycolor">
    <span class="btn-code">E030</span>
    <div class="btn-icon"><img src="<?= base_url('img/myicon.png') ?>" alt="..."></div>
    <span>Titre du bouton</span>
    <span class="btn-desc">Description courte</span>
</a>
```

Discution en Français
