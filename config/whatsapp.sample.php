<?php
// ============================================================
// WhatsApp (OpenWA) settings - optional, drivers only
// OpenWA: https://github.com/rmyndharis/OpenWA   (setup steps: docs/OPENWA_SETUP.md)
//
// 1. Copy this file to config/whatsapp.php   (that file is NOT uploaded to
//    GitHub, so the API key stays on your computer)
// 2. Fill in the values below and set 'enabled' to true
//
// NOTE: OpenWA is an unofficial gateway. WhatsApp may restrict the sending number.
// Use a dedicated number; e-mail and in-system notifications stay the main channels.
// ============================================================
return [
    // false (default) = nothing is sent and nothing is written to whatsapp_log. WhatsApp is outside the SRS (v1.1 lists in-system and e-mail only).
    'enabled'      => false,

    // Address of the OpenWA server (no trailing slash, no /api)
    'base_url'     => 'http://localhost:2785',

    // OpenWA API key sent in the "X-API-Key" header (use an OPERATOR key limited to the session below)
    'api_key'      => '',

    // The OpenWA session that is connected to the sending phone (its id, from the dashboard)
    'session_id'   => '',

    // Country code used when a number starts with 0 (Malaysia = 60)
    'country_code' => '60',

    // Seconds to wait for the server; kept short so a page never hangs
    'timeout'      => 5,

    // For testing: send every message to this number instead (e.g. '60123456789'); '' = off
    'redirect_to'  => '',
];
