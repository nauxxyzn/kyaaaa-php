### Kyaaaa-PHP
#### Lightweight PHP Framework

![minimum_requirements_php](https://img.shields.io/badge/PHP-%5E8.3-green?style=flat-square&logo=PHP)
![last_commit](https://img.shields.io/github/last-commit/naufkia/kyaaaa-php?style=flat-square)

Requires **PHP 8.3 or newer** with the `pdo`, `mbstring`, `fileinfo` and `json` extensions.

#### Directory Structure :

| Directory          | Description             |
|--------------------|-------------------------|
| `core`             | App Directory           |
| `core/Conf`        | Configuration Directory |
| `core/Controllers` | Controllers Directory   |
| `core/Models`      | Models Directory        |
| `core/Views`       | Views Directory         |
| `public`           | Public Directory        |
| `tests`            | Smoke tests             |

#### Installation
1. `composer create-project naufkia/kyaaaa-php:dev-main`
2. `cp .env.example .env` and fill in your settings (`APP_ENV=development` on your own machine)
3. `php kyaaaa` (options: `--host=localhost --port=5555`)
4. Open `http://localhost:5555` on your browser.

Run the checks with `composer test` (or `php tests/smoke.php`).

#### Security notes

- **Point the web server's document root to `public/`.** Never to the project root.
  Nginx: `root /path/to/project/public; location / { try_files $uri /index.php$is_args$args; }`
  and `location ~ \.php$ { fastcgi_pass ...; }` only for `/index.php`.
- Keep secrets in `.env` (git-ignored), not in `core/Conf/*.php`.
- Without `APP_ENV=development` the app runs in production mode: generic error pages, details only in the PHP error log.
- Set `APP_URL` (and ideally `APP_ALLOWED_HOSTS`) in production so links never depend on the visitor's `Host` header.
- Set `TRUST_PROXY=true` only behind a trusted reverse proxy / CDN.
- Query builder: values are always bound parameters, identifiers are validated. `selectRaw()` and `raw()` are the only
  unsafe entry points, never give them user input (pass values to `raw()` through its `$bindings` argument).
- Output: escape with `esc($value)` in views. `url()`, forms and redirects are already safe.
- State changing routes: `$router->post('/x', ...)->csrf();` and `<?= csrf()->field() ?>` in the form
  (AJAX: send `csrf()->metaTag()`'s token as the `X-CSRF-Token` header).
- Call `session()->regenerate()` right after login / logout.
- Uploads: `request()->validate()` checks the real MIME type, `request()->move()` refuses executable extensions,
  path traversal and overwriting. SVG files can contain scripts, only accept them from trusted users.

#### Documentation
The project includes documentation. Just run it once to get started!