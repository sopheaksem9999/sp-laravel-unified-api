<?php

namespace Sopheak\Core\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Sopheak\Core\Tests\TestCase;

class CleanTempAttachmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('sp_attachments')) {
            Schema::create('sp_attachments', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('disk');
                $table->string('path');
                $table->string('visibility');
                $table->timestamp('temp_timeout')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('sp_attachment_links')) {
            Schema::create('sp_attachment_links', function (Blueprint $table): void {
                $table->id();
                $table->string('attachment_id')->index();
                $table->string('record_id')->nullable();
                $table->string('record_type')->nullable();
                $table->string('collection_name')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        Storage::fake('local');
        Storage::fake('public');
    }

    /** @test */
    public function it_deletes_expired_temp_attachments_using_temp_timeout_and_fallback_created_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-03 10:00:00'));
        Config::set('attachments.temp_lifetime', 60);

        Storage::disk('local')->put('attachments/expired-timeout.txt', 'expired-timeout');
        Storage::disk('local')->put('attachments/expired-fallback.txt', 'expired-fallback');
        Storage::disk('local')->put('attachments/keep-temp.txt', 'keep-temp');
        Storage::disk('local')->put('attachments/keep-private.txt', 'keep-private');

        DB::table('sp_attachments')->insert([
            [
                'id' => 'expired-timeout',
                'disk' => 'local',
                'path' => 'attachments/expired-timeout.txt',
                'visibility' => 'temp_private',
                'temp_timeout' => Carbon::now()->subMinutes(5)->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ],
            [
                'id' => 'expired-fallback',
                'disk' => 'local',
                'path' => 'attachments/expired-fallback.txt',
                'visibility' => 'temp_public',
                'temp_timeout' => null,
                'created_at' => Carbon::now()->subHours(3)->toDateTimeString(),
                'updated_at' => Carbon::now()->subHours(3)->toDateTimeString(),
            ],
            [
                'id' => 'keep-temp',
                'disk' => 'local',
                'path' => 'attachments/keep-temp.txt',
                'visibility' => 'temp_private',
                'temp_timeout' => Carbon::now()->addMinutes(30)->toDateTimeString(),
                'created_at' => Carbon::now()->subHours(10)->toDateTimeString(),
                'updated_at' => Carbon::now()->subHours(10)->toDateTimeString(),
            ],
            [
                'id' => 'keep-private',
                'disk' => 'local',
                'path' => 'attachments/keep-private.txt',
                'visibility' => 'private',
                'temp_timeout' => Carbon::now()->subMinutes(5)->toDateTimeString(),
                'created_at' => Carbon::now()->subHours(5)->toDateTimeString(),
                'updated_at' => Carbon::now()->subHours(5)->toDateTimeString(),
            ],
        ]);

        DB::table('sp_attachment_links')->insert([
            ['attachment_id' => 'expired-timeout'],
            ['attachment_id' => 'expired-fallback'],
            ['attachment_id' => 'keep-temp'],
        ]);

        $this->artisan('sp-laravel-api:clean-temp-attachments', ['--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('sp_attachments', ['id' => 'expired-timeout']);
        $this->assertDatabaseMissing('sp_attachments', ['id' => 'expired-fallback']);
        $this->assertDatabaseHas('sp_attachments', ['id' => 'keep-temp']);
        $this->assertDatabaseHas('sp_attachments', ['id' => 'keep-private']);

        $this->assertDatabaseMissing('sp_attachment_links', ['attachment_id' => 'expired-timeout']);
        $this->assertDatabaseMissing('sp_attachment_links', ['attachment_id' => 'expired-fallback']);
        $this->assertDatabaseHas('sp_attachment_links', ['attachment_id' => 'keep-temp']);

        $this->assertFalse(Storage::disk('local')->exists('attachments/expired-timeout.txt'));
        $this->assertFalse(Storage::disk('local')->exists('attachments/expired-fallback.txt'));
        $this->assertTrue(Storage::disk('local')->exists('attachments/keep-temp.txt'));
        $this->assertTrue(Storage::disk('local')->exists('attachments/keep-private.txt'));

        Carbon::setTestNow();
    }
}
