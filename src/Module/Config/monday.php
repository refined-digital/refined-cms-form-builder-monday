<?php
return [
    // personal API token — avatar > Developers > My access tokens in monday.com
    'token' => env('MONDAY_TOKEN'),

    // where to send an alert when a submission fails to reach monday.com.
    // unset = no alert (the failure is still report()ed to the logs)
    'error_email' => env('MONDAY_ERROR_EMAIL'),

    // monday phone columns require an ISO-2 country alongside the number
    'phone_country' => env('MONDAY_PHONE_COUNTRY', 'NZ'),
];
