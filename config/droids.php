<?php

return [
    // Allow anyone to sign up after the first account exists.
    'open_registration' => (bool) env('DROIDS_OPEN_REGISTRATION', false),
];
