<?php
declare(strict_types=1);

class SmtpException extends RuntimeException {}

/**
 * Small, dependency-free SMTP client.
 *
 * Supports STARTTLS (port 587), implicit TLS (port 465) and plaintext,
 * with AUTH LOGIN / AUTH PLAIN chosen from the server's EHLO advertisement.
 * Written because this project has no Composer; it covers what outbound
 * transactional mail needs and nothing more.
 */
final class Smtp
{
    private $socket = null;
    private array $extensions = [];
    private array $log = [];

    public function __construct(
        private string $host,
        private int $port = 587,
        private string $secure = 'tls',   // tls | ssl | none
        private ?string $username = null,
        private ?string $password = null,
        private int $timeout = 20,
    ) {}

    public static function fromSettings(): self
    {
        return new self(
            Settings::get('smtp_host'),
            (int) Settings::get('smtp_port', '587'),
            Settings::get('smtp_secure', 'tls'),
            Settings::get('smtp_user') ?: null,
            Settings::get('smtp_pass') ?: null,
            (int) Settings::get('smtp_timeout', '20'),
        );
    }

    /** Transcript of the session, with AUTH payloads masked. Useful for the test button. */
    public function transcript(): string
    {
        return implode("\n", $this->log);
    }

    public function connect(): void
    {
        if ($this->host === '') {
            throw new SmtpException('SMTP host is not configured.');
        }

        $transport = $this->secure === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => ['SNI_enabled' => true, 'peer_name' => $this->host],
        ]);

        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new SmtpException(sprintf(
                'Could not connect to %s:%d (%s). %s',
                $this->host,
                $this->port,
                $errstr !== '' ? $errstr : 'no detail',
                $errno === 0 ? 'Check the host name and that outbound SMTP is not firewalled.' : "errno $errno"
            ));
        }

        stream_set_timeout($this->socket, $this->timeout);
        $this->expect([220], 'greeting');
        $this->ehlo();

        if ($this->secure === 'tls') {
            $this->startTls();
        }

        if ($this->username !== null && $this->username !== '') {
            $this->authenticate();
        }
    }

    private function ehlo(): void
    {
        $hostname = gethostname() ?: 'localhost';
        $response = $this->command("EHLO $hostname", [250], 'EHLO');

        $this->extensions = [];
        foreach (explode("\n", $response) as $line) {
            // Lines look like "250-AUTH LOGIN PLAIN" or "250 STARTTLS".
            $line = trim(substr($line, 4));
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/[ =]/', $line);
            $name = strtoupper((string) array_shift($parts));
            $this->extensions[$name] = array_map('strtoupper', $parts);
        }
    }

    private function startTls(): void
    {
        if (!isset($this->extensions['STARTTLS'])) {
            throw new SmtpException(
                'Server does not advertise STARTTLS. Try security "ssl" on port 465, or "none".'
            );
        }
        $this->command('STARTTLS', [220], 'STARTTLS');

        $ok = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );
        if ($ok !== true) {
            throw new SmtpException('TLS negotiation failed. The server certificate may be invalid.');
        }

        // RFC 3207: re-issue EHLO over the encrypted channel.
        $this->ehlo();
    }

    private function authenticate(): void
    {
        $methods = $this->extensions['AUTH'] ?? [];

        if (in_array('LOGIN', $methods, true) || $methods === []) {
            $this->command('AUTH LOGIN', [334], 'AUTH');
            $this->command(base64_encode((string) $this->username), [334], 'AUTH username', true);
            $this->command(base64_encode((string) $this->password), [235], 'AUTH password', true);
            return;
        }

        if (in_array('PLAIN', $methods, true)) {
            $payload = base64_encode("\0" . $this->username . "\0" . $this->password);
            $this->command('AUTH PLAIN ' . $payload, [235], 'AUTH PLAIN', true);
            return;
        }

        throw new SmtpException(
            'No supported auth method. Server offers: ' . (implode(', ', $methods) ?: 'none')
        );
    }

    /**
     * @param array<int,string> $to
     */
    public function send(string $from, array $to, string $rawMessage): void
    {
        $this->command("MAIL FROM:<$from>", [250], 'MAIL FROM');

        foreach ($to as $recipient) {
            $this->command("RCPT TO:<$recipient>", [250, 251], 'RCPT TO');
        }

        $this->command('DATA', [354], 'DATA');

        // Dot-stuffing: a line consisting of a single "." would end the message.
        $body = preg_replace('/^\./m', '..', $rawMessage);
        $this->write($body . "\r\n.\r\n");
        $this->expect([250], 'message body');
    }

    public function quit(): void
    {
        if ($this->socket) {
            try {
                $this->command('QUIT', [221], 'QUIT');
            } catch (SmtpException) {
                // Server hung up first; nothing useful to do.
            }
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function command(string $line, array $expected, string $label, bool $mask = false): string
    {
        $this->log[] = '> ' . ($mask ? '[redacted]' : $line);
        $this->write($line . "\r\n");
        return $this->expect($expected, $label);
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data) === false) {
            throw new SmtpException('Connection lost while writing to the SMTP server.');
        }
    }

    private function expect(array $codes, string $label): string
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-"; the final line uses "250 ".
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $meta = stream_get_meta_data($this->socket);
        if (!empty($meta['timed_out'])) {
            throw new SmtpException("SMTP timed out waiting for $label.");
        }
        if ($response === '') {
            throw new SmtpException("SMTP server closed the connection during $label.");
        }

        $this->log[] = '< ' . trim($response);
        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $codes, true)) {
            throw new SmtpException(sprintf('SMTP %s failed: %s', $label, trim($response)));
        }

        return $response;
    }
}
