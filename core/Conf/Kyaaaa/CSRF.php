<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use RuntimeException;

class CSRF
{
    private const HEADER = 'HTTP_X_CSRF_TOKEN';

    private static int $tokenExpiry = 7200;
    private static string $fieldName = 'kyaaaaaaaKyaaa_csrf';

    public function __construct()
    {
        $this->init();
    }

    public function setExpiry(int $expiry): void
    {
        self::$tokenExpiry = max(1, $expiry);
    }

    public function setTokenName(string $tokenName): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tokenName) !== 1) {
            throw new \InvalidArgumentException('Invalid CSRF field name.');
        }

        self::$fieldName = $tokenName;
    }

    /**
     * @deprecated The token is now random (random_bytes), a secret key is no
     *             longer needed. Kept so old code does not break.
     */
    public function setTokenKey(string $key): void
    {
    }

    public function getTokenName(): string
    {
        return self::$fieldName;
    }

    public function token(): string
    {
        return (string) $_SESSION['csrf']['token'];
    }

    /**
     * Valid when the token comes from the POST field or the X-CSRF-Token header,
     * has not expired and (if the browser sends an Origin header) the request
     * comes from our own site.
     */
    public function isValidRequest(): bool
    {
        $sent = $_POST[self::$fieldName] ?? ($_SERVER[self::HEADER] ?? null);
        $stored = $_SESSION['csrf']['token'] ?? null;
        $expiry = (int) ($_SESSION['csrf']['expiry'] ?? 0);

        if (!is_string($sent) || $sent === '' || !is_string($stored) || $stored === '') {
            return false;
        }

        if ($expiry < time() || !hash_equals($stored, $sent)) {
            return false;
        }

        return Security::originAllowed();
    }

    /** The hidden <input> as a string. */
    public function field(): string
    {
        return '<input type="hidden" name="' . htmlspecialchars(self::$fieldName, ENT_QUOTES, 'UTF-8')
            . '" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /** Echo the hidden <input> (kept for backwards compatibility). */
    public function tokenField(): void
    {
        echo $this->field();
    }

    /** <meta> tag for AJAX: send its content in the X-CSRF-Token header. */
    public function metaTag(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    private function init(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('CSRF protection needs an active session.');
        }

        $csrf = $_SESSION['csrf'] ?? null;

        if (
            !is_array($csrf)
            || !isset($csrf['token'])
            || !is_string($csrf['token'])
            || $csrf['token'] === ''
            || (int) ($csrf['expiry'] ?? 0) < time()
        ) {
            $this->generateToken();
        }
    }

    protected function generateToken(): void
    {
        $_SESSION['csrf'] = [
            'token' => bin2hex(random_bytes(32)),
            'expiry' => time() + self::$tokenExpiry,
        ];
    }
}
