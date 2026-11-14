<?php

namespace App\Support\ManagedDatabase;

use RuntimeException;

/** The catalog could not be read from the managed database; the message is safe to show. */
final class CatalogUnavailableException extends RuntimeException {}
