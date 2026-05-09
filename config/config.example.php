<?php

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'YOUR_DATABASE_NAME',
        'user' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'stripe' => [
        'secret_key' => 'sk_live_xxx',
        'webhook_secret' => 'whsec_xxx',
        // 任意。設定する場合はLive環境で作成したprod_から始まるIDだけを入れてください。
        // 空文字の場合はCheckout作成時に商品名「ligacu Recovery Session 90min」を使います。
        'product_id' => '',
    ],
    'app' => [
        'base_url' => 'https://ligacu.com',
        'admin_user' => 'admin',
        'admin_password' => 'CHANGE_ME',
    ],
];
