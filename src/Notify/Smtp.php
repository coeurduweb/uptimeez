<?php
declare(strict_types=1);

namespace Uptimeez\Notify;

use Uptimeez\Config;

/**
 * Client SMTP minimal (fsockopen) : AUTH LOGIN / PLAIN, STARTTLS ou SSL direct.
 * Utile quand mail() est bridée ou finit en spam.
 */
final class Smtp
{
    public static function send(array $recipients, string $from, string $fromName, string $subject,
                                string $html, string $text): array
    {
        // Démonstration publique : rien ne sort. Le verrou est ici, dans
        // l'expéditeur, et pas seulement dans la configuration : un visiteur
        // atteint l'écran des réglages.
        if ($q = \Uptimeez\Demo::silenced()) return $q;
        $c      = Config::get('notify.mail.smtp', []);
        $host   = trim((string)($c['host'] ?? ''));
        $port   = (int)($c['port'] ?? 587);
        $secure = strtolower((string)($c['secure'] ?? 'tls'));
        if ($host === '') return ['ok' => false, 'info' => t('Hôte SMTP non renseigné')];

        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15);
        if (!$fp) return ['ok' => false, 'info' => t('Connexion SMTP impossible : {detail}',
                                                     ['detail' => (string)($errstr ?: $errno)])];
        stream_set_timeout($fp, 15);

        $log = [];
        $read = function () use ($fp, &$log): string {
            $out = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') break;
            }
            $log[] = '< ' . trim($out);
            return $out;
        };
        $write = function (string $cmd, bool $secret = false) use ($fp, &$log): void {
            fwrite($fp, $cmd . "\r\n");
            $log[] = '> ' . ($secret ? '***' : $cmd);
        };
        $expect = function (string $resp, string $codes) use (&$log): bool {
            $code = substr(trim($resp), 0, 3);
            return in_array($code, explode(',', $codes), true);
        };

        $fail = function (string $why) use ($fp, &$log): array {
            @fclose($fp);
            return ['ok' => false, 'info' => $why . ' | ' . str_cut(implode(' ', array_slice($log, -4)), 300)];
        };

        if (!$expect($read(), '220')) return $fail(t('Bannière SMTP inattendue'));
        $ehlo = 'uptimeez.' . (parse_url((string)Config::get('app.base_url', ''), PHP_URL_HOST) ?: 'localhost');
        $write('EHLO ' . $ehlo);
        $caps = $read();
        if (!$expect($caps, '250')) return $fail(t('EHLO refusé'));

        if ($secure === 'tls') {
            $write('STARTTLS');
            if (!$expect($read(), '220')) return $fail(t('STARTTLS refusé'));
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return $fail(t('Chiffrement TLS impossible'));
            }
            $write('EHLO ' . $ehlo);
            $caps = $read();
        }

        $user = (string)($c['user'] ?? ''); $pass = (string)($c['pass'] ?? '');
        if ($user !== '') {
            if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') !== false) {
                $write('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), true);
                if (!$expect($read(), '235')) return $fail(t('Authentification SMTP refusée'));
            } else {
                $write('AUTH LOGIN');
                if (!$expect($read(), '334')) return $fail(t('AUTH LOGIN refusé'));
                $write(base64_encode($user), true);
                if (!$expect($read(), '334')) return $fail(t('Identifiant SMTP refusé'));
                $write(base64_encode($pass), true);
                if (!$expect($read(), '235')) return $fail(t('Mot de passe SMTP refusé'));
            }
        }

        $write('MAIL FROM:<' . $from . '>');
        if (!$expect($read(), '250')) return $fail(t('MAIL FROM refusé'));
        foreach ($recipients as $rcpt) {
            $write('RCPT TO:<' . $rcpt . '>');
            if (!$expect($read(), '250,251')) return $fail(t('Destinataire refusé : {mail}', ['mail' => $rcpt]));
        }
        $write('DATA');
        if (!$expect($read(), '354')) return $fail(t('DATA refusé'));

        $boundary = 'uptimeez' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . (preg_match('~[^\x20-\x7E]~', $fromName)
                ? '=?UTF-8?B?' . base64_encode($fromName) . '?=' : '"' . addslashes($fromName) . '"') . ' <' . $from . '>',
            'To: ' . implode(', ', $recipients),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehlo . '>',
            'MIME-Version: 1.0',
            'X-Mailer: Uptimeez',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--{$boundary}--\r\n";
        // Protection du point isolé en début de ligne
        $body = preg_replace('~^\.~m', '..', $body) ?? $body;

        fwrite($fp, $body . "\r\n.\r\n");
        $final = $read();
        $write('QUIT');
        @fclose($fp);

        $ok = $expect($final, '250');
        return ['ok' => $ok, 'info' => $ok
            ? t('Accepté par {host}', ['host' => $host])
            : t('Refusé : {reason}', ['reason' => str_cut(trim($final), 200)])];
    }
}
