<?php
declare(strict_types=1);

namespace Uptimeez\Notify;

use Uptimeez\Config;
use Uptimeez\Http;

/** Webhook générique : POST JSON, pour brancher n'importe quel outil (n8n, Make, Teams…). */
final class Webhook
{
    public static function send(string $title, array $lines, string $sev, array $mon): array
    {
        $url = trim((string)Config::get('notify.webhook.url', ''));
        if ($url === '') return ['ok' => false, 'info' => t('URL de webhook non configurée')];

        $fields = [];
        foreach ($lines as [$name, $value]) $fields[$name] = (string)$value;

        $payload = [
            'source'     => 'uptimeez',
            'severity'   => $sev,
            'title'      => $title,
            'monitor'    => ['id' => (int)($mon['id'] ?? 0), 'name' => $mon['name'] ?? '', 'url' => $mon['url'] ?? ''],
            'fields'     => $fields,
            'link'       => Notifier::monitorLink($mon),
            'timestamp'  => date('c'),
        ];

        $res = Http::fetch($url, [
            'method'  => 'POST',
            'body'    => jenc($payload),
            'headers' => ['Content-Type' => 'application/json', 'User-Agent' => 'Uptimeez/1.0'],
            'timeout' => 12,
            'maxBody' => 20000,
        ]);
        $ok = $res->ok && $res->status >= 200 && $res->status < 400;
        return ['ok' => $ok, 'info' => 'HTTP ' . $res->status . ' ' . str_cut($res->body ?: (string)$res->error, 150)];
    }
}
