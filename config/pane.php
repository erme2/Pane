<?php

use App\Messages\EmailChannel;
use App\Messages\WebPushChannel;

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
        // Longest screened text allowed, in characters (0 = no limit).
        'max_length' => (int) env('PANE_SCREENING_MAX_LENGTH', 2000),
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
            WebPushChannel::NAME => WebPushChannel::class,
        ],
    ],

    'push' => [
        // VAPID keys of this install (web push). Set by the deploy from secrets, never through the API.
        // Without a public and a private key, push is off: registering and sending are refused.
        'vapid_public_key' => (string) env('PANE_VAPID_PUBLIC_KEY', ''),
        'vapid_private_key' => (string) env('PANE_VAPID_PRIVATE_KEY', ''),
        // A mailto: or https: address the push services can reach the install owner at.
        'vapid_subject' => (string) env('PANE_VAPID_SUBJECT', ''),
        // Most pushes one member can be sent per minute (all their devices count as one).
        'rate_limit_per_minute' => (int) env('PANE_PUSH_RATE_LIMIT', 10),
        // Most devices one member can keep registered.
        'max_subscriptions_per_user' => (int) env('PANE_PUSH_MAX_DEVICES', 10),
        // Seconds the push service keeps an undelivered push.
        'ttl' => (int) env('PANE_PUSH_TTL', 86400),
    ],

];
