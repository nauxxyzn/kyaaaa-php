<?php

declare(strict_types=1);

namespace Core\Conf;

use Core\Conf\Kyaaaa\Env;
use Core\Conf\Kyaaaa\Mailer;

class Email
{
    /**
     * Email config. Values come from .env (MAIL_HOST, MAIL_PORT, MAIL_CRYPTO
     * ["ssl", "tls" or empty], MAIL_USERNAME, MAIL_PASSWORD).
     */
    public function start(): Mailer
    {
        return new Mailer(
            (string) Env::get('MAIL_HOST', 'smtp.gmail.com'),
            (int) Env::get('MAIL_PORT', '465'),
            (string) Env::get('MAIL_CRYPTO', 'ssl'),
            (string) Env::get('MAIL_USERNAME', ''),
            (string) Env::get('MAIL_PASSWORD', '')
        );
    }
}
