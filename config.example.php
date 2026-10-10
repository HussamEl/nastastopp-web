<?php
// Copy to nastastopp-config.php ONE FOLDER ABOVE public_html (never inside it, never in git),
// and fill it on the server only (hPanel → File Manager). The API finds it by walking up from its
// own folder.
return [
    // MySQL from hPanel → Databases (names in docs/ACCOUNTS.md).
    'db_dsn' => 'mysql:host=localhost;dbname=u840798620_nastastopp;charset=utf8mb4',
    'db_user' => 'u840798620_nastastopp_api',
    'db_pass' => '',

    // Google: one key for the Routes API (server only), one for the Maps JavaScript API (sent to
    // enrolled tablets for their map; restrict it in Google Cloud to the Maps JavaScript API).
    'google_routes_key' => '',
    'google_maps_js_key' => '',

    // mapmap.ai.
    'mapmap_key' => '',

    // The admin page's password, as password_hash('…', PASSWORD_DEFAULT) gives it.
    'admin_password_hash' => '',

    // Requests a device may make a day, per service.
    'daily_limits' => ['google_routes' => 1500, 'google_matrix' => 150, 'mapmap_route' => 3000, 'mapmap_matrix' => 300, 'config' => 200],
];
