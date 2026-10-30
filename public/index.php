<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', __DIR__);

$autoload = ROOT_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Dependencies are missing. Run "composer install".');
}

require $autoload;

(new \Core\Conf\Routes())->kyaaaaRun();
