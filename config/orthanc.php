<?php

return [
    'url' => rtrim((string) env('ORTHANC_URL', 'http://192.168.2.58:8042'), '/'),
    'username' => env('ORTHANC_USERNAME'),
    'password' => env('ORTHANC_PASSWORD'),
    'timeout' => (int) env('ORTHANC_TIMEOUT', 20),
];
