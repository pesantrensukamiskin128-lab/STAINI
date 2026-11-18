<?php

namespace App\Services;

use App\Models\AcademicCalendar;
use App\Models\AppNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AcademicCalendarReminderService
{
    /**
     * @return array{events: int, reminders: int, users: int}
     */
    public function sendUpcomingReminders(?CarbonInterface $today = null): array
    {
        $reminderDate = CarbonImmutable::instance($today ?? CarbonImmutable::today())
            ->startOfDay()
            ->addDays(3)
            ->toDateString();
        $events = AcademicCalendar::query()
            ->whereDate('start_date', $reminderDate)
            ->orWhereDate('end_date', $reminderDate)
            ->orderBy('id')
            ->get();
        $activeUserCount = User::query()->where('is_active', true)->count();
        $sentReminders = 0;

        foreach ($events as $event) {
            $startDate = $event->start_date?->toDateString();
            $endDate = $event->end_date?->toDateString() ?? $startDate;
            $startsOnReminderDate = $startDate === $reminderDate;
            $endsOnReminderDate = $endDate === $reminderDate;

            $reminderType = match (true) {
                $startsOnReminderDate && $endsOnReminderDate => 'start-end',
                $startsOnReminderDate => 'start',
                $endsOnReminderDate => 'end',
                default => null,
            };

            if ($reminderType === null) {
                continue;
            }

            $message = $this->messageFor($event, $reminderType, $reminderDate);
            $link = "/akademik/kalender?reminder={$event->id}-{$reminderType}-{$reminderDate}";

            User::query()
                ->where('is_active', true)
                ->select('id')
                ->orderBy('id')
                ->chunkById(500, function ($users) use ($event, $message, $link, &$sentReminders): void {
                    $userIds = $users->pluck('id');
                    $alreadyNotified = AppNotification::query()
                        ->where('link', $link)
                        ->whereIn('user_id', $userIds)
                        ->pluck('user_id')
                        ->all();

                    foreach ($users as $user) {
                        if (in_array($user->id, $alreadyNotified, true)) {
                            continue;
                        }

                        AppNotification::send(
                            (int) $user->id,
                            'Pengingat Kalender Akademik',
                            $message,
                            'info',
                            $link,
                        );
                        $sentReminders++;
                    }
                });
        }

        return [
            'events' => $events->count(),
            'reminders' => $sentReminders,
            'users' => $activeUserCount,
        ];
    }

    private function messageFor(AcademicCalendar $event, string $reminderType, string $reminderDate): string
    {
        $description = trim((string) $event->description);
        $eventDetails = $description !== '' ? " Keterangan: {$description}" : '';

        if ($reminderType === 'start-end') {
            return "Event \"{$event->title}\" dimulai dan berakhir 3 hari lagi ({$reminderDate}).{$eventDetails}";
        }

        if ($reminderType === 'start') {
            return "Event \"{$event->title}\" akan dimulai 3 hari lagi ({$reminderDate}).{$eventDetails}";
        }

        return "Event \"{$event->title}\" akan berakhir 3 hari lagi ({$reminderDate}).{$eventDetails}";
    }
}
