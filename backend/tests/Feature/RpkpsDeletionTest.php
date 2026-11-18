<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RpkpsController;
use App\Models\Rpkps;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class RpkpsDeletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rpkps', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('status');
            $table->string('verification_code')->nullable()->unique();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('rpkps');
        parent::tearDown();
    }

    public function test_draft_revision_and_approved_rpkps_can_be_deleted(): void
    {
        $this->useAdminRequest();
        $controller = app(RpkpsController::class);

        foreach (['DRAFT', 'REVISI', 'DISETUJUI'] as $index => $status) {
            $rpkps = Rpkps::create(['code' => 'RPS-'.($index + 1), 'status' => $status]);

            $response = $controller->destroy($rpkps);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertDatabaseMissing('rpkps', ['id' => $rpkps->id]);
        }
    }

    public function test_locked_rpkps_cannot_be_deleted(): void
    {
        $this->useAdminRequest();
        $rpkps = Rpkps::create(['code' => 'RPS-LOCKED', 'status' => 'DIKUNCI']);

        $response = app(RpkpsController::class)->destroy($rpkps);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertDatabaseHas('rpkps', ['id' => $rpkps->id, 'status' => 'DIKUNCI']);
    }

    private function useAdminRequest(): void
    {
        $admin = Mockery::mock(User::class);
        $admin->shouldReceive('hasAnyRole')
            ->with(['SUPER_ADMIN', 'ADMIN_AKADEMIK'])
            ->andReturn(true);

        $request = app('request');
        $request->setUserResolver(fn () => $admin);
        $this->assertSame($admin, request()->user());
    }
}
