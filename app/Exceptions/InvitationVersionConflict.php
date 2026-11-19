<?php

namespace App\Exceptions;

use DomainException;

/**
 * The invitation changed after the client read it: the precondition (If-Match) no longer holds
 * against the locked row. Maps to 412 version_conflict.
 */
class InvitationVersionConflict extends DomainException {}
