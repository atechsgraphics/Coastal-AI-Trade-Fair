<?php

/**
 * Example secrets file.
 * ---------------------------------------------------------------------------
 * Copy this to  data/env.php  on the server and fill in the real values.
 *
 *   data/env.php is listed in .gitignore and is never committed.
 *   The whole data folder is also blocked from the web, so nothing here is
 *   ever served to a visitor.
 *
 * Anything set here wins over the same setting in the control panel, so a
 * password typed here never has to be stored in the database at all.
 *
 * Every booking setting can be overridden: use the setting's name in capitals.
 * Delete any line you do not need — a missing key simply falls back to the
 * control panel.
 */

return [
    /* The address of the live site. Used for the links in emails and for the
       QR code printed on every ticket. No trailing slash. */
    'APP_URL' => 'https://www.example.na',

    /* Mail server. Set "How email is sent" to SMTP in the control panel, put
       the host, port and username there, and keep only the password here. */
    'BK_SMTP_PASS' => 'your-smtp-password',

    /* The rest are optional — the control panel is usually the easier place
       for these. Shown so you know the naming works for any setting.

    'BK_SMTP_HOST'  => 'smtp.example.com',
    'BK_SMTP_USER'  => 'bookings@example.na',
    'BK_FROM_EMAIL' => 'bookings@example.na',
    */
];
