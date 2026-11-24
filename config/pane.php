<?php

use App\Messages\EmailChannel;

return [

    'signup' => [
        // Open sign-up: an unknown, WorkOS-verified email becomes a standard user without an invitation.
        // Off by default; changed by a deploy (environment or config), never through the API.
        'open' => (bool) env('PANE_SIGNUP_OPEN', false),
    ],

    'screening' => [
        // Text screening of map fields marked with the "screened" validation (Pane#175).
        // Extra blocked terms for this install, comma separated, added to the default list.
        'blocklist' => array_values(array_filter(array_map('trim', explode(',', (string) env('PANE_SCREENING_BLOCKLIST', ''))))),
        // Set to false to use only the install's own terms.
        'use_default_blocklist' => (bool) env('PANE_SCREENING_DEFAULT_BLOCKLIST', true),
        // Longest run of the same character allowed.
        'max_repeated_characters' => (int) env('PANE_SCREENING_MAX_REPEAT', 6),
        // "block": any link is refused; "allow_list": only the hosts below; "off": links are not checked.
        'links' => (string) env('PANE_SCREENING_LINKS', 'block'),
        'allowed_link_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PANE_SCREENING_ALLOWED_HOSTS', ''))))),
    ],

    'messages' => [
        // Channel name => App\Messages\MessageChannel class. SMS and mobile push plug in here.
        'channels' => [
            EmailChannel::NAME => EmailChannel::class,
        ],
    ],

];
