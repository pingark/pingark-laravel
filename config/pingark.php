<?php

/*
|--------------------------------------------------------------------------
| PingArk client configuration
|--------------------------------------------------------------------------
|
| base_url + ping_key drive the slug ping URLs your scheduled tasks hit.
| api_key (a read-write project key) is only needed by the pingark:sync
| command and the PingArk::api() management client. Everything here reads
| from the environment so it stays out of version control.
|
*/

return [

    /* Master switch: set PINGARK_ENABLED=false to silence every ping. */
    'enabled' => env('PINGARK_ENABLED', true),

    /* The ingestion base URL your pings are sent to (no trailing slash needed). */
    'base_url' => env('PINGARK_BASE_URL', 'https://ping.pingark.com'),

    /*
     * The Management API base URL, used by pingark:sync and PingArk::api(). A
     * separate surface from ingestion so its security is managed independently.
     */
    'api_url' => env('PINGARK_API_URL', 'https://api.pingark.com'),

    /* The project ping key (Project settings -> Project ping key). Drives slug pings. */
    'ping_key' => env('PINGARK_PING_KEY'),

    /* A read-write project API key, used by pingark:sync and PingArk::api(). */
    'api_key' => env('PINGARK_API_KEY'),

    /* Grace period in seconds applied to checks created by pingark:sync. */
    'default_grace' => (int) env('PINGARK_DEFAULT_GRACE', 600),

    /* Outbound ping timeout in seconds. Short, so a slow network never hangs a job. */
    'timeout' => (int) env('PINGARK_TIMEOUT', 5),

    /* User agent sent with every ping, so you can spot the plugin in ping history. */
    'user_agent' => env('PINGARK_USER_AGENT', 'PingArk-Laravel'),

    /*
     * Optional default check slug. When set, the PingArk facade signals may be
     * called with no check argument (e.g. PingArk::success()) and fall back to
     * this slug, which is handy for a single-job application.
     */
    'default_check' => env('PINGARK_DEFAULT_CHECK'),

];
