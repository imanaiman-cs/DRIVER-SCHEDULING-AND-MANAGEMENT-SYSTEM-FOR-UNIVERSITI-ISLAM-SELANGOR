<?php
// ============================================================
// WhatsApp (OpenWA) settings - optional, drivers only
//
// 1. Copy this file to config/whatsapp.php   (that file is NOT uploaded to
//    GitHub, so the API key stays on your computer)
// 2. Run your OpenWA server (open-wa EASY API) and scan the QR code with the
//    phone of the Vehicle Section
// 3. Fill in the values below and set 'enabled' to true
//
// NOTE: OpenWA drives WhatsApp Web, which is not an official WhatsApp API.
// WhatsApp may block the sending number. Use a dedicated number, and keep
// e-mail and in-system notifications as the main channels.
// ============================================================
return [
    // false = nothing is sent; the attempt is only written to whatsapp_log
    'enabled'      => false,

    // Address of the OpenWA server (no trailing slash)
    'base_url'     => 'http://localhost:8002',

    // Sent in the "api_key" header (leave '' if the server has no key)
    'api_key'      => '',

    // Path of the send-text call and how the body is built.
    //   open-wa EASY API: POST /sendText  {"args": {"to": "6012...@c.us", "content": "..."}}
    'send_path'    => '/sendText',

    // Country code used when a number starts with 0 (Malaysia = 60)
    'country_code' => '60',

    // Seconds to wait for the server; kept short so a page never hangs
    'timeout'      => 5,

    // For testing: send every message to this number instead (e.g. '60123456789'); '' = off
    'redirect_to'  => '',
];
