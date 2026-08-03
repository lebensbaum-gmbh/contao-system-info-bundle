<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ContaoSystemInfoBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}