<?php
// ============================================================
// E-mail settings
//
// 1. Copy this file to config/mail.php   (that file is NOT uploaded to
//    GitHub, so your password stays on your computer)
// 2. Fill in the values below
// 3. Set 'enabled' to true
//
// Gmail: turn on 2-Step Verification for the sending account, then
// create an "App password" at https://myaccount.google.com/apppasswords
// and paste the 16 characters below (no spaces). Your normal Gmail
// password will NOT work.
// ============================================================
return [
    // false = nothing is sent; messages are only saved in the e-mail log
    'enabled'     => false,

    'host'        => 'smtp.gmail.com',
    'port'        => 587,
    'encryption'  => 'tls',                       // 'tls' (port 587), 'ssl' (port 465) or ''
    'auth'        => true,
    'username'    => 'your.sending.account@gmail.com',
    'password'    => 'xxxxxxxxxxxxxxxx',          // Gmail app password

    'from_email'  => '',                          // leave empty to use the username
    'from_name'   => 'UIS Transport Unit',
    'reply_to'    => '',

    // TESTING: send every e-mail to these address(es) instead of the real
    // driver, so nobody outside your team receives test messages.
    // Leave the list empty ([]) when the system goes live.
    'redirect_to' => [
        // 'first.inbox@gmail.com',
        // 'second.inbox@gmail.com',
    ],
];
