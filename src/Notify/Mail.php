<?php
declare(strict_types=1);

namespace Uptimer\Notify;

use Uptimer\Config;

final class Mail
{
    public static function send(string $title, array $lines, string $sev, array $mon): array
    {
        $to = trim((string)Config::get('notify.mail.to', ''));
        if ($to === '') return ['ok' => false, 'info' => t('Aucun destinataire configuré')];

        $recipients = array_values(array_filter(array_map('trim', preg_split('~[,;]~', $to) ?: [])));
        if (!$recipients) return ['ok' => false, 'info' => t('Destinataire invalide')];

        $from     = trim((string)Config::get('notify.mail.from', '')) ?: ('uptimer@' . (gethostname() ?: 'localhost'));
        $fromName = trim((string)Config::get('notify.mail.from_name', 'Uptimer'));
        $subject  = self::subjectPrefix($sev) . ' ' . strip_tags($title);
        [$html, $text] = self::body($title, $lines, $sev, $mon);

        $transport = Config::get('notify.mail.transport', 'mail');
        if ($transport === 'smtp') {
            return Smtp::send($recipients, $from, $fromName, $subject, $html, $text);
        }

        $boundary = 'uptimer' . bin2hex(random_bytes(8));
        $headers  = [
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Uptimer',
            'X-Priority: ' . ($sev === 'down' ? '1' : '3'),
        ];
        $msg = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
             . $text . "\r\n\r\n"
             . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
             . $html . "\r\n\r\n--{$boundary}--";

        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = @mail(implode(',', $recipients), $subjectEnc, $msg, implode("\r\n", $headers), '-f' . $from);
        return ['ok' => (bool)$ok,
                'info' => $ok ? t('mail() acceptée par le serveur')
                              : t('mail() a échoué, voir les journaux PHP')];
    }

    /**
     * Envoi d'un document : sujet libre, destinataires libres, corps déjà composé.
     *
     * Un rapport mensuel n'est pas une alerte : pas de préfixe [ALERTE], pas de
     * priorité haute, et des destinataires qui sont ceux du client concerné et
     * non ceux de l'astreinte.
     */
    public static function sendDocument(array $to, string $subject, string $html, string $text): array
    {
        $recipients = [];
        foreach ($to as $mail) {
            $mail = trim((string)$mail);
            if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) $recipients[$mail] = true;
        }
        $recipients = array_keys($recipients);
        if (!$recipients) return ['ok' => false, 'info' => t('Aucun destinataire configuré')];
        if (!Config::get('notify.mail.enabled', false)) {
            return ['ok' => false, 'info' => t('Le canal e-mail est désactivé dans les réglages.')];
        }

        $from     = trim((string)Config::get('notify.mail.from', '')) ?: ('uptimer@' . (gethostname() ?: 'localhost'));
        $fromName = trim((string)Config::get('notify.mail.from_name', 'Uptimer'));

        if (Config::get('notify.mail.transport', 'mail') === 'smtp') {
            return Smtp::send($recipients, $from, $fromName, $subject, $html, $text);
        }

        $boundary = 'uptimer' . bin2hex(random_bytes(8));
        $headers  = [
            'From: ' . self::encodeName($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Uptimer',
            'Auto-Submitted: auto-generated',
        ];
        $msg = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
             . $text . "\r\n\r\n"
             . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
             . $html . "\r\n\r\n--{$boundary}--";
        $ok = @mail(implode(',', $recipients), '=?UTF-8?B?' . base64_encode($subject) . '?=',
                    $msg, implode("\r\n", $headers), '-f' . $from);
        return ['ok' => (bool)$ok,
                'info' => $ok ? t('Rapport remis au serveur de messagerie ({n} destinataire(s))',
                                  ['n' => count($recipients)])
                              : t('mail() a échoué (voir les journaux PHP)')];
    }

    private static function subjectPrefix(string $sev): string
    {
        return match ($sev) {
            'down'     => '[ALERTE]',
            'degraded' => '[VIGILANCE]',
            'up'       => t('[RÉTABLI]'),
            default    => '[UPTIMER]',
        };
    }

    private static function encodeName(string $n): string
    {
        return preg_match('~[^\x20-\x7E]~', $n) ? '=?UTF-8?B?' . base64_encode($n) . '?=' : '"' . addslashes($n) . '"';
    }

    /** @return array{0:string,1:string} */
    public static function body(string $title, array $lines, string $sev, array $mon): array
    {
        $accent = match ($sev) {
            'down' => '#e5484d', 'degraded' => '#f5a524', 'up' => '#30a46c', default => '#5b7fff',
        };
        $link = Notifier::monitorLink($mon);

        $rows = '';
        $txt  = strip_tags($title) . "\n" . str_repeat('=', 40) . "\n";
        foreach ($lines as [$name, $value]) {
            $v = (string)$value;
            if ($v === '') continue;
            $rows .= '<tr><td style="padding:8px 14px;color:#64748b;font-size:13px;white-space:nowrap;vertical-align:top">'
                   . e($name) . '</td><td style="padding:8px 14px;color:#0f172a;font-size:14px">' . e($v) . '</td></tr>';
            $txt  .= $name . ' : ' . $v . "\n";
        }
        if ($link) $txt .= "\nFiche : " . $link . "\n";

        $html = '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px">'
            . '<tr><td align="center"><table role="presentation" width="560" cellpadding="0" cellspacing="0" '
            . 'style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0">'
            . '<tr><td style="height:5px;background:' . $accent . '"></td></tr>'
            . '<tr><td style="padding:20px 14px 6px"><div style="font-size:18px;font-weight:700;color:#0f172a">'
            . e(strip_tags($title)) . '</div></td></tr>'
            . '<tr><td style="padding:6px 0 10px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . $rows . '</table></td></tr>'
            . ($link ? '<tr><td style="padding:6px 14px 22px"><a href="' . e($link) . '" '
                . 'style="display:inline-block;background:#0f172a;color:#fff;text-decoration:none;padding:10px 16px;'
                . 'border-radius:8px;font-size:14px;font-weight:600">'
                . e(t('Ouvrir dans {app}')) . '</a></td></tr>' : '')
            . '<tr><td style="padding:12px 14px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px">'
            . e(t('{app} · surveillance de sites')) . ' · ' . date('d/m/Y H:i') . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        return [$html, $txt];
    }
}
