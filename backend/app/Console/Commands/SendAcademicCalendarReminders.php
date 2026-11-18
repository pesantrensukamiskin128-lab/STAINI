<?php

namespace App\Console\Commands;

use App\Services\AcademicCalendarReminderService;
use Illuminate\Console\Command;

class SendAcademicCalendarReminders extends Command
{
    protected $signature = 'academic-calendar:send-reminders';

    protected $description = 'Kirim pengingat kalender akademik tiga hari sebelum event dimulai atau berakhir.';

    public function handle(AcademicCalendarReminderService $reminders): int
    {
        $result = $reminders->sendUpcomingReminders();

        $this->info(sprintf(
            'Event diperiksa: %d; notifikasi dikirim: %d; akun aktif: %d.',
            $result['events'],
            $result['reminders'],
            $result['users'],
        ));

        return self::SUCCESS;
    }
}
