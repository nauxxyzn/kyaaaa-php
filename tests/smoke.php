<?php

declare(strict_types=1);

/*
 * Standalone smoke test (no PHPUnit needed):   php tests/smoke.php
 * Uses an in-memory SQLite database, so no MySQL is required.
 * Exit code 0 = everything passed.
 */

use Core\Conf\Kyaaaa\CSRF;
use Core\Conf\Kyaaaa\DB;
use Core\Conf\Kyaaaa\DebugBar;
use Core\Conf\Kyaaaa\DebugView;
use Core\Conf\Kyaaaa\ErrorPage;
use Core\Conf\Kyaaaa\HttpException;
use Core\Conf\Kyaaaa\Mailer;
use Core\Conf\Kyaaaa\Renderer;
use Core\Conf\Kyaaaa\Request;
use Core\Conf\Kyaaaa\Route;
use Core\Conf\Kyaaaa\Security;

ob_start(); // lets session_start() work in the CLI

define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', ROOT_PATH . '/public');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Core\\')) {
        $file = ROOT_PATH . '/core/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require ROOT_PATH . '/core/Conf/Kyaaaa/Common.php';

DebugBar::start(false); // collect data without buffering the output, so the checks below can inspect it

$_SERVER['HTTP_HOST'] = 'localhost:5555';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

$GLOBALS['failures'] = 0;

function check(string $name, bool $ok): void
{
    if ($ok) {
        echo "ok    {$name}\n";

        return;
    }

    $GLOBALS['failures']++;
    echo "FAIL  {$name}\n";
}

function throws(callable $fn, string $class = Throwable::class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
}

// --- Query builder ------------------------------------------------------

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, status INTEGER, email TEXT)');
DB::setConnection($pdo);

$users = DB::query('users');
$users->insert(['name' => 'Ayu', 'status' => 1, 'email' => 'ayu@example.com'])->save();
$users->insert(['name' => 'Budi', 'status' => 0, 'email' => null])->save();
$users->insert(['name' => "O'Brien", 'status' => 1, 'email' => 'ob@example.com'])->save();

check('insert + count', DB::query('users')->count() === 3);
check('where (default =)', count(DB::query('users')->select('*')->where('status', 1)->get()) === 2);
check('where (custom operator)', count(DB::query('users')->select('*')->where('status <=', 0)->get()) === 1);
check('where + and', count(DB::query('users')->select('*')->where('status', 1)->and('name', 'Ayu')->get()) === 1);
check('where + or', count(DB::query('users')->select('*')->where('name', 'Ayu')->or('name', 'Budi')->get()) === 2);
check('whereIn', count(DB::query('users')->select('id')->whereIn('name', ['Ayu', 'Budi'])->get()) === 2);
check('whereLike', count(DB::query('users')->select('*')->whereLike('name', 'A%')->get()) === 1);
check('null becomes IS NULL', count(DB::query('users')->select('*')->where('email', null)->get()) === 1);
check('quote in a value is just data', count(DB::query('users')->select('*')->where('name', "O'Brien")->get()) === 1);
check('SQL in a value returns nothing', count(DB::query('users')->select('*')->where('name', "x' OR '1'='1")->get()) === 0);
check('aggregate + first()', (int) DB::query('users')->select('COUNT(*)')->where('status', 1)->first() === 2);
check('orderBy + limit', DB::query('users')->select('name')->orderBy('name', 'ASC')->limit(1)->get()[0]->name === 'Ayu');
check('column ending in "like" is not an operator', str_contains(DB::query('users')->select('*')->where('dislike', 1)->toSql(), 'dislike = :w0'));
check('LIKE operator in column', str_contains(DB::query('users')->select('*')->where('name LIKE', 'A%')->toSql(), 'name LIKE :w0'));
check('row()', DB::query('users')->select('*')->where('name', 'Ayu')->row()->email === 'ayu@example.com');

DB::query('users')->update(['status' => 5])->where('name', 'Budi')->save();
check('update', (int) DB::query('users')->select('status')->where('name', 'Budi')->first() === 5);

DB::query('users')->delete()->where('name', 'Budi')->save();
check('delete', DB::query('users')->count() === 2);

$bad = InvalidArgumentException::class;
check('injection via column rejected', throws(fn() => DB::query('users')->select('*')->where('id = 1; DROP TABLE users; --', 1), $bad));
check('injection via table rejected', throws(fn() => DB::query('users; DROP TABLE users'), $bad));
check('injection via select rejected', throws(fn() => DB::query('users')->select('id FROM users; --'), $bad));
check('injection via orderBy rejected', throws(fn() => DB::query('users')->orderBy('id; DROP TABLE users'), $bad));
check('injection via order direction rejected', throws(fn() => DB::query('users')->orderBy('id', 'ASC; DROP'), $bad));
check('injection via insert column rejected', throws(fn() => DB::query('users')->insert(['name) VALUES (1); --' => 'x']), $bad));
check('injection via join rejected', throws(fn() => DB::query('users')->join('INNER', 'x', '1=1; DROP TABLE users'), $bad));
check('table still exists after attacks', DB::query('users')->count() === 2);

// --- Output escaping ----------------------------------------------------

check('esc() html', esc('<script>alert("x")</script>') === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;');
check('esc() null', esc(null) === '');
check('esc() strict mode', esc('a<b', false) === 'a&#60;b');

// --- Views --------------------------------------------------------------

check('view path traversal rejected', throws(fn() => Renderer::resolve('../Conf/App'), $bad));
check('view name with odd characters rejected', throws(fn() => Renderer::resolve('home%00'), $bad));

// --- Redirects ----------------------------------------------------------

check('relative redirect', Security::safeRedirectTarget('/login') === 'http://localhost:5555/login');
check('same host redirect', Security::safeRedirectTarget('http://localhost:5555/x') === 'http://localhost:5555/x');
check('external host rejected', !Security::isSafeRedirect('https://evil.example/'));
check('protocol-relative rejected', !Security::isSafeRedirect('//evil.example'));
check('javascript: rejected', !Security::isSafeRedirect('javascript:alert(1)'));
check('backslash trick rejected', !Security::isSafeRedirect('/\\evil.example'));
check('userinfo trick rejected', !Security::isSafeRedirect('http://localhost@evil.example/'));
check('CRLF removed', !str_contains(Security::safeRedirectTarget("/a\r\nSet-Cookie: x=1"), "\n"));

// --- CSRF ---------------------------------------------------------------

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_start();

$csrf = new CSRF();
$field = $csrf->getTokenName();
$token = $csrf->token();

$_POST = [];
check('csrf token is 64 hex chars', preg_match('/^[a-f0-9]{64}$/', $token) === 1);
check('csrf missing token rejected', !$csrf->isValidRequest());
$_POST[$field] = $token;
check('csrf valid token accepted', $csrf->isValidRequest());
$_POST[$field] = 'wrong';
check('csrf wrong token rejected', !$csrf->isValidRequest());
$_POST[$field] = ['not', 'a', 'string'];
check('csrf array token rejected', !$csrf->isValidRequest());
$_POST = [];

$html = (new Renderer('home', ['title' => '<b>x</b>', 'subtitle' => 's']))->render();
check('view output is escaped', str_contains($html, '&lt;b&gt;x&lt;/b&gt;') && !str_contains($html, '<b>x</b>'));

$docs = (new Renderer('docs', ['title' => 'Docs']))->render();
check('docs page renders', str_contains($docs, 'id="routing"') && str_contains($docs, 'id="deploy"'));
check('docs code samples are escaped', !str_contains($docs, '<?') && str_contains($docs, '&lt;?= csrf()-&gt;field() ?&gt;'));

$home = (new Renderer('home', ['title' => 'Home']))->render();
check('home page renders', str_contains($home, 'composer create-project') && str_contains($home, 'k-wordmark'));
check('pages carry a CSRF meta tag', str_contains($home, 'name="csrf-token"') && str_contains($docs, 'name="csrf-token"'));

// --- Routing ------------------------------------------------------------

$route = new Route('/items/:id', static fn() => null);
check('route matches', $route->test('items/5') && $route->test('/items/5/'));
check('route is anchored', !$route->test('items/5/extra') && !$route->test('xitems/5'));
check('route literal parts are quoted', (new Route('/files/:name.json', static fn() => null))->test('files/a.json')
    && !(new Route('/files/:name.json', static fn() => null))->test('files/aXjson'));
$constrained = (new Route('/u/:id', static fn() => null))->with('id', '\d+');
check('route constraint', $constrained->test('u/12') && !$constrained->test('u/ab'));
$_GET = ['id' => 'from-query'];
$route->match('items/7');
check('route parameter wins over query string', $_GET['id'] === '7');

// --- Uploads ------------------------------------------------------------

check('filename sanitized', Request::sanitizeFilename('../../etc/passwd') === 'passwd');
check('php extension blocked', Request::hasBlockedExtension('shell.php'));
check('double extension blocked', Request::hasBlockedExtension('shell.php.jpg'));
check('image extension allowed', !Request::hasBlockedExtension('photo.jpg'));

// --- Mailer -------------------------------------------------------------

$mail = new Mailer('localhost', 25);
$mail->setDebug(false);
$mail->setFrom('from@example.com', "Evil\r\nBcc: injected@example.com");
$mail->addTo('to@example.com');
$mail->addTo("victim@example.com\r\nBcc: injected@example.com");
$mail->addBcc('secret@example.com');
$mail->setSubject("Hello\r\nBcc: injected@example.com");
$mail->setBody('<p>Hi</p>');

$message = $mail->createMessage();
$headers = explode("\r\n\r\n", $message, 2)[0];
check('no injected header lines', preg_match('/^Bcc:/mi', $headers) === 0);
check('Bcc recipient is not exposed', !str_contains($message, 'secret@example.com'));
check('address with line break rejected', !str_contains($message, 'victim@example.com'));
check('body is dot-stuffed', Mailer::dotStuff("hi\n.\nQUIT") === "hi\r\n..\r\nQUIT");

// --- Development tools (debug toolbar and error page) --------------------

check('sensitive keys are recognised', DebugView::isSensitive('DB_PASSWORD') && DebugView::isSensitive('csrf') && DebugView::isSensitive('Authorization') && !DebugView::isSensitive('name'));

$masked = DebugView::maskArray(['password' => 'hunter2', 'name' => 'Ayu', 'nested' => ['api_key' => 'k']]);
check('maskArray hides secrets at any depth', $masked['password'] === DebugView::MASK && $masked['name'] === 'Ayu' && $masked['nested']['api_key'] === DebugView::MASK);

check('local clients are not flagged as public', !DebugView::isPublicClient('127.0.0.1') && !DebugView::isPublicClient('192.168.1.20') && !DebugView::isPublicClient('::1') && !DebugView::isPublicClient(''));
check('public clients are flagged', DebugView::isPublicClient('8.8.8.8'));

$pdo->exec('CREATE TABLE vault (id INTEGER PRIMARY KEY, password TEXT)');
DB::query('vault')->insert(['password' => 'hunter2'])->save();
DB::query('vault')->select('*')->where('password', 'hunter2')->get();
dbg('<script>alert(1)</script>', 'xss test');

$bar = DebugBar::collect();
$barHtml = implode('', DebugView::panels($bar));
check('queries are recorded with timing and rows', count($bar['queries']) > 10 && $bar['queries'][0]['ms'] >= 0.0 && $bar['queries'][0]['rows'] !== null);
check('values bound to sensitive columns never reach the toolbar', !str_contains($barHtml, 'hunter2') && str_contains($barHtml, DebugView::MASK));
check('logged values are escaped', !str_contains($barHtml, '<script>alert(1)</script>') && str_contains($barHtml, '&lt;script&gt;'));
check('rendered views are recorded', in_array('view', array_column($bar['views'], 'kind'), true));

$page = DebugBar::filter('<html><body><p>hi</p></body></html>', PHP_OUTPUT_HANDLER_FINAL);
check('toolbar is injected right before </body>', str_contains($page, 'kyaaaa-debug-tpl') && str_ends_with($page, '</body></html>'));
check('JSON responses are left untouched', DebugBar::filter('{"ok":true}', PHP_OUTPUT_HANDLER_FINAL) === '{"ok":true}');
check('discarded buffers are left untouched', DebugBar::filter('<body></body>', PHP_OUTPUT_HANDLER_CLEAN) === '<body></body>');

$error = ErrorPage::render(new RuntimeException('Boom <b>x</b>'), 500);
check('error page shows the class and an escaped message', str_contains($error, 'RuntimeException') && str_contains($error, 'Boom &lt;b&gt;x&lt;/b&gt;') && !str_contains($error, 'Boom <b>x</b>'));
check('error page shows the source and the toolbar', str_contains($error, 'smoke.php') && str_contains($error, 'class="ln hit"') && str_contains($error, 'kyaaaa-debug-tpl'));
check('error page never prints secrets', !str_contains($error, 'hunter2'));

$notFound = ErrorPage::render(new HttpException(404, 'Not Found'), 404);
check('404 page opens on the Routes tab without a stack', str_contains($notFound, 'data-tab="route" aria-selected="true"') && !str_contains($notFound, '<nav class="ep-stack"'));

$failures = (int) $GLOBALS['failures'];
echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";

ob_end_flush();
exit($failures === 0 ? 0 : 1);
