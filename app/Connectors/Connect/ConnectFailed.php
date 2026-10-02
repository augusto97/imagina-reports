<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

use RuntimeException;

/**
 * A one-click connection that did not complete, with the reason in words the person can act
 * on (shown as-is in the panel). Thrown by providers instead of returning null, so the real
 * cause — a cancelled consent, an unchecked permission, a misconfigured OAuth client — is
 * never flattened into "it didn't work".
 */
final class ConnectFailed extends RuntimeException {}
