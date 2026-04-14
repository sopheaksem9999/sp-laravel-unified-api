<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$config = config('attachments.tables.sp_attachments');
dump(isset($config->afterRead));
if (isset($config->afterRead)) {
    dump($config->afterRead);
}
