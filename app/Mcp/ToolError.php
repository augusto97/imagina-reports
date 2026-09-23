<?php

declare(strict_types=1);

namespace App\Mcp;

use RuntimeException;

/**
 * A failure the assistant should read and act on (a missing argument, a record that doesn't
 * exist, a validation message from the app). Its message is shown as-is, so it is written for
 * a person, in Spanish.
 */
final class ToolError extends RuntimeException {}
