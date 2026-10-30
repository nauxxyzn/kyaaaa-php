<?php
$code = static function (string $source, string $lang = 'php', string $file = ''): string {
    return '<div class="k-code"><div class="k-code-bar"><span>' . esc($file !== '' ? $file : $lang) . '</span>'
        . '<button type="button" class="k-copy" data-copy>Copy</button></div>'
        . '<pre><code data-lang="' . esc($lang) . '">' . esc($source) . '</code></pre></div>';
};

$toc = [
    'Getting started' => [
        ['requirements', 'Requirements'],
        ['install', 'Install and run'],
        ['structure', 'Folder structure'],
        ['config', 'Configuration'],
    ],
    'Building pages' => [
        ['routing', 'Routing'],
        ['controllers', 'Controllers'],
        ['views', 'Views and layouts'],
        ['helpers', 'Helper functions'],
    ],
    'Data' => [
        ['database', 'Query builder'],
        ['models', 'Models'],
    ],
    'Requests' => [
        ['request', 'Request data'],
        ['session', 'Sessions'],
        ['csrf', 'CSRF protection'],
        ['uploads', 'File uploads'],
        ['redirects', 'Redirects and errors'],
    ],
    'More' => [
        ['debugging', 'Debugging'],
        ['mailer', 'Sending email'],
        ['testing', 'Testing'],
        ['deploy', 'Going live'],
    ],
];

$tocList = static function (array $toc): string {
    $html = '';
    foreach ($toc as $group => $items) {
        $html .= '<p class="k-toc-title">' . esc($group) . '</p><ul>';
        foreach ($items as [$id, $label]) {
            $html .= '<li><a href="#' . esc($id) . '">' . esc($label) . '</a></li>';
        }
        $html .= '</ul>';
    }

    return $html;
};
?>
<div class="k-wrap k-docs">
    <nav class="k-toc" aria-label="Documentation"><?= $tocList($toc) ?></nav>

    <article class="k-prose">
        <details class="k-toc-m">
            <summary>On this page</summary>
            <?= $tocList($toc) ?>
        </details>

        <h1>Kyaaaa-PHP documentation</h1>
        <p class="k-lead">Everything you need to build and ship a site with Kyaaaa-PHP. Read it once from the top, then use the sidebar as a reference.</p>

        <h2 id="requirements">Requirements<a href="#requirements" aria-label="Link to this section">#</a></h2>
        <p>Kyaaaa-PHP needs <strong>PHP 8.3 or newer</strong> and <a href="https://getcomposer.org" rel="noopener">Composer</a>. These PHP extensions must be enabled:</p>
        <ul>
            <li><code>pdo</code> and the driver for your database: <code>pdo_mysql</code>, <code>pdo_pgsql</code>, <code>pdo_sqlsrv</code> or <code>pdo_sqlite</code></li>
            <li><code>mbstring</code>, <code>fileinfo</code> and <code>json</code></li>
        </ul>

        <h2 id="install">Install and run<a href="#install" aria-label="Link to this section">#</a></h2>
        <?= $code(<<<'CODE'
composer create-project naufkia/kyaaaa-php:dev-main my-app
cd my-app
cp .env.example .env
php kyaaaa
CODE, 'bash', 'terminal') ?>
        <p>Open <code>http://localhost:5555</code>. Change the address with <code>php kyaaaa --host=localhost --port=8080</code>.</p>
        <aside class="k-note k-note-tip">
            <strong>Use the built-in server for development only.</strong>
            <p>The server started by <code>php kyaaaa</code> is meant for your own machine. For a real site, see <a href="#deploy">Going live</a>.</p>
        </aside>
        <p>Open <code>.env</code> and set <code>APP_ENV=development</code> on your own machine to get detailed error pages. Without it the app runs in production mode and shows a generic error page.</p>

        <h2 id="structure">Folder structure<a href="#structure" aria-label="Link to this section">#</a></h2>
        <div class="k-table-wrap">
            <table>
                <thead><tr><th>Path</th><th>What lives there</th></tr></thead>
                <tbody>
                    <tr><td><code>core/Conf</code></td><td>Your settings: <code>App.php</code>, <code>Database.php</code>, <code>Email.php</code> and the routes in <code>Routes.php</code>.</td></tr>
                    <tr><td><code>core/Conf/Kyaaaa</code></td><td>The framework itself (router, query builder, session, CSRF, mailer). You normally don't edit it.</td></tr>
                    <tr><td><code>core/Controllers</code></td><td>Classes that handle a route.</td></tr>
                    <tr><td><code>core/Models</code></td><td>Classes that talk to the database.</td></tr>
                    <tr><td><code>core/Views</code></td><td>Page templates, named <code>name.kyaaaa.php</code>, and <code>layout.kyaaaa.php</code>.</td></tr>
                    <tr><td><code>public</code></td><td>The only folder the web server should serve: <code>index.php</code> and your assets.</td></tr>
                    <tr><td><code>tests</code></td><td>The smoke test. Add your own checks here.</td></tr>
                    <tr><td><code>.env</code></td><td>Secrets and per-machine settings. Never commit it.</td></tr>
                </tbody>
            </table>
        </div>

        <h2 id="config">Configuration<a href="#config" aria-label="Link to this section">#</a></h2>
        <p>Settings come from <code>.env</code>. Copy <code>.env.example</code> and fill in what you need:</p>
        <?= $code(<<<'CODE'
APP_ENV=development
APP_TIMEZONE=Asia/Jakarta
APP_URL=

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kyaaaa_db
DB_USERNAME=root
DB_PASSWORD=

MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_CRYPTO=ssl
MAIL_USERNAME=
MAIL_PASSWORD=
CODE, 'env', '.env') ?>
        <p>Read any value in your own code with <code>env('KEY', 'default')</code>. Values are always strings, and a real environment variable wins over the same key in <code>.env</code>.</p>

        <h3>App settings</h3>
        <div class="k-table-wrap">
            <table>
                <thead><tr><th>Key</th><th>What it does</th></tr></thead>
                <tbody>
                    <tr><td><code>APP_ENV</code></td><td><code>development</code> shows detailed errors. Anything else is production. Default: production.</td></tr>
                    <tr><td><code>APP_TIMEZONE</code></td><td>PHP timezone, for example <code>Asia/Jakarta</code>.</td></tr>
                    <tr><td><code>APP_URL</code></td><td>Public address of the site. Set it in production so <code>url()</code> never depends on what a visitor sends in the <code>Host</code> header.</td></tr>
                    <tr><td><code>APP_ALLOWED_HOSTS</code></td><td>Comma separated host names. A request for any other host gets a 400.</td></tr>
                    <tr><td><code>APP_REDIRECT_HOSTS</code></td><td>Extra hosts that <code>redirectTo()</code> may send people to.</td></tr>
                    <tr><td><code>TRUST_PROXY</code></td><td>Set to <code>true</code> only behind a reverse proxy or CDN you control. It makes <code>X-Forwarded-Proto</code> count when the app checks for HTTPS.</td></tr>
                </tbody>
            </table>
        </div>

        <h3>Security headers</h3>
        <p>Every response gets <code>X-Content-Type-Options</code>, <code>X-Frame-Options</code>, <code>Referrer-Policy</code> and <code>Permissions-Policy</code>, plus <code>Strict-Transport-Security</code> on HTTPS. Add or remove headers in <code>App.php</code>:</p>
        <?= $code(<<<'CODE'
public function security_headers(): array
{
    return [
        'Content-Security-Policy' => "default-src 'self'",
        'X-Frame-Options' => false, // false removes a default header
    ];
}
CODE, 'php', 'core/Conf/App.php') ?>
        <aside class="k-note k-note-warn">
            <strong>Strict Content-Security-Policy and the default layout.</strong>
            <p>The default layout has one small inline script that restores the light or dark theme. Allow it, or delete it, before you turn on a strict policy.</p>
        </aside>

        <h2 id="routing">Routing<a href="#routing" aria-label="Link to this section">#</a></h2>
        <p>Routes live in <code>core/Conf/Routes.php</code>. A handler can be <code>[Controller::class, 'method']</code>, a closure, or the string <code>'Fully\Qualified\Controller#method'</code>.</p>
        <?= $code(<<<'CODE'
$router = new Router();

$router->get('/', [HomeCtrl::class, 'index']);
$router->post('/login', [AuthCtrl::class, 'login'])->csrf();

// :id is passed to the handler as an argument
$router->get('/items/:id', [ItemCtrl::class, 'show'])
    ->with('id', '\d+');

$router->group('/admin', function ($router) {
    $router->get('/users', [AdminCtrl::class, 'users']);   // /admin/users
});

$router->run();
CODE, 'php', 'core/Conf/Routes.php') ?>
        <p>Available methods are <code>get</code>, <code>post</code>, <code>put</code>, <code>patch</code> and <code>delete</code>. Route parameters are also available in <code>$_GET</code>, and they win over a query-string value with the same name.</p>

        <h3>Named routes</h3>
        <?= $code(<<<'CODE'
$router->get('/items/:id', [ItemCtrl::class, 'show'], 'item');

$path = $router->url('item', ['id' => 5]);   // "items/5"
$link = url($path);                          // full address
CODE, 'php') ?>

        <h3>Middleware</h3>
        <p>A middleware runs before the handler. Stop the request by throwing an <code>HttpException</code> or by returning <code>false</code> (which gives a 403).</p>
        <?= $code(<<<'CODE'
$router->get('/account', [AccountCtrl::class, 'index'])
    ->middleware(function () {
        if (!session()->has('user_id')) {
            redirectTo('/login');
        }
    });
CODE, 'php') ?>

        <h3>PUT, PATCH and DELETE from a form</h3>
        <p>HTML forms only send GET and POST. Add a hidden <code>_method</code> field to a POST form and the router treats it as that method.</p>
        <?= $code(<<<'CODE'
<form method="post" action="/items/5">
    <input type="hidden" name="_method" value="DELETE">
    <?= csrf()->field() ?>
    <button type="submit">Delete</button>
</form>
CODE, 'php') ?>
        <p>An unknown path returns <strong>404</strong>. A known path with the wrong method returns <strong>405</strong> and an <code>Allow</code> header.</p>

        <h2 id="controllers">Controllers<a href="#controllers" aria-label="Link to this section">#</a></h2>
        <p>A controller is a plain class in <code>core/Controllers</code>. Route parameters arrive as arguments, always as strings, so cast them.</p>
        <?= $code(<<<'CODE'
namespace Core\Controllers;

use Core\Models\UserModel;

class UserCtrl
{
    public function show(string $id): void
    {
        $user = (new UserModel())->find((int) $id);

        if ($user === null) {
            abort(404, 'User not found');
        }

        view('user/show', ['title' => 'Profile', 'user' => $user]);
    }
}
CODE, 'php', 'core/Controllers/UserCtrl.php') ?>

        <h2 id="views">Views and layouts<a href="#views" aria-label="Link to this section">#</a></h2>
        <p><code>view('user/show', $data)</code> renders <code>core/Views/user/show.kyaaaa.php</code>. The keys of <code>$data</code> become variables inside the view. The result is placed in <code>$content</code> of the layout: <code>user/layout.kyaaaa.php</code> if it exists, otherwise <code>core/Views/layout.kyaaaa.php</code>.</p>
        <?= $code(<<<'CODE'
<h1><?= esc($user->name) ?></h1>
<p>Member since <?= esc($user->created_at) ?></p>
<a href="<?= esc(url('items/5')) ?>">Open item</a>
CODE, 'php', 'core/Views/user/show.kyaaaa.php') ?>
        <aside class="k-note k-note-warn">
            <strong>Always escape output.</strong>
            <p>Print every variable with <code>esc()</code>, unless it already holds HTML you built yourself, like <code>$content</code> in the layout. View names may only contain letters, digits, <code>_</code>, <code>-</code> and <code>/</code>, so a visitor can never make a view load another file.</p>
        </aside>
        <p>Set <code>$data['title']</code> and <code>$data['description']</code> to fill the page title and meta description of the default layout.</p>

        <h2 id="helpers">Helper functions<a href="#helpers" aria-label="Link to this section">#</a></h2>
        <div class="k-table-wrap">
            <table>
                <thead><tr><th>Function</th><th>What it does</th></tr></thead>
                <tbody>
                    <tr><td><code>view($name, $data)</code></td><td>Render a view inside the layout and print it.</td></tr>
                    <tr><td><code>esc($value)</code></td><td>Escape for HTML. <code>esc($value, false)</code> is the strict mode for attributes. Arrays are escaped item by item.</td></tr>
                    <tr><td><code>url($path)</code></td><td>Full address of the site, optionally followed by a path.</td></tr>
                    <tr><td><code>env($key, $default)</code></td><td>Read a value from the environment or <code>.env</code>.</td></tr>
                    <tr><td><code>session()</code></td><td>Session helper. See <a href="#session">Sessions</a>.</td></tr>
                    <tr><td><code>request()</code></td><td>Request helper. See <a href="#request">Request data</a>.</td></tr>
                    <tr><td><code>csrf()</code></td><td>CSRF helper. See <a href="#csrf">CSRF protection</a>.</td></tr>
                    <tr><td><code>redirectTo($url, $code)</code></td><td>Redirect inside your site and stop.</td></tr>
                    <tr><td><code>redirectExternal($url)</code></td><td>Redirect to a trusted external address.</td></tr>
                    <tr><td><code>abort($status, $message)</code></td><td>Stop with an HTTP error page.</td></tr>
                    <tr><td><code>dd(...$vars)</code>, <code>d(...$vars)</code></td><td>Dump variables and stop. They only work in development.</td></tr>
                    <tr><td><code>dbg($value, $label)</code></td><td>Send a value to the debug toolbar and return it. Does nothing in production. See <a href="#debugging">Debugging</a>.</td></tr>
                </tbody>
            </table>
        </div>

        <h2 id="database">Query builder<a href="#database" aria-label="Link to this section">#</a></h2>
        <p>Set the connection in <code>.env</code> (<code>mysql</code>, <code>pgsql</code>, <code>sqlsrv</code> or <code>sqlite</code>). The builder connects on the first query, and rows come back as objects.</p>
        <?= $code(<<<'CODE'
use Core\Conf\Kyaaaa\DB;

$users = DB::query('users');

$rows = $users->select('id, name')
    ->where('status', 1)
    ->orderBy('name', 'ASC')
    ->limit(10)
    ->get();

$user = $users->select('*')->where('id', $id)->row();   // one row, or null

// first column of the first row
$total = $users->select('COUNT(*)')->where('status', 1)->first();

$count = DB::query('users')->where('status', 1)->count();
CODE, 'php') ?>

        <h3>Conditions</h3>
        <?= $code(<<<'CODE'
->where('age >=', 18)            // operators: = != <> < > <= >= LIKE
->and('country', 'ID')
->or('role', 'admin')
->whereIn('id', [1, 2, 3])
->whereLike('name', 'A%')
->whereIsNull('deleted_at')
->where('email', null)           // becomes IS NULL
CODE, 'php') ?>
        <p>The first condition becomes <code>WHERE</code>. Every later <code>where()</code> or <code>and()</code> adds <code>AND</code>, and <code>or()</code> adds <code>OR</code>.</p>

        <h3>Joins, grouping and paging</h3>
        <?= $code(<<<'CODE'
DB::query('users u')
    ->select('u.name, p.title')
    ->join('LEFT', 'posts p', 'u.id = p.user_id')
    ->where('u.status', 1)
    ->limit(20)->offset(40)
    ->get();

DB::query('orders')
    ->select('user_id, COUNT(*) AS total')
    ->groupBy('user_id')
    ->having('COUNT(*)', '>', 5)
    ->get();
CODE, 'php') ?>

        <h3>Insert, update and delete</h3>
        <p>These build a statement. Call <code>save()</code> to run it.</p>
        <?= $code(<<<'CODE'
$users = DB::query('users');

$users->insert(['name' => $name, 'email' => $email])->save();
$id = $users->lastInsertId();

$users->update(['status' => 0])->where('id', $id)->save();
$users->delete()->where('id', $id)->save();
$changed = $users->rowCount();
CODE, 'php') ?>
        <aside class="k-note k-note-warn">
            <strong>Forgetting <code>where()</code> changes every row.</strong>
            <p><code>update()</code> and <code>delete()</code> without a condition affect the whole table, exactly like SQL does.</p>
        </aside>

        <h3>Why it is injection-safe</h3>
        <p>Values always travel as bound parameters. Table names, column names, operators, joins and sort fields are checked against a strict pattern, and anything unusual throws an <code>InvalidArgumentException</code> instead of reaching the database. This is why <code>select()</code> only accepts plain columns, aliases and simple aggregates such as <code>COUNT(*)</code> or <code>SUM(amount)</code>.</p>
        <p>For anything more exotic there are two escape hatches. <strong>Never give them user input.</strong></p>
        <?= $code(<<<'CODE'
$rows = DB::query('users')
    ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") AS month')
    ->get();

// raw(): pass values through bindings, never by concatenation
$rows = DB::query()->raw(
    'SELECT * FROM users WHERE email = ? AND status = ?',
    [$email, 1]
);
CODE, 'php') ?>

        <h2 id="models">Models<a href="#models" aria-label="Link to this section">#</a></h2>
        <p>A model is a class that keeps the queries for one table in one place. Each <code>select()</code>, <code>insert()</code>, <code>update()</code> or <code>delete()</code> starts a fresh statement, so one builder can be reused safely.</p>
        <?= $code(<<<'CODE'
namespace Core\Models;

use Core\Conf\Kyaaaa\DB;

class UserModel
{
    private DB $users;

    public function __construct()
    {
        $this->users = DB::query('users');
    }

    public function find(int $id): ?object
    {
        return $this->users->select('*')->where('id', $id)->row();
    }

    public function create(string $name, string $email): string|false
    {
        $this->users->insert(['name' => $name, 'email' => $email])->save();

        return $this->users->lastInsertId();
    }
}
CODE, 'php', 'core/Models/UserModel.php') ?>

        <h2 id="request">Request data<a href="#request" aria-label="Link to this section">#</a></h2>
        <?= $code(<<<'CODE'
$name = request()->post('name');     // POST value, or null
$all  = request()->post();           // every POST value
$page = request()->get('page', 1);   // GET value with a default
$term = request()->request('q');     // GET or POST, never cookies

request()->isPost();                 // also isGet(), isAjax()
request()->header('Authorization');

$payload = request()->json();        // decoded JSON body, or null
$raw     = request()->body();        // raw body, for webhook signatures
CODE, 'php') ?>
        <p>Request data is untrusted. Validate it, cast it, and escape it when you print it.</p>

        <h2 id="session">Sessions<a href="#session" aria-label="Link to this section">#</a></h2>
        <?= $code(<<<'CODE'
session()->set('user_id', 12);
session()->get('user_id');             // 12
session()->has('user_id');             // true
session()->remove('user_id');

session()->flash('notif', 'Saved!');   // set now ...
session()->getFlash('notif');          // ... read once, then it is gone

session()->regenerate();   // call right after login or logout
session()->clear();        // log out: empty and destroy the session
CODE, 'php') ?>
        <p>Session cookies are HttpOnly, <code>SameSite=Lax</code> and <code>Secure</code> on HTTPS, and unknown session ids are refused. Change the options in <code>App::session()</code>.</p>

        <h2 id="csrf">CSRF protection<a href="#csrf" aria-label="Link to this section">#</a></h2>
        <p>A CSRF attack makes a visitor's browser send a request they never meant to send. Protect every route that changes data by adding <code>->csrf()</code> to it, and put the token in your form:</p>
        <?= $code(<<<'CODE'
$router->post('/post', [PostCtrl::class, 'store'])->csrf();
CODE, 'php', 'core/Conf/Routes.php') ?>
        <?= $code(<<<'CODE'
<form method="post" action="/post">
    <?= csrf()->field() ?>
    <input name="title">
    <button type="submit">Save</button>
</form>
CODE, 'php', 'a view') ?>
        <p>The default layout already prints <code>csrf()->metaTag()</code>. For AJAX, send that token in the <code>X-CSRF-Token</code> header:</p>
        <?= $code(<<<'CODE'
const token = document.querySelector('meta[name="csrf-token"]').content;

fetch('/post', {
    method: 'POST',
    headers: { 'X-CSRF-Token': token },
    body: new FormData(form),
});
CODE, 'js') ?>
        <p>A missing, wrong or expired token (two hours by default) gives a <strong>419 Page Expired</strong> response. If the browser sends an <code>Origin</code> header, it must also match your site.</p>
        <aside class="k-note k-note-tip">
            <strong>Server-to-server callbacks have no token.</strong>
            <p>Don't add <code>->csrf()</code> to a webhook that another server calls, such as a payment notification. Verify its signature instead, using <code>request()->body()</code>.</p>
        </aside>

        <h2 id="uploads">File uploads<a href="#uploads" aria-label="Link to this section">#</a></h2>
        <?= $code(<<<'CODE'
$file  = request()->file('avatar');
$types = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

// validate() checks the real MIME type, not the one the browser claims
if (request()->validate($file, $types)) {
    $ext = $types[request()->getFileType($file)];

    // a random name: never trust the name the client sent
    $name = bin2hex(random_bytes(8)) . '.' . $ext;

    request()->move($file, 'uploads/avatars', $name);
}
CODE, 'php') ?>
        <p><code>move()</code> stores the file inside <code>public/</code> and returns <code>true</code> or <code>false</code>. It refuses executable extensions (even hidden ones like <code>shell.php.jpg</code>), refuses <code>..</code> in the folder, only accepts real HTTP uploads, and won't overwrite an existing file unless you pass <code>true</code> as the fourth argument.</p>
        <aside class="k-note k-note-warn">
            <strong>SVG files can contain scripts.</strong>
            <p>Only accept SVG uploads from people you trust.</p>
        </aside>

        <h2 id="redirects">Redirects and errors<a href="#redirects" aria-label="Link to this section">#</a></h2>
        <?= $code(<<<'CODE'
redirectTo('/login');                 // a path on your site
redirectTo('/dashboard', 302);        // 301, 302, 303 (default), 307 or 308
redirectExternal($paymentUrl);        // only for addresses you trust

abort(404);
abort(403, 'No access');
throw new \Core\Conf\Kyaaaa\HttpException(422, 'Invalid input');
CODE, 'php') ?>
        <p><code>redirectTo()</code> accepts relative paths and addresses on the same host. Any other address throws, so <code>?next=https://evil.example</code> can't turn your site into an open redirect. Use <code>redirectExternal()</code> only with addresses from your own code or a trusted API, never with user input.</p>

        <h3>Error pages</h3>
        <p>In <strong>development</strong>, an error shows its message, the failing line of code and the stack trace. In <strong>production</strong>, visitors see a generic page, and the details go to the PHP error log. Requests that ask for JSON get a JSON error. The message of a 4xx <code>HttpException</code> is shown to the visitor, the message of a 5xx never is.</p>

        <h2 id="debugging">Debugging<a href="#debugging" aria-label="Link to this section">#</a></h2>
        <p>Set <code>APP_ENV=development</code> in <code>.env</code> and two tools appear: an error inspector and a debug toolbar. In production neither exists. Nothing is collected, buffered or shown.</p>

        <h3>The error page</h3>
        <p>An uncaught error opens an inspector instead of a generic page:</p>
        <ul>
            <li>The <strong>stack</strong> is on the left. Frames from your own code have a pink edge. Framework and vendor frames stay hidden until you untick the box.</li>
            <li>The <strong>source</strong> of the selected frame is on the right, with the failing line highlighted.</li>
            <li><strong>Tabs</strong> underneath show the request, the matched route, the SQL queries, the session, the loaded files and the PHP environment. The page opens on the Queries tab when a query failed.</li>
            <li>A <strong>hint</strong> explains common mistakes, such as a missing CSRF token or a wrong view name. <strong>Copy error</strong> puts a short report on your clipboard, ready to paste into an issue.</li>
        </ul>
        <p>A 404 or 405 lists every registered route, so you can see what the router knows.</p>

        <h3>The debug toolbar</h3>
        <p>A floating bar sits at the bottom left of every HTML page. Click a section to open its panel. Click it again, press <code>Esc</code> or use the &times; button to close it. The round <strong>K~</strong> button, or <code>Alt</code> + <code>K</code>, collapses the whole bar into a small icon, and the page remembers your choice.</p>
        <div class="k-table-wrap">
            <table>
                <thead><tr><th>Section</th><th>What it shows</th></tr></thead>
                <tbody>
                    <tr><td>Request</td><td>Status code, headers, GET and POST data, cookies and response headers.</td></tr>
                    <tr><td>Route</td><td>The route that matched, its parameters, and every registered route.</td></tr>
                    <tr><td>Time</td><td>How the request time splits between the database, the views and everything else, plus a timeline.</td></tr>
                    <tr><td>Memory</td><td>Memory use compared with the PHP limit, and the PHP settings.</td></tr>
                    <tr><td>SQL</td><td>Every query with its time, rows and bound values. Slow queries (50 ms or more) and queries that run more than once are flagged, which often points to an N+1 problem.</td></tr>
                    <tr><td>Views</td><td>Each view and layout with its render time and variables.</td></tr>
                    <tr><td>Files</td><td>The PHP files loaded for the request, grouped by your code, the framework and Composer packages.</td></tr>
                    <tr><td>Session</td><td>What is stored in the session.</td></tr>
                    <tr><td>Log</td><td>Values you sent with <code>dbg()</code>.</td></tr>
                </tbody>
            </table>
        </div>
        <p>Every response also carries a <code>Server-Timing</code> header, which browsers show in the network tab of their developer tools. The toolbar lives in a Shadow DOM, so your site's CSS can't change how it looks.</p>

        <h3>Logging a value with dbg()</h3>
        <?= $code(<<<'CODE'
$user = dbg($users->select('*')->where('id', $id)->row(), 'user');

dbg(session()->get('cart'), 'cart');
CODE, 'php') ?>
        <p><code>dbg()</code> shows the value in the Log tab and returns it, so you can wrap it around any expression. In production it does nothing.</p>

        <aside class="k-note k-note-warn">
            <strong>Development mode is for your own machine.</strong>
            <p>Passwords, tokens, cookies, session ids and values bound to columns such as <code>password</code> are always replaced by &bull;&bull;&bull;&bull;&bull;&bull;. Error pages still show file paths and source code, so never leave <code>APP_ENV=development</code> on a public site. When someone outside your local network opens a page, the toolbar and the error page show a <strong>Public client</strong> warning.</p>
        </aside>
        <aside class="k-note k-note-tip">
            <strong>A strict Content-Security-Policy blocks these tools.</strong>
            <p>They use an inline script and inline styles. Turn a strict policy on for production only, for example by returning it from <code>security_headers()</code> when <code>APP_ENV</code> is not <code>development</code>.</p>
        </aside>

        <h2 id="mailer">Sending email<a href="#mailer" aria-label="Link to this section">#</a></h2>
        <?= $code(<<<'CODE'
$mail = (new \Core\Conf\Email())->start();

$mail->setFrom('no-reply@example.com', 'My App');
$mail->addTo($email);
$mail->setSubject('Welcome');
$mail->setBody('<p>Glad you are here.</p>');

if (!$mail->send()) {
    error_log($mail->getLastError());
}
CODE, 'php') ?>
        <p>Set the SMTP server in the <code>MAIL_*</code> values of <code>.env</code>. The HTML body also gets a plain-text version automatically. Add recipients with <code>addCc()</code> and <code>addBcc()</code>, and files with <code>addFile()</code>. If a file name can ever come from a visitor, call <code>allowAttachmentsFrom('/path/to/folder')</code> first.</p>
        <p>Addresses with a line break or an invalid format are skipped. The mailer verifies the server's TLS certificate and never sends your password over an unencrypted connection.</p>

        <h2 id="testing">Testing<a href="#testing" aria-label="Link to this section">#</a></h2>
        <?= $code(<<<'CODE'
composer test
CODE, 'bash', 'terminal') ?>
        <p>This runs <code>tests/smoke.php</code> (or run <code>php tests/smoke.php</code> yourself). It uses an in-memory SQLite database, so you don't need MySQL. It checks the query builder, escaping, redirects, CSRF, routing, uploads and the mailer, including attempts at SQL injection. Add your own checks at the bottom of the file.</p>

        <h2 id="deploy">Going live<a href="#deploy" aria-label="Link to this section">#</a></h2>
        <ul>
            <li>Point the web server's document root to the <code>public</code> folder, never to the project root.</li>
            <li>Keep <code>APP_ENV</code> unset or <code>production</code>, and set <code>APP_URL</code> to your real address.</li>
            <li>Set <code>APP_ALLOWED_HOSTS</code> to your host names.</li>
            <li>Serve the site over HTTPS. Set <code>TRUST_PROXY=true</code> only when a proxy you control terminates it.</li>
            <li>Keep <code>.env</code> out of git and out of the public folder.</li>
            <li>Call <code>session()->regenerate()</code> after login and logout.</li>
        </ul>
        <p>On Apache the included <code>.htaccess</code> files do the rest. On Nginx:</p>
        <?= $code(<<<'CODE'
server {
    server_name example.com;
    root /var/www/my-app/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ \.php$ { return 404; }       # no other PHP file may run
    location ~ /\.(?!well-known) { deny all; }
}
CODE, 'nginx', 'nginx.conf') ?>
        <p>Found a bug or have a question? Open an issue on <a href="https://github.com/naufkia/kyaaaa-php" rel="noopener">GitHub</a>.</p>
    </article>
</div>
