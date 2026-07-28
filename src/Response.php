<?php
declare(strict_types=1);

namespace Uptimer;

/** Réponse HTTP enrichie (timings, certificat, erreur curl normalisée). */
final class Response
{
    public bool   $ok = false;          // requête techniquement aboutie
    public int    $status = 0;
    public string $body = '';
    public array  $headers = [];        // clés en minuscules
    public string $finalUrl = '';
    public int    $redirects = 0;
    public ?string $error = null;       // message curl brut
    public ?string $errorCode = null;   // TIMEOUT | DNS | CONNECT | SSL_INVALID | ...
    public int    $dnsMs = 0;
    public int    $connectMs = 0;
    public int    $tlsMs = 0;
    public int    $ttfbMs = 0;
    public int    $totalMs = 0;
    public int    $size = 0;
    public ?string $contentType = null;
    public array  $certInfo = [];
    public bool   $truncated = false;
    public string $url = '';
    public string $ip = '';        // adresse réellement contactée (corrélation de pannes)

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isHtml(): bool
    {
        return str_contains(strtolower((string)$this->contentType), 'html')
            || (bool)preg_match('~^\s*(<!doctype html|<html)~i', ltrim(substr($this->body, 0, 500)));
    }

    public function looksLikeCss(): bool
    {
        $head = substr($this->body, 0, 4000);
        if ($head === '') return false;
        if (preg_match('~^\s*(<|<\?php)~', $head)) return false;
        return (bool)preg_match('~[{}@]~', $head)
            && (bool)preg_match('~(\{[^}]*:[^}]*\}|@media|@import|@font-face|:root|--[a-z-]+\s*:)~i', $head);
    }
}
