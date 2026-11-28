<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The personal-data cleanup could not finish. What it had done in the database
 * was rolled back, so running it again is safe. The message never carries a
 * user id, a provider message or a secret.
 */
class PersonalDataCleanupException extends RuntimeException {}
