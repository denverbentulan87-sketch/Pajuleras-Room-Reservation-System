<?php
declare(strict_types=1);

/**
 * Inline SVG icon set (24x24, drawn with strokes in the current text colour).
 * No external files, so icons work offline and under the strict CSP.
 */
function icon(string $name, string $class = ''): string
{
    static $paths = [
        'home'      => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M10 20v-5h4v5"/>',
        'bed'       => '<path d="M2.5 4v16"/><path d="M2.5 8h17a2 2 0 0 1 2 2v10"/><path d="M2.5 17h19"/><path d="M6.5 8v9"/>',
        'door'      => '<path d="M5 21V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v17"/><path d="M3 21h18"/><circle cx="15.5" cy="12.5" r=".9" fill="currentColor"/>',
        'clipboard' => '<rect x="5.5" y="4.5" width="13" height="16.5" rx="2"/><path d="M9 4.5h6V7H9z"/><path d="M9 12h6M9 16h6"/>',
        'message'   => '<path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5 4v-4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/><path d="M8 9.5h8M8 12.5h5"/>',
        'search'    => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'key'       => '<circle cx="8" cy="15" r="4"/><path d="M11 12 20 3M17 6l3 3M14.5 8.5l2 2"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17" cy="9" r="2.8"/><path d="M17.5 14.2c2.6.3 4.5 2.2 4.5 5"/>',
        'lock'      => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
        'eye'       => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'   => '<path d="M10.6 6.1A10 10 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.2 3.9M6.6 7.6A17 17 0 0 0 2 12s3.6 7 10 7a9.8 9.8 0 0 0 4-.9"/><path d="M3 3l18 18"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'dashboard' => '<rect x="3" y="3" width="7.5" height="9" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="5.5" rx="1.5"/><rect x="13.5" y="12" width="7.5" height="9" rx="1.5"/><rect x="3" y="15.5" width="7.5" height="5.5" rx="1.5"/>',
        'inbox'     => '<path d="M3 13l3-8h12l3 8"/><path d="M3 13v6a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-6h-5.5a3.5 3.5 0 0 1-7 0H3z"/>',
        'calendar'  => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="m9 15 2 2 4-4"/>',
        'calendar-clock' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M12 13v3l2 1"/>',
        'cash'      => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 9.5v.01M18 14.5v.01"/>',
        'chart'     => '<path d="M4 4v16h16"/><path d="M8 16v-5M12 16V8M16 16v-7"/>',
        'sliders'   => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'history'   => '<path d="M3.5 12a8.5 8.5 0 1 0 2.6-6.1L3.5 8.5"/><path d="M3.5 4v4.5H8"/><path d="M12 8v4.5l3 1.8"/>',
        'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.6 2.6 3.8 5.6 3.8 9s-1.2 6.4-3.8 9c-2.6-2.6-3.8-5.6-3.8-9S9.4 5.6 12 3z"/>',
        'logout'    => '<path d="M9 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h4"/><path d="M16 8l4 4-4 4M20 12H9"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'x'         => '<path d="M6 6l12 12M18 6 6 18"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'phone'     => '<path d="M6.5 3.5h3l1.5 4-2 1.5a11 11 0 0 0 5.5 5.5l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5.5a2 2 0 0 1 2-2z"/>',
        'pin'       => '<path d="M12 21s7-6.2 7-11.5a7 7 0 0 0-14 0C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'alert'     => '<path d="M12 3.5 22 20H2L12 3.5z"/><path d="M12 10v4.5M12 17.5v.01"/>',
        'arrow'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'shield'    => '<path d="M12 3l8 3v5.5c0 4.7-3.2 8.2-8 9.5-4.8-1.3-8-4.8-8-9.5V6l8-3z"/><path d="m9 12 2.2 2.2L15.5 10"/>',
        'check'     => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'receipt'   => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9 8h6M9 12h6"/>',
        'layers'    => '<path d="M12 3 3 8l9 5 9-5-9-5z"/><path d="m3 12.5 9 5 9-5"/><path d="m3 17 9 5 9-5"/>',
        'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>',
        'arrow-up'  => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'chevron'   => '<path d="m6 9 6 6 6-6"/>',
        'tag'       => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.4" fill="currentColor"/>',
        'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'send'      => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/>',
        'refresh'   => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/>',
        'copy'      => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
        'wallet'    => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1V10a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2z"/><circle cx="16.5" cy="14.5" r="1.2"/>',
        'bot'       => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 4v4M9 13v1.5M15 13v1.5M2 13v3M22 13v3"/>',
        'expand'    => '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
        'sparkle'   => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/>',
    ];
    $inner = $paths[$name] ?? $paths['sparkle'];
    return '<svg class="ic ic-' . $name . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}
