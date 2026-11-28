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
 |  - `reports` (Pane#245): everyone files a report for a list they belong to, only administrators
 |    read it and change its status (reporter, list, status, targets, members).
 |
 | Two options extend the rules above (Pane#245): an `owner` rule may carry `member_of` (the new row
 | names a list the user belongs to, and optionally `other`, another member), and a `members` rule may
 | carry `hide_blocked` (author, table, list, blocker, blocked): a read leaves out the rows written by
 | a member the user blocked in that list.
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
            // TODO(erme2/sweegly#41): add `hide_blocked` here (and to the messages table) once the maps carry the author column.
        ]],
        // Reports and blocks (Pane#245); their maps live in erme2/sweegly data/ and must use these columns.
        'content_reports' => ['reports' => [
            'reporter' => 'reporter_membership_id', 'list' => 'list_id', 'status' => 'status',
            'reason' => 'reason',
            'reasons' => ['Spam', 'Abusive or hateful', 'Sexual content', 'Personal information', 'Illegal content', 'Other'],
            'targets' => ['list_item_id', 'message_id'],
            'members' => ['table' => 'list_members', 'members_key' => 'list_id', 'members_user' => 'membership_id'],
        ]],
        'member_blocks' => ['owner' => [
            'key' => 'blocker_membership_id',
            'member_of' => [
                'list' => 'list_id', 'table' => 'list_members', 'members_key' => 'list_id', 'members_user' => 'membership_id',
                'other' => 'blocked_membership_id',
            ],
        ]],
    ],
];
