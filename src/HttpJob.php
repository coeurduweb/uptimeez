<?php
declare(strict_types=1);

namespace Uptimer;

/** État interne d'une requête en cours (objet = passage par référence naturel). */
final class HttpJob
{
    public mixed $ch = null;
    public Response $res;
    public string $body = '';
    public int $max = Http::MAX_BODY;
    public bool $truncated = false;
    public int|string|null $key = null;

    public function __construct()
    {
        $this->res = new Response();
    }
}
