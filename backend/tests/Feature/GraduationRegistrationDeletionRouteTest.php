<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\GraduationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GraduationRegistrationDeletionRouteTest extends TestCase
{
    public function test_deleting_a_graduation_registration_is_limited_to_academic_administrators(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/graduation/registrations/1', 'DELETE'));

        $this->assertSame(GraduationController::class.'@destroyRegistration', ltrim($route->getActionName(), '\\'));
        $this->assertContains('role:SUPER_ADMIN|ADMIN_AKADEMIK', $route->gatherMiddleware());
    }
}
