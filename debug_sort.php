<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Schema::create('test_posts', function ($table) {
    $table->id();
    $table->string('title');
    $table->timestamps();
});

DB::table('test_posts')->insert(['title' => 'P1', 'created_at' => now()->subDays(3)]);
DB::table('test_posts')->insert(['title' => 'P2', 'created_at' => now()->subDays(1)]);
DB::table('test_posts')->insert(['title' => 'P3', 'created_at' => now()->subDays(2)]);

$builder = DB::table('test_posts');
$builder->orderBy('test_posts.created_at', 'desc');
$builder->whereIn('test_posts.id', [1, 2, 3]);

$data = $builder->get();

foreach ($data as $item) {
    echo $item->title . "\n";
}
