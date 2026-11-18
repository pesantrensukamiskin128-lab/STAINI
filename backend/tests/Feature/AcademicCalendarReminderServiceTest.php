<?php

namespace Tests\Feature;

use App\Services\AcademicCalendarReminderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AcademicCalendarReminderServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password');
            $table->string('username')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('academic_calendars', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('category');
            $table->string('event_type')->nullable();
            $table->string('color')->nullable();
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('type', 50)->default('info');
            $table->string('link')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'User Aktif Satu', 'password' => 'x', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'User Aktif Dua', 'password' => 'x', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'User Nonaktif', 'password' => 'x', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_start_and_end_reminders_go_to_active_users_and_are_not_duplicated(): void
    {
        DB::table('academic_calendars')->insert([
            [
                'id' => 1, 'title' => 'Pendaftaran KRS', 'description' => 'Isi KRS daring.',
                'start_date' => '2026-10-04', 'end_date' => '2026-10-10', 'category' => 'Akademik',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'title' => 'Libur Satu Hari', 'start_date' => '2026-10-04',
                'description' => null, 'end_date' => null, 'category' => 'Libur',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $service = app(AcademicCalendarReminderService::class);
        $firstRun = $service->sendUpcomingReminders();

        $this->assertSame(['events' => 2, 'reminders' => 4, 'users' => 2], $firstRun);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseHas('notifications', [
            'user_id' => 1,
            'title' => 'Pengingat Kalender Akademik',
            'message' => 'Event "Pendaftaran KRS" akan dimulai 3 hari lagi (2026-10-04). Keterangan: Isi KRS daring.',
            'link' => '/akademik/kalender?reminder=1-start-2026-10-04',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => 2,
            'message' => 'Event "Libur Satu Hari" dimulai dan berakhir 3 hari lagi (2026-10-04).',
            'link' => '/akademik/kalender?reminder=2-start-end-2026-10-04',
        ]);

        $secondRun = $service->sendUpcomingReminders();

        $this->assertSame(['events' => 2, 'reminders' => 0, 'users' => 2], $secondRun);
        $this->assertDatabaseCount('notifications', 4);
    }

    public function test_end_reminder_is_sent_three_days_before_multi_day_event_ends(): void
    {
        DB::table('academic_calendars')->insert([
            'id' => 1, 'title' => 'Masa UTS', 'start_date' => '2026-09-28',
            'end_date' => '2026-10-04', 'category' => 'UTS',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(AcademicCalendarReminderService::class)->sendUpcomingReminders();

        $this->assertSame(['events' => 1, 'reminders' => 2, 'users' => 2], $result);
        $this->assertDatabaseHas('notifications', [
            'user_id' => 1,
            'message' => 'Event "Masa UTS" akan berakhir 3 hari lagi (2026-10-04).',
            'link' => '/akademik/kalender?reminder=1-end-2026-10-04',
        ]);
        $this->assertDatabaseMissing('notifications', ['user_id' => 3]);
    }
}
