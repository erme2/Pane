<?php

namespace App\Exceptions;

use RuntimeException;

/** WorkOS could not delete the user, so the anonymisation did not start. */
class UserAnonymisationException extends RuntimeException {}
