<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\HonorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HonorJournalReviewRouteTest extends TestCase
{
    public function test_journal_review_routes_are_limited_to_finance_administrators(): void
    {
        $listRoute = Route::getRoutes()->match(Request::create('/api/finance/honor/journal-reviews', 'GET'));
        $reviewRoute = Route::getRoutes()->match(Request::create('/api/finance/honor/journal-reviews/1', 'PATCH'));

        $this->assertSame(HonorController::class.'@lectureJournalReviews', ltrim($listRoute->getActionName(), '\\'));
        $this->assertSame(HonorController::class.'@reviewLectureJournal', ltrim($reviewRoute->getActionName(), '\\'));
        $this->assertContains('role:SUPER_ADMIN|ADMIN_KEUANGAN', $listRoute->gatherMiddleware());
        $this->assertContains('role:SUPER_ADMIN|ADMIN_KEUANGAN', $reviewRoute->gatherMiddleware());
    }
}
