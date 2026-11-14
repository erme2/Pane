<?php

namespace App\Support\ManagedDatabase;

use RuntimeException;

/** The catalog object changed after the caller read it (If-Match no longer matches); nothing was saved. */
final class CatalogVersionChangedException extends RuntimeException {}
