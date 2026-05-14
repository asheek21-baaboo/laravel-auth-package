<?php

declare(strict_types=1);

namespace Baaboo\InternalToolComposerAuthPackage;

class CurrentUserService
{
    public function __construct(
        private readonly TokenValidator $validator,
    ) {}
}
