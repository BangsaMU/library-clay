<?php
return [
    'curl' => array(
        'TIMEOUT' => 30,
        'VERIFY' => false,
        'LIST_NETWORK' => env('APP_LIST_NETWORK'),
    ),
    'main' => array(
        'APP_CODE' => '11', /*10 digit max char dari master app */
        'KEY' => '', /*32 digit  char random untuk hasing token harus sama antara server an client untuk decode token dari server */
        'ACTIVE' => env('SSO_ACTIVE', false), /*jika akan login mengunakan sso set ke true [true,false], tambahkan di env SSO_ACTIVE untuk config di lokal development*/
        'TOKEN' => '', /*auth untuk masuk ke sytem api sso*/
        'URL' => env('SSO_URL', 'http://sso.test'), /*harus diakhiri dengan / (slash) url untuk login SSO*/
        'CALL_BACK' => '',
    ),
];
