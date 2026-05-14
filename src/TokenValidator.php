<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

class TokenValidator
{
    public function __construct(
        private readonly string $idpUrl,
        private readonly int $cacheTtl,
        private readonly CacheRepository $cache,
    ) {}
}
