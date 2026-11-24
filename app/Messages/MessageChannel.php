<?php

namespace App\Messages;

use App\Models\Message;

/**
 * A way to deliver a queued message (email, later SMS and mobile push). The
 * queue table and the worker never change for a new channel: implement this
 * interface and register the class under its name in `config/pane.php`
 * (`messages.channels`).
 */
interface MessageChannel
{
    /**
     * Delivers the message. Throw on any failure so the worker retries it.
     */
    public function send(Message $message): void;
}
