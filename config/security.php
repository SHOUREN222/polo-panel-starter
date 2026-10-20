<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Temporary node authentication compatibility
    |--------------------------------------------------------------------------
    |
    | Node credentials belong in request headers, never URLs. This switch is
    | only for a short rolling migration of existing agents. Leave it disabled
    | normally and remove it after every node has moved to header auth.
    |
    */
    'allow_legacy_node_query_token' => (bool) env('ALLOW_LEGACY_NODE_QUERY_TOKEN', false),
];
