<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\KrsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class KrsProdiReviewRouteTest extends TestCase
{
    public function test_prodi_review_route_is_not_captured_by_krs_model_binding(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/krs/prodi-review', 'GET'));

        $this->assertSame(KrsController::class.'@prodiReviewIndex', ltrim($route->getActionName(), '\\'));
    }

}
