<?php

/*
 | Row access rules set by Pane itself (Pane#232), keyed by table name. They
 | win over the `access` rules a map may still carry, and they cannot be changed
 | through a map or the API: only a deploy changes this file.
 |
 | A non-administrator reaches a table only when it has a rule, here or (until
 | the maps drop it) in its map; a table with no rule is refused, reads included.
 |
 | Rule kinds:
 |  - `members`: one hop through a members table, as the map's `access.members`
 |    (table, key, members_key, members_user, optional role and creator).
 |  - `owner`: ['key' => column] holds the signed-in user's uuid.
 |  - `read`: true; any signed-in user reads every row, only administrators write.
 */
return [
    'tables' => [
        // Sweegly's shared catalogue: everyone signed in reads, only an administrator writes.
        'categories' => ['read' => true],
        'products' => ['read' => true],
        'chains' => ['read' => true],
        'shops' => ['read' => true],
        'offers' => ['read' => true],
        // Sweegly's lists: the one-hop members rules of data/maps/sweegly.json.
        'lists' => ['members' => [
            'table' => 'list_members', 'key' => 'id', 'members_key' => 'list_id',
            'members_user' => 'membership_id', 'creator' => 'created_by_membership_id',
        ]],
        'list_members' => ['members' => [
            'table' => 'list_members', 'key' => 'list_id', 'members_key' => 'list_id', 'members_user' => 'membership_id',
        ]],
        'list_items' => ['members' => [
            'table' => 'list_members', 'key' => 'list_id', 'members_key' => 'list_id', 'members_user' => 'membership_id',
        ]],
    ],
];
