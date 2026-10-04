<?php

declare(strict_types=1);

namespace Kaneas\Services;

/**
 * Minimal SMTP client (STARTTLS / SSL, AUTH LOGIN), so no Composer packages
 * are needed on shared hosting. Sends multipart text + HTML mails.
 */
final class Mailer
{
    private const TIMEOUT = 15;

    /** @var resource|null */
    private $socket = null;

    public function __construct(private readonly array $config)
    {
    }

    public static function fromSettings(): self
    {
        return new self(Settings::mail());
    }

    public function isConfigured(): bool
    {
        return $this->config['enabled']
            && $this->config['host'] !== ''
            && filter_var($this->config['from_address'], FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Sends without throwing; returns false (and logs) on failure.
     * Mail is a nice-to-have here: an invitation must still succeed if SMTP is down.
     */
    public function trySend(string $to, string $subject, string $text, ?string $html = null): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        try {
            $this->send($to, $subject, $text, $html);
            return true;
        } catch (\Throwable $e) {
            error_log('[kaneas] mail failed: ' . $e->getMessage());
            return false;
        }
    }

    /** @throws \RuntimeException */
    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Invalid recipient address.');
        }
        $from = (string) $this->config['from_address'];

        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            $this->command($this->buildMessage($from, $to, $subject, $text, $html) . "\r\n.", [250]);
            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $encryption = $this->config['encryption'];
        $host = (string) $this->config['host'];
        $port = (int) $this->config['port'];
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ]]);
        $socket = @stream_socket_client($remote, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("Could not connect to $host:$port ($errstr)");
        }
        stream_set_timeout($socket, self::TIMEOUT);
        $this->socket = $socket;

        $this->expect([220]);
        $ehlo = 'EHLO ' . $this->localHostname();
        $this->command($ehlo, [250]);

        if ($encryption === 'tls') {
            $this->command('STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!stream_socket_enable_crypto($this->socket, true, $crypto)) {
                throw new \RuntimeException('STARTTLS negotiation failed.');
            }
            $this->command($ehlo, [250]);
        }

        if ($this->config['username'] !== '') {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode((string) $this->config['username']), [334]);
            $this->command(base64_encode((string) $this->config['password']), [235], 'AUTH password');
        }
    }

    private function buildMessage(string $from, string $to, string $subject, string $text, ?string $html): string
    {
        $fromName = $this->clean((string) $this->config['from_name']);
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . ($fromName !== '' ? $this->encodeName($fromName) . ' ' : '') . '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($this->clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
        ];

        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($text));
        } else {
            $boundary = 'kaneas_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($text))
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html))
                . '--' . $boundary . '--';
        }

        // Base64 bodies never start a line with ".", so no dot-stuffing is needed.
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim($body, "\r\n");
    }

    private function command(string $line, array $expected, ?string $label = null): string
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new \RuntimeException('Could not write to SMTP server.');
        }
        return $this->expect($expected, $label ?? strtok($line, ' :'));
    }

    private function expect(array $codes, string $context = 'greeting'): string
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException(sprintf('SMTP %s failed: %s', $context, trim($response) ?: 'no response'));
        }
        return $response;
    }

    private function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    /** Unstructured header text (Subject): plain ASCII as-is, otherwise RFC 2047 encoded. */
    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
    }

    /** Display name in an address header (From): must be quoted when it is plain ASCII. */
    private function encodeName(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? $this->encodeHeader($value) : '"' . addcslashes($value, '"\\') . '"';
    }

    private function localHostname(): string
    {
        $host = $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';
        return preg_replace('/[^A-Za-z0-9.\-]/', '', (string) $host) ?: 'localhost';
    }
}
