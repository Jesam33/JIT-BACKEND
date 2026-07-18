<?php

namespace Botble\Base\Supports\ValueObjects;

use Botble\Base\Supports\Core;
use Carbon\CarbonInterface;

class CoreProduct
{
    public function __construct(
        public string $version
    ) {
    }
}
