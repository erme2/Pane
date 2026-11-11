<?php

namespace App\Support\ManagedDatabase;

use RuntimeException;

/** A connection refused by Pane's own rules (TLS mode, unencrypted public host); safe to show. */
final class ConnectionRejectedException extends RuntimeException {}
