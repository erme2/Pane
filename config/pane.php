<?php

use App\Messages\EmailChannel;

return [

    'signup' => [
        // Open sign-up: an unknown, WorkOS-verified email becomes a standard user without an invitation.
        // Off by default; changed by a deploy (environment or config), never through the API.
        'open' => (bool) env('PANE_SIGNUP_OPEN', false),
    ],

    'messages' => [
        // Channel name => App\Messages\MessageChannel class. SMS and mobile push plug in here.
        'channels' => [
            EmailChannel::NAME => EmailChannel::class,
        ],
    ],

];
