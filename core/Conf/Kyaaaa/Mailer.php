<?php

declare(strict_types=1);

namespace Core\Conf\Kyaaaa;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Minimal SMTP client.
 *
 * Hardening compared to the old version: TLS certificates are verified, STARTTLS
 * failures abort instead of silently continuing in plain text, credentials are
 * never sent over an unencrypted connection, header values are stripped of line
 * breaks, the body is dot-stuffed (no SMTP command injection), Bcc recipients
 * are not exposed in the headers and attachments can be limited to a directory.
 */
class Mailer
{
    public const NL = "\r\n";

    private string $host;
    private int $port;
    private string $secure;
    private string $username;
    private string $password;

    /** @var array{0: string, 1: string}|array{} */
    private array $from = [];

    /** @var list<array{0: string, 1: string}> */
    private array $to = [];

    /** @var list<array{0: string, 1: string}> */
    private array $cc = [];

    /** @var list<array{0: string, 1: string}> */
    private array $bcc = [];

    /** @var array<string, array{0: string, 1: string}> */
    private array $replyTo = [];

    /** @var array<string, bool> */
    private array $recipients = [];

    /** @var list<string> */
    private array $files = [];

    private ?string $attachmentBase = null;
    private string $charset = 'utf-8';
    private string $subject = '';
    private string $body = '';
    private string $text = '';

    /** @var resource|null */
    private $connection = null;

    private int $timeout = 30;
    private bool $encrypted = false;

    /** @var array<string, true|list<string>> */
    private array $extensions = [];

    private string $hostname = '';
    private bool $debug = false;
    private string $lastError = '';

    public function __construct(string $host = 'localhost', int|string $port = 25, string $secure = '', string $username = '', string $password = '')
    {
        $secure = strtolower($secure);
        if (!in_array($secure, ['', 'ssl', 'tls'], true)) {
            throw new InvalidArgumentException('Mailer security must be "", "ssl" or "tls".');
        }

        $this->host = $host;
        $this->port = (int) $port;
        $this->secure = $secure;
        $this->username = $username;
        $this->password = $password;
        $this->debug = PHP_SAPI === 'cli';
    }

    public function __destruct()
    {
        $this->close();
    }

    public function setFrom(string $address, string $name = ''): void
    {
        $address = $this->valid($address);
        if ($address !== false) {
            $this->from = [$address, self::stripnl($name)];
        }
    }

    public function addReplyTo(string $address, string $name = ''): void
    {
        $address = $this->valid($address);
        if ($address !== false && !isset($this->replyTo[$address])) {
            $this->replyTo[$address] = [$address, self::stripnl($name)];
        }
    }

    public function setTo(string $address, string $name = ''): void
    {
        $this->addTo($address, $name);
    }

    public function addTo(string $address, string $name = ''): void
    {
        $address = $this->valid($address);
        if ($address !== false && !isset($this->recipients[$address])) {
            $this->to[] = [$address, self::stripnl($name)];
            $this->recipients[$address] = true;
        }
    }

    public function addCc(string $address, string $name = ''): void
    {
        $address = $this->valid($address);
        if ($address !== false && !isset($this->recipients[$address])) {
            $this->cc[] = [$address, self::stripnl($name)];
            $this->recipients[$address] = true;
        }
    }

    public function addBcc(string $address, string $name = ''): void
    {
        $address = $this->valid($address);
        if ($address !== false && !isset($this->recipients[$address])) {
            $this->bcc[] = [$address, self::stripnl($name)];
            $this->recipients[$address] = true;
        }
    }

    /**
     * Only attach files located inside $directory. Strongly recommended when
     * a file name can ever be influenced by a visitor.
     */
    public function allowAttachmentsFrom(string $directory): void
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException('Attachment directory does not exist.');
        }

        $this->attachmentBase = rtrim($real, '/\\');
    }

    public function addFile(string $file): void
    {
        $real = realpath($file);
        if ($real === false || !is_file($real) || !is_readable($real) || filesize($real) > 20 * 1048576) {
            return;
        }

        if ($this->attachmentBase !== null && !str_starts_with($real, $this->attachmentBase . DIRECTORY_SEPARATOR)) {
            return;
        }

        $this->files[] = $real;
    }

    public function setSubject(string $subject = ''): void
    {
        $this->subject = $subject;
    }

    public function setBody(string $body = ''): void
    {
        $this->body = $body;
    }

    /** Plain text alternative. Generated from the HTML body when not set. */
    public function setText(string $text = ''): void
    {
        $this->text = $text;
    }

    public function setTimeout(int $seconds = 30): void
    {
        $this->timeout = max(1, $seconds);
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function send(): bool
    {
        $this->lastError = '';

        try {
            $this->init();
            $this->connect();
            $this->auth();

            $this->request('MAIL FROM:<' . $this->from[0] . '>', [250]);
            foreach (array_keys($this->recipients) as $address) {
                $this->request('RCPT TO:<' . $address . '>', [250, 251]);
            }

            $this->request('DATA', [354]);
            $this->sendData(self::dotStuff($this->createMessage()));
            $this->request('QUIT', [221]);
            $this->close();

            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->debug('ERROR: ' . $e->getMessage());
            error_log('[kyaaaa] Mailer: ' . str_replace(["\r", "\n"], ' ', $e->getMessage()));
            $this->close();

            return false;
        }
    }

    public function createMessage(): string
    {
        return $this->createHeader() . self::NL . $this->createContent();
    }

    /**
     * Normalise line endings to CRLF and double a leading dot on every line so
     * the body can never end the DATA command early.
     */
    public static function dotStuff(string $message): string
    {
        $message = (string) preg_replace('/\r\n|\r|\n/', self::NL, $message);

        return (string) preg_replace('/(^|\r\n)\./', '$1..', $message);
    }

    public static function stripnl(string $str): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $str));
    }

    public function html2text(string $html): string
    {
        $html = preg_replace('~<(head|title|style|script)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<br\s*/?>|</p>|</div>|</li>|</tr>~i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    // ------------------------------------------------------------------
    // Message
    // ------------------------------------------------------------------

    private function createHeader(): string
    {
        $headers = [
            'Date: ' . date('r'),
            'To: ' . $this->concatAddress($this->to),
            'From: ' . $this->formatAddress($this->from),
        ];

        if ($this->cc !== []) {
            $headers[] = 'Cc: ' . $this->concatAddress($this->cc);
        }

        // Bcc recipients are only used for RCPT TO, never written to a header.

        if ($this->replyTo !== []) {
            $headers[] = 'Reply-To: ' . $this->concatAddress(array_values($this->replyTo));
        }

        $headers[] = 'Subject: ' . $this->encodeHeader($this->subject);
        $headers[] = 'Message-ID: ' . $this->generateMessageId();
        $headers[] = 'MIME-Version: 1.0';

        return implode(self::NL, $headers);
    }

    private function createContent(): string
    {
        $alternative = 'kyaaaa_a_' . bin2hex(random_bytes(12));
        $text = $this->text !== '' ? $this->text : $this->html2text($this->body);

        if ($this->files === []) {
            $lines = [
                'Content-Type: multipart/alternative; boundary="' . $alternative . '"',
                '',
                'This is a multi-part message in MIME format.',
            ];

            return implode(self::NL, array_merge($lines, $this->alternativeParts($alternative, $text)));
        }

        $mixed = 'kyaaaa_m_' . bin2hex(random_bytes(12));
        $lines = [
            'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
            '',
            'This is a multi-part message in MIME format.',
            '--' . $mixed,
            'Content-Type: multipart/alternative; boundary="' . $alternative . '"',
            '',
        ];
        $lines = array_merge($lines, $this->alternativeParts($alternative, $text));

        foreach ($this->files as $file) {
            $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($file));
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $lines[] = '--' . $mixed;
            $lines[] = 'Content-Type: application/octet-stream; name="' . $name . '"';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = 'Content-Disposition: attachment; filename="' . $name . '"';
            $lines[] = '';
            $lines[] = $this->encodeBase64($content);
        }

        $lines[] = '--' . $mixed . '--';

        return implode(self::NL, $lines);
    }

    /**
     * @return list<string>
     */
    private function alternativeParts(string $boundary, string $text): array
    {
        return [
            '--' . $boundary,
            'Content-Type: text/plain; charset="' . $this->charset . '"',
            'Content-Transfer-Encoding: base64',
            '',
            $this->encodeBase64($text),
            '--' . $boundary,
            'Content-Type: text/html; charset="' . $this->charset . '"',
            'Content-Transfer-Encoding: base64',
            '',
            $this->encodeBase64($this->body),
            '--' . $boundary . '--',
        ];
    }

    private function encodeBase64(string $data): string
    {
        return rtrim(chunk_split(base64_encode($data), 76, self::NL));
    }

    private function encodeHeader(string $value): string
    {
        $value = self::stripnl($value);

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return substr($value, 0, 900);
        }

        return mb_encode_mimeheader($value, 'UTF-8', 'B', self::NL);
    }

    /**
     * @param array{0: string, 1: string}|array{} $address
     */
    private function formatAddress(array $address): string
    {
        if ($address === []) {
            return '';
        }

        [$email, $name] = $address;
        if ($name === '') {
            return $email;
        }

        $encoded = $this->encodeHeader($name);
        if (!str_starts_with($encoded, '=?')) {
            $encoded = '"' . addcslashes($encoded, "\"\\") . '"';
        }

        return $encoded . ' <' . $email . '>';
    }

    /**
     * @param list<array{0: string, 1: string}> $addresses
     */
    private function concatAddress(array $addresses): string
    {
        $list = [];
        foreach ($addresses as $address) {
            $list[] = $this->formatAddress($address);
        }

        return implode(', ', $list);
    }

    private function generateMessageId(): string
    {
        $host = (string) preg_replace('/[^A-Za-z0-9.-]/', '', $this->ehloName());

        return '<' . bin2hex(random_bytes(16)) . '@' . ($host !== '' ? $host : 'localhost') . '>';
    }

    private function valid(string $address): string|false
    {
        $address = strtolower(trim($address));

        if (preg_match('/[\r\n\0<>,;"\s]/', $address) === 1) {
            $this->debug('Invalid address rejected');

            return false;
        }

        $valid = filter_var($address, FILTER_VALIDATE_EMAIL);
        if ($valid === false) {
            $this->debug('Invalid address rejected');

            return false;
        }

        return $valid;
    }

    // ------------------------------------------------------------------
    // SMTP
    // ------------------------------------------------------------------

    private function init(): void
    {
        if ($this->to === []) {
            throw new RuntimeException('A valid email address to send to is required.');
        }

        if (strlen(trim($this->body)) < 3) {
            throw new RuntimeException('Message body is empty.');
        }

        if ($this->from === []) {
            $from = $this->valid($this->username);
            if ($from === false) {
                throw new RuntimeException('A valid sender address is required (setFrom).');
            }
            $this->from = [$from, ''];
        }
    }

    private function remoteHost(): string
    {
        $host = (string) preg_replace('~^[a-z]+://~i', '', $this->host);

        if (!str_starts_with($host, '[')) {
            $host = (string) preg_replace('/:\d+$/', '', $host);
        }

        if (preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])$/', $host) !== 1) {
            throw new RuntimeException('Invalid SMTP host.');
        }

        return $host;
    }

    private function ehloName(): string
    {
        if ($this->hostname === '') {
            $name = gethostname();
            $name = is_string($name) ? (string) preg_replace('/[^A-Za-z0-9.-]/', '', $name) : '';
            $this->hostname = $name !== '' ? $name : 'localhost';
        }

        return $this->hostname;
    }

    public function connect(): bool
    {
        $host = $this->remoteHost();
        $this->debug("Connecting to {$host}:{$this->port}");

        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => trim($host, '[]'),
                'SNI_enabled' => true,
                'crypto_method' => $crypto,
            ],
        ]);

        $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $this->port;
        $connection = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if ($connection === false) {
            throw new RuntimeException("Unable to connect to the SMTP server ({$errno}): {$errstr}");
        }

        stream_set_timeout($connection, $this->timeout);
        $this->connection = $connection;
        $this->encrypted = $this->secure === 'ssl';

        $this->readResponse([220]);
        $this->request('EHLO ' . $this->ehloName(), [250]);

        if ($this->secure !== 'ssl') {
            if (isset($this->extensions['STARTTLS'])) {
                $this->request('STARTTLS', [220]);

                if (stream_socket_enable_crypto($connection, true, $crypto) !== true) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }

                $this->encrypted = true;
                $this->extensions = [];
                $this->request('EHLO ' . $this->ehloName(), [250]);
            } elseif ($this->secure === 'tls') {
                throw new RuntimeException('The SMTP server does not support STARTTLS.');
            }
        }

        return true;
    }

    private function auth(): void
    {
        if ($this->username === '' || $this->password === '' || !isset($this->extensions['AUTH'])) {
            return;
        }

        if (!$this->encrypted && !in_array(strtolower($this->remoteHost()), ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw new RuntimeException('Refusing to send SMTP credentials over an unencrypted connection.');
        }

        $mechanisms = is_array($this->extensions['AUTH']) ? $this->extensions['AUTH'] : [];

        if (in_array('PLAIN', $mechanisms, true)) {
            $this->request('AUTH PLAIN', [334]);
            $this->request(base64_encode("\0" . $this->username . "\0" . $this->password), [235], '[credentials hidden]');
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            $this->request('AUTH LOGIN', [334]);
            $this->request(base64_encode($this->username), [334], '[username hidden]');
            $this->request(base64_encode($this->password), [235], '[password hidden]');
        } else {
            throw new RuntimeException('No supported SMTP authentication method (PLAIN or LOGIN).');
        }
    }

    /**
     * @param list<int> $expected
     */
    private function request(string $command, array $expected, ?string $log = null): string
    {
        if (preg_match('/[\r\n]/', $command) === 1) {
            throw new RuntimeException('Line break in SMTP command.');
        }

        $this->debug('REQUEST: ' . ($log ?? $command));
        $this->write($command . self::NL);

        $response = $this->readResponse($expected);

        if (str_starts_with($command, 'EHLO')) {
            $this->parseEhlo($response);
        }

        return $response;
    }

    private function sendData(string $message): void
    {
        $this->debug('REQUEST: [message body]');
        $this->write($message . self::NL . '.' . self::NL);
        $this->readResponse([250]);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->connection) || fwrite($this->connection, $data) === false) {
            throw new RuntimeException('Unable to write to the SMTP connection.');
        }
    }

    /**
     * @param list<int> $expected
     */
    private function readResponse(array $expected): string
    {
        if (!is_resource($this->connection)) {
            throw new RuntimeException('SMTP connection is closed.');
        }

        $response = '';
        for ($i = 0; $i < 100; $i++) {
            $line = fgets($this->connection, 1024);

            if ($line === false) {
                $meta = stream_get_meta_data($this->connection);
                throw new RuntimeException($meta['timed_out'] ? 'SMTP connection timed out.' : 'SMTP connection closed unexpectedly.');
            }

            $response .= $line;

            // "250-..." continues, "250 ..." is the last line of the reply
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $this->debug('RESPONSE: ' . $response);

        if (!in_array((int) substr($response, 0, 3), $expected, true)) {
            throw new RuntimeException(trim($response));
        }

        return $response;
    }

    private function parseEhlo(string $response): void
    {
        $this->extensions = [];

        $lines = preg_split('/\r\n|\r|\n/', trim($response));
        if ($lines === false) {
            return;
        }

        array_shift($lines); // greeting

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim(substr($line, 4)));
            if ($parts === false || $parts[0] === '') {
                continue;
            }

            $name = strtoupper((string) array_shift($parts));
            $this->extensions[$name] = $parts === [] ? true : array_map('strtoupper', $parts);
        }
    }

    private function close(): void
    {
        if (is_resource($this->connection)) {
            fclose($this->connection);
            $this->debug('Connection closed');
        }

        $this->connection = null;
        $this->extensions = [];
        $this->encrypted = false;
    }

    private function debug(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        $message = (string) preg_replace('/\r\n|\r/', "\n", $message);
        echo '[', date('Y-m-d H:i:s'), '] ', trim(str_replace("\n", "\n\t\t\t\t", trim($message))), "\n";
    }
}
