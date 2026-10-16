<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Maintenance;

use RuntimeException;

/** An existing reservation rejects this action without changing the active job. */
final class MaintenanceConflictException extends RuntimeException
{
}
