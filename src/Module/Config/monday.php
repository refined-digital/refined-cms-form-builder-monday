<?php
return [
    // personal API token — avatar > Developers > My access tokens in monday.com
    'token' => env('MONDAY_TOKEN'),

    // where to send an alert when a submission fails to reach monday.com.
    // unset = no alert (the failure is still report()ed to the logs)
    'error_email' => env('MONDAY_ERROR_EMAIL'),

    // ISO-2 country phone numbers are local to (e.g. AU, NZ) — used to send them in
    // international format, which monday's item view needs
    'phone_country' => env('MONDAY_PHONE_COUNTRY', 'NZ'),
];
