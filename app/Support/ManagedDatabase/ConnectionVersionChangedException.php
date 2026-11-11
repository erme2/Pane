<?php

namespace App\Support\ManagedDatabase;

use RuntimeException;

/** The connection changed after the caller read it (If-Match no longer matches); nothing was saved. */
final class ConnectionVersionChangedException extends RuntimeException {}
