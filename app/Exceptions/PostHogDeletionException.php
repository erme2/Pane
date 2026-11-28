<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * PostHog accepted a bulk delete (HTTP 202) but reported it could not erase
 * everything. The message never carries the provider's text, an id or a key.
 */
class PostHogDeletionException extends RuntimeException {}
