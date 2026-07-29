<?php
declare(strict_types=1);

namespace Uptimeez\Notify;

use Uptimeez\I18n;

use Uptimeez\Config;
use Uptimeez\Http;

final class Discord
{
    public static function send(string $title, array $lines, string $sev, array $mon): array
    {
        // Démonstration publique : rien ne sort. Le verrou est ici, dans
        // l'expéditeur, et pas seulement dans la configuration : un visiteur
        // atteint l'écran des réglages.
        if ($q = \Uptimeez\Demo::silenced()) return $q;
        $url = trim((string)Config::get('notify.discord.webhook', ''));
        if ($url === '') return ['ok' => false, 'info' => t('Webhook Discord non configuré')];

        $fields = [];
        foreach ($lines as [$name, $value]) {
            $v = (string)$value;
            if ($v === '') continue;
            $fields[] = ['name' => $name, 'value' => str_cut($v, 900), 'inline' => mb_strlen($v) < 40];
        }
        $link = Notifier::monitorLink($mon);

        $payload = ['username' => I18n::APP, 'embeds' => [[
            'title'       => str_cut($title, 240),
            'description' => $link ? '[' . t('Ouvrir la fiche de surveillance') . '](' . $link . ')' : null,
            'color'       => Notifier::COLORS[$sev] ?? Notifier::COLORS['info'],
            'fields'      => $fields,
            'timestamp'   => date('c'),
            'footer'      => ['text' => 'UptimeEZ · ' . date('d/m/Y H:i')],
        ]]];

        $res = Http::fetch($url, [
            'method'  => 'POST',
            'body'    => jenc($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 12,
            'maxBody' => 20000,
        ]);
        $ok = $res->ok && $res->status >= 200 && $res->status < 300;
        return ['ok' => $ok, 'info' => $ok ? 'HTTP ' . $res->status : ('HTTP ' . $res->status . ' ' . str_cut($res->body ?: (string)$res->error, 200))];
    }
}
