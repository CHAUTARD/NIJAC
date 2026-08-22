# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# Régles du projet
Commence chaque réponse par mon prénom.
Adapte-toi au style du code retour.
Les réponse toujours en Français.

## Project overview

NIJAC is a PHP/MySQL web app for managing and nominating table-tennis referees (Juges-Arbitres, JA) for the Normandy League. Stack: PHP 8.2+, CodeIgniter 4, MySQL/MariaDB, Bootstrap 5, jQuery 3, no front-end build step.

The application is entirely implemented in `ci4/` (CodeIgniter 4). There is no more legacy stand-alone-PHP-page app — it was fully replaced by the CI4 port (see `git log` around "Version avec Ci4"). The repository root still holds shared, framework-agnostic pieces the CI4 app depends on: `config/` (`db.php`, `app_config.php`, `helpers.php`), `Classes/` (`Obfuscator.php`, `SecurePasswordHasher.php`, `Distance.php`), and `asset/` (CSS/JS served straight from the root, not from `ci4/public/`).

### FFTT API client

All FFTT calls go through `App\Libraries\FfttRawClient` (`ci4/app/Libraries/FfttRawClient.php`), instantiated via `getFfttRawClient()` (config/app_config.php), authenticated with `getFfttAppId()`/`getFfttAppKey()` (config/db.php, ROT47-decoded from `.env`). Plain PHP cURL, no Composer runtime dependency — production only has FTP access (no Composer, no shell), so this was a deliberate choice: any future change to this feature deploys like any other PHP file, no `vendor/` sync step.

Two ways to call it:

- **7 typed convenience methods** — `listClubsByDepartement()`, `retrieveClubDetails()`, `listOrganismes()`, `listEpreuves()`, `listEquipesByClub()`, `retrieveJoueurDetails()`, `listJoueursByClub()` — cover every FFTT call actually used by production screens (E005, E007, E008, E011, E017). Each returns a plain associative array (or array of arrays) with the raw FFTT field names (e.g. `numero`, `nom`, `nomsalle`, `libequipe`) — no typed model objects.
- **`request(string $action, array $params = []): array`** — for any other raw endpoint: `xml_division`, `xml_result_equ` (poules/rencontres by division ID or `cx_poule`), `xml_chp_renc`, `xml_licence` (base SPID, distinct from `xml_licence_b`), or any field a convenience method doesn't surface (e.g. `xml_club_detail` returning multiple salles for one club — see `SalleController::ffttSync()`). `lastUrl()`/`lastHttp()`/`lastRaw()` expose the last request's details for diagnostics (see E018).

Previously used the composer package `alamirault/fftt-api` (typed facade + Guzzle 6.x, an EOL branch with known CVEs) and, before that, `Classes/FfttApi.php` (raw cURL, a different `serie`/app-key signing scheme) — both removed. E018 (FfttTestController, `testViaLibrary()`) only exercises the 7 production-used methods; it no longer covers the old facade's diagnostic-only surface (classement, historique, parties, Elo-style virtual points, regex-parsed rencontre details, actualités...), none of which backed a real screen.

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

Access via `http://nijac/` — a dedicated WAMP vhost whose `DocumentRoot` is this folder directly (see `httpd-vhosts.conf` and the Windows `hosts` file), matching `app.baseURL` in `ci4/.env` (`http://nijac/`). Do **not** use `http://localhost/NIJAC/` (default vhost, NIJAC as a subfolder): `Config\App::$baseURL` has no path segment, so every `site_url()`/`base_url()` link generated while browsing under that host is wrong (wrong host under the default vhost, or wrong path if the host is added to `allowedHostnames`) — CodeIgniter's `SiteURI` only swaps the host for allowed hostnames, never the base path, so the two access methods can't both work against a single static `baseURL`. In production the app is deployed under a path (`https://www.ligue-normandie-tt.fr/nijac/`), so `ci4/.env`'s `app.baseURL` there must include that path — that `.env` is a separate file edited directly on the server (FTP-only deploy, see below), not the one in this repo. The root `.htaccess` transparently forwards everything to `ci4/public/index.php` (the CI4 front controller) except real files/folders (`asset/`, `img/`, `SQL/`, `Importation/`, `logs/`, `ci4/` itself), which are served as-is. No `RewriteBase` is hard-coded, so the same `.htaccess` works whether this folder is a vhost docroot (WAMP) or a subfolder (production).

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

Every screen has a code `EXXX`. It's hard-coded directly into each view's `<title>`/header markup (no shared header partial — see "View / header convention" below) and referenced in the matching `Routes.php` comment block. When creating a new screen, assign the next available code and add it to `Ecrans.md` and `SPECIFICATION.md`.

| Range | Domain |
|-------|--------|
| E001–E019 | Admin / paramétrage |
| E020–E029 | Nominateur |
| E030–E033 | JA / public (fiche JA, convocation, disponibilité, changement mdp) |
| E034–E039 | CSR (Commission Sportive Régionale) |
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
| `ci4/app/Filters/Auth.php` | Redirects to `login` unless authenticated (Administrateur, Nominateur or CSR — there is no JA session, see Session structure below) |
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
    'role'           => 'Administrateur' | 'Nominateur' | 'CSR',
    'is_admin'       => bool,
    'id_departement' => string,    // e.g. '76'
    'change_login'   => bool,      // forces password change on next login
    'email'          => string,    // Utilisateur.Email — Reply-To + Cc prefill in Centre d'envoi (E024)
];
```

There is no `JA` role/session: a JA never logs in. All JA-facing screens (E029–E032) are public routes, identified by an Obfuscator token (`?ja=TOKEN`) in a link emailed to them — see `construireMarqueursMessage()` in `config/app_config.php` for how those links are built, and `InfoRencontreController::resolveContext()` (E030) for the reference implementation of "token, else Nominateur/Admin session, else redirect".

### Access control convention

- Admin-only routes: `['filter' => 'adminauth']` in `Routes.php`
- Nominateur + admin routes: `['filter' => 'auth']` in `Routes.php` (role `CSR` also passes this filter, though it has no menu link into these screens)
- CSR-only routes (E034, E035): `['filter' => 'csrauth']` — role `CSR` or `Administrateur` (see `CsrAuth.php`). E027 briefly moved to `csrauth`/the CSR menu (E034) when the CSR role was introduced, but moved back to `auth`/the Nominateur menu (E020).
- Admin-only AJAX actions within a shared controller: checked individually inside the method, same idea as before (e.g. `SalleController`/`JugearbitreController`/`MessagerieController` use route filter `auth` but gate specific write actions to admin in code)
- E018 (FfttTestController) and E099 (DbAdminController) extra restriction, checked manually in the controller: `$_SESSION['utilisateur']['login'] === 'CHAUTARD'`
- Public (tokenized or fully open) routes have no filter at all in `Routes.php` — e.g. E023 `desiderata-club`, E029 `adresse-ja`, E030 `info-rencontre`, E031 `convocation-ja`, E032 `disponibilite-ja` (all JA-facing screens; session checked manually in-controller only to let Nominateur/Admin reuse E030 from their own menu)

### Database conventions

- Auto-column-add pattern: controllers check `DESCRIBE ja` / `SHOW COLUMNS FROM` at request time and issue `ALTER TABLE ADD COLUMN IF NOT EXISTS` before using new columns. No CI4 migration files are used for this — everything is in `config/app_config.php` or inline in the controller.
- Auto-enum-extend pattern: `ajouterValeurEnum($pdo, $table, $colonne, $valeur, $defaut)` in `config/app_config.php` adds a value to an existing `ENUM` column if missing (re-reads the current definition via `SHOW COLUMNS` first, so no existing value is lost) — used for `messagerie.Type` (new system message types, e.g. `assurerTemplateExpirationFfttApi()`) and `utilisateur.Role` (`assurerRoleCsr()`, called from `UtilisateurController`). The CSR role (E035) doesn't get its own message type: it reuses `Id_Messagerie = 6` (Réengagements), the same message already sent by E027 — see `MessagerieController::ID_MESSAGE_CSR`.
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
