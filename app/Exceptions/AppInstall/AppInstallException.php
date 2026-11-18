<?php

namespace App\Exceptions\AppInstall;

use RuntimeException;

/** The data folder, the prefix, a migration or a maps file can't be installed. */
class AppInstallException extends RuntimeException {}
