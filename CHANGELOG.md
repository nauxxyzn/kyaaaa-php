# Changelog

## 2.0.0 - PHP 8.3+ and security release

### Upgrading an existing project
1. Replace `core/Conf/Kyaaaa/*` (and `public/index.php`, `public/.htaccess`, `kyaaaa`, `composer.json`).
2. **Delete `core/Conf/Kyaaaa/Handler/`** (old Whoops fork, replaced by `ErrorHandler.php`).
3. Run `composer dump-autoload`.
4. Move DB / SMTP passwords to `.env` (see `.env.example`). `core/Conf/App.php`, `Database.php`, `Email.php`
   of your own project keep working, the new `App` settings are optional.
5. Set `APP_ENV=development` locally. **The default is now `production`.**

### PHP 8.3 compatibility
- `Session::config()` is static (it was called statically while declared non-static: fatal error on PHP 8).
- `Request::move()` used an undefined constant (`replace == true`): fixed.
- No more dynamic properties, implicit nullable parameters, `Serializable` without `__serialize`,
  `extract(error_get_last())`, `session_id(null)` or `count(null)`.
- Typed code with `declare(strict_types=1)`; uses `json_validate()`, `never` and readonly promotion.
- `error_reporting(0)` is gone: errors are logged and handled by `ErrorHandler`.
- Fixed in routing: query strings no longer break matching, the first `$_GET` parameter is no longer dropped,
  parameters with custom regexes work, `Exception` in `Routes.php` no longer resolves to `Core\Conf\Exception`.

### Security fixes
| Area | Problem | Fix |
|------|---------|-----|
| `DB` | SQL injection through `where`, `or`, `and`, `having`, `whereLike`, `whereIn`, `orderBy`, `limit`, `groupBy`, `join`, `union`, index hints, `select`, table and column names | all values are bound parameters; identifiers, operators, joins and expressions are validated |
| `DB` | one shared static table, so two models could query each other's table | every `DB::query()` is an independent builder |
| `DB` | `raw()` could not take bindings | `raw($sql, $bindings)` |
| `Connection` | `utf8` charset, SQL Server DSN wrong, a failed connection leaked details and returned `null` | `utf8mb4`, correct DSN, generic exception (details only in the log) |
| `CSRF` | token derived from a hard-coded key and `time()`, `hash_equals()` crash on array input | `random_bytes()` token, type checks, `X-CSRF-Token` header, Origin check |
| `Session` | cookie not HttpOnly / SameSite / Secure, no strict mode, `regenerate()` kept the old session | secure defaults, strict mode, regenerate deletes the old session and rotates the CSRF token |
| `redirectTo()` | XSS through the unescaped URL, open redirect, header injection | validated targets (same host or allow-list), CR/LF stripped, escaped body; `redirectExternal()` for trusted URLs |
| `url()` | trusted the `Host` header | validated host, `APP_URL`, `APP_ALLOWED_HOSTS`, proxy headers only when trusted |
| `Request::move()` | path traversal / arbitrary file write, world-writable directories, uploaded `.php` files | sanitized names, executable extensions refused, destination must stay in `public/`, `is_uploaded_file()`, mode 0755/0644, no silent overwrite |
| `Request` | client supplied MIME type, `$_REQUEST` included cookies, `getUri()` returned the Referer for AJAX | MIME from content, GET+POST only, always `REQUEST_URI` |
| `Renderer` / `viewPath()` | view name could traverse directories, `extract()` could overwrite internals, `require_once` skipped the layout on the second render | strict view names inside `core/Views`, `EXTR_SKIP`, isolated scope |
| `esc()` | broken "strict" mode that lower-cased text, double encoding, no charset | `htmlspecialchars(..., UTF-8)`, working strict mode, arrays supported |
| Error pages | development page dumped `$_SERVER`, cookies and session; `dd()` worked in production; route errors printed raw exception messages with status 200 | no environment dumps, `dd()` disabled in production, proper 4xx/5xx status codes |
| `Mailer` | header injection, Bcc exposed in headers, SMTP command injection (no dot-stuffing), TLS not verified, STARTTLS failure ignored, credentials sent in plain text, credentials printed in debug output | all fixed, see class docblock |
| Defaults | no security headers, `.env`/dot files and `core/` reachable on misconfigured hosts, `environment()` defaulted to development | security headers, hardened `.htaccess` files, `.env` support, production by default |

### Behaviour changes to be aware of
- `redirectTo()` now accepts relative paths and same-host URLs only. Use `redirectExternal()` for trusted external URLs
  (e.g. a payment page) or list hosts in `App::redirect_hosts()`.
- `Request::move()` returns `false` instead of overwriting an existing file unless `$replace` is `true`.
- `Request::getFileName()` returns the sanitized name, `getFileType()` the detected MIME type.
- `where('col', null)` produces `IS NULL`; a second `where()` is `AND` (before it produced invalid SQL).
- `select()` accepts plain columns, aliases and simple aggregates. Use `selectRaw()` for anything more exotic.
- `Router::url()` throws for an unknown route name instead of returning the error message.
- A wrong HTTP method on a known path gives 405 (was a plain message), unknown paths give 404.
- Development tools (only with `APP_ENV=development`): an error inspector (stack, source, hints, request / route / query / session tabs, route list on 404), a floating debug toolbar that collapses to an icon (`Alt+K`), query timing with N+1 and slow-query flags, `dbg($value, $label)` and a `Server-Timing` header. Sensitive values are masked and a "Public client" warning shows when the site is opened from a public address.
- Added: `Env`, `HttpException`, `abort()`, `Route::csrf()`, `DB::count()/row()/offset()/selectRaw()/lastInsertId()`,
  `Request::header()/body()/json()`, `csrf()->field()/metaTag()`, POST `_method` override for PUT/PATCH/DELETE.
