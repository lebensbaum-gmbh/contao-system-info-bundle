<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Maintenance;

use RuntimeException;

/** The explicit --fail self-test; unexpected worker errors retain error logging. */
final class MaintenanceProbeFailureException extends RuntimeException
{
}
