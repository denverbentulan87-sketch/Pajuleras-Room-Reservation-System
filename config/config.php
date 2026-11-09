<?php
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'pajuleras_bh',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'base_url' => '',

    'timezone'   => 'Asia/Manila',
    'tz_offset'  => '+08:00',      

    // Set to true only while developing; shows PHP errors on screen.
    'debug' => false,

    // Admin session: minutes of inactivity before automatic logout
    'admin_idle_minutes' => 30,

    // Abuse protection
    'max_failed_logins'   => 5,    // per IP+username ...
    'login_lock_minutes'  => 15,   // ... within this many minutes
    'max_inquiries_per_hour' => 5, // new inquiries per visitor IP per hour
    'max_track_failures'  => 8,    // wrong tracking look-ups per IP per 15 min

    'mail' => [
        'host'       => 'smtp.gmail.com',          // e.g. 'smtp.gmail.com'
        'port'       => 587,         // 587 with 'tls', 465 with 'ssl'
        'secure'     => 'tls',       // 'tls' (STARTTLS), 'ssl' or '' (none)
        'user'       => '',          // your full e-mail address
        'pass'       => '',          // for Gmail use an App Password, never your normal password
        'from_email' => '',          // defaults to 'user'
        'from_name'  => '',          // defaults to the boarding house name
        'verify_tls' => true,
    ],

    // Room photo uploads
    'upload_max_bytes' => 3 * 1024 * 1024,
];
