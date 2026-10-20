<?php
/**
 * Pajuleras Boarding House Management System - configuration
 *
 * XAMPP defaults are shown below (user "root", empty password).
 * Change them if your MySQL setup is different.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'pajuleras_bh',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Leave empty to auto-detect (works in XAMPP htdocs sub-folders).
    // Only set this if links break, e.g. '/pajuleras_bh'
    'base_url' => '',

    'timezone'   => 'Asia/Manila',
    'tz_offset'  => '+08:00',      // MySQL session time zone (Philippines has no DST)

    // Set to true only while developing; shows PHP errors on screen.
    'debug' => false,

    // Admin session: minutes of inactivity before automatic logout
    'admin_idle_minutes' => 30,

    // Abuse protection
    'max_failed_logins'   => 5,    // per IP+username ...
    'login_lock_minutes'  => 15,   // ... within this many minutes
    'max_inquiries_per_hour' => 5, // new inquiries per visitor IP per hour
    'max_track_failures'  => 8,    // wrong tracking look-ups per IP per 15 min

    // Room photo uploads
    'upload_max_bytes' => 3 * 1024 * 1024,
];
