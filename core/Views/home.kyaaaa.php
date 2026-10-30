<?php
$code = static function (string $source, string $lang = 'php', string $file = ''): string {
    return '<div class="k-code"><div class="k-code-bar"><span>' . esc($file !== '' ? $file : $lang) . '</span>'
        . '<button type="button" class="k-copy" data-copy>Copy</button></div>'
        . '<pre><code data-lang="' . esc($lang) . '">' . esc($source) . '</code></pre></div>';
};
?>
<section class="k-hero">
    <div class="k-wrap k-hero-grid">
        <div>
            <h1 class="k-wordmark">Kyaaaa<span class="k-sr">-PHP</span><span class="k-tilde" aria-hidden="true">~</span></h1>
            <p class="k-lead">A small PHP 8.3 framework you can read in one afternoon. It gives you a router, a query builder, sessions, CSRF protection and a mailer, with safe defaults already switched on.</p>
            <div class="k-actions">
                <a class="k-btn k-btn-primary" href="<?= esc(url('docs')) ?>">Read the docs</a>
                <a class="k-btn" href="https://github.com/naufkia/kyaaaa-php" rel="noopener">View on GitHub</a>
            </div>
        </div>

        <div class="k-bubble">
            <h2>Up and running in three commands</h2>
            <?= $code(<<<'CODE'
composer create-project naufkia/kyaaaa-php:dev-main my-app
cd my-app && cp .env.example .env
php kyaaaa
CODE, 'bash', 'terminal') ?>
            <p>Then open <code>http://localhost:5555</code>. Set <code>APP_ENV=development</code> in <code>.env</code> to see detailed error pages while you build.</p>
        </div>
    </div>
</section>

<section class="k-section">
    <div class="k-wrap">
        <h2>Small on purpose, careful by default</h2>
        <p class="k-section-lead">Everything below ships in the box. There is no hidden magic: each piece is one short file you can open and read.</p>

        <div class="k-grid">
            <article class="k-panel k-span-7">
                <h3>Routes that read like a sentence</h3>
                <p>Parameters, regex limits, groups, middleware and CSRF checks chain onto the route.</p>
                <?= $code(<<<'CODE'
$router->get('/', [HomeCtrl::class, 'index']);

$router->get('/items/:id', [ItemCtrl::class, 'show'])
    ->with('id', '\d+');

$router->post('/items', [ItemCtrl::class, 'store'])
    ->csrf();
CODE, 'php', 'core/Conf/Routes.php') ?>
            </article>

            <article class="k-panel k-span-5">
                <h3>Queries that can't be injected</h3>
                <p>Values are bound parameters and column names are validated, so user input never changes the query.</p>
                <?= $code(<<<'CODE'
$rows = DB::query('users')
    ->select('id, name')
    ->where('status', 1)
    ->and('name LIKE', $search)
    ->orderBy('name', 'ASC')
    ->limit(20)
    ->get();
CODE, 'php', 'a model') ?>
            </article>

            <article class="k-panel k-panel-pink k-span-5">
                <h3>Safe defaults you don't have to remember</h3>
                <ul class="k-checks">
                    <li>Output is escaped with <code>esc()</code>.</li>
                    <li>Forms and AJAX calls carry CSRF tokens.</li>
                    <li>Session cookies are HttpOnly and SameSite.</li>
                    <li>Production error pages never show details.</li>
                    <li>Uploads refuse <code>.php</code> files and path tricks.</li>
                    <li>Redirects can't be bent to other sites.</li>
                </ul>
                <p class="k-panel-note">All of this is on from the first request. You don't configure anything.</p>
            </article>

            <div class="k-stack k-span-7">
                <article class="k-panel">
                    <h3>Secrets stay in .env</h3>
                    <p>Database and mail settings live in one git-ignored file.</p>
                    <?= $code(<<<'CODE'
APP_ENV=production
DB_DATABASE=kyaaaa_db
DB_PASSWORD=
MAIL_HOST=smtp.example.com
CODE, 'env', '.env') ?>
                </article>

                <article class="k-panel">
                    <h3>Email in five lines</h3>
                    <p>SMTP over verified TLS, with attachments and plain-text fallback.</p>
                    <?= $code(<<<'CODE'
$mail = (new \Core\Conf\Email())->start();
$mail->setFrom('hello@example.com', 'My App');
$mail->addTo($email);
$mail->setSubject('Welcome');
$mail->setBody('<p>Glad you are here.</p>');
$mail->send();
CODE, 'php', 'a controller') ?>
                </article>
            </div>
        </div>
    </div>
</section>

<section class="k-section">
    <div class="k-wrap">
        <h2>Your first page, in three steps</h2>
        <p class="k-section-lead">A route points to a controller, the controller picks a view. Here is a greeting page at <code>/hello/Ayu</code>.</p>

        <ol class="k-steps">
            <li class="k-step">
                <span class="k-step-num" aria-hidden="true">1</span>
                <div>
                    <h3>Add a route</h3>
                    <?= $code(<<<'CODE'
$router->get('/hello/:name', [\Core\Controllers\HelloCtrl::class, 'greet']);
CODE, 'php', 'core/Conf/Routes.php') ?>
                </div>
            </li>
            <li class="k-step">
                <span class="k-step-num" aria-hidden="true">2</span>
                <div>
                    <h3>Write the controller</h3>
                    <?= $code(<<<'CODE'
namespace Core\Controllers;

class HelloCtrl
{
    public function greet(string $name): void
    {
        view('hello', ['title' => 'Hello', 'name' => $name]);
    }
}
CODE, 'php', 'core/Controllers/HelloCtrl.php') ?>
                </div>
            </li>
            <li class="k-step">
                <span class="k-step-num" aria-hidden="true">3</span>
                <div>
                    <h3>Create the view</h3>
                    <?= $code(<<<'CODE'
<div class="k-wrap">
    <h1>Hello, <?= esc($name) ?>!</h1>
</div>
CODE, 'php', 'core/Views/hello.kyaaaa.php') ?>
                </div>
            </li>
        </ol>

        <div class="k-actions">
            <a class="k-btn k-btn-primary" href="<?= esc(url('docs')) ?>">Continue in the docs</a>
        </div>
    </div>
</section>
