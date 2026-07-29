<?php
declare(strict_types=1);

namespace Uptimer\Notify;

use Uptimer\Config;
use Uptimer\Http;

final class Slack
{
    private const HEX = ['down' => '#e5484d', 'degraded' => '#f5a524', 'up' => '#30a46c', 'info' => '#5b7fff'];

    public static function send(string $title, array $lines, string $sev, array $mon): array
    {
        $url = trim((string)Config::get('notify.slack.webhook', ''));
        if ($url === '') return ['ok' => false, 'info' => t('Webhook Slack non configuré')];

        $fields = [];
        foreach ($lines as [$name, $value]) {
            $v = (string)$value;
            if ($v === '') continue;
            $fields[] = ['type' => 'mrkdwn', 'text' => '*' . $name . "*\n" . str_cut($v, 400)];
        }
        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => str_cut($title, 145), 'emoji' => true]],
        ];
        foreach (array_chunk($fields, 2) as $chunk) {
            $blocks[] = ['type' => 'section', 'fields' => $chunk];
        }
        $link = Notifier::monitorLink($mon);
        if ($link) {
            $blocks[] = ['type' => 'actions', 'elements' => [[
                'type' => 'button', 'text' => ['type' => 'plain_text', 'text' => t('Ouvrir dans {app}')], 'url' => $link,
            ]]];
        }

        $payload = [
            'text'        => str_cut($title, 200),
            'attachments' => [[
                'color'  => self::HEX[$sev] ?? self::HEX['info'],
                'blocks' => $blocks,
            ]],
        ];

        $res = Http::fetch($url, [
            'method'  => 'POST',
            'body'    => jenc($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 12,
            'maxBody' => 20000,
        ]);
        $ok = $res->ok && $res->status >= 200 && $res->status < 300 && stripos($res->body, 'invalid') === false;
        return ['ok' => $ok, 'info' => 'HTTP ' . $res->status . ' ' . str_cut($res->body ?: (string)$res->error, 150)];
    }
}
