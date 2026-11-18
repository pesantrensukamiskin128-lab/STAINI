<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthProfilePhotoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->nullable();
            $table->string('password');
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_authenticated_user_can_upload_and_replace_their_profile_photo(): void
    {
        Storage::fake('public');
        $user = User::create([
            'name' => 'User One',
            'email' => 'one@example.test',
            'password' => 'secret',
        ]);
        $this->actingAs($user, 'sanctum');

        $firstResponse = $this->postJson('/api/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('first.png'),
        ]);
        $firstResponse->assertOk()
            ->assertJsonPath('message', 'Foto profil berhasil diperbarui.')
            ->assertJsonPath('user.photo_path', $user->fresh()->photo_path);
        Storage::disk('public')->assertExists($user->fresh()->photo_path);
        $firstPath = $user->fresh()->photo_path;

        $secondResponse = $this->postJson('/api/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('second.png'),
        ]);
        $secondResponse->assertOk()
            ->assertJsonPath('user.photo_path', $user->fresh()->photo_path);

        $this->assertNotSame($firstPath, $user->fresh()->photo_path);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($user->fresh()->photo_path);
    }

    public function test_profile_photo_upload_requires_authentication(): void
    {
        Storage::fake('public');

        $this->postJson('/api/auth/profile/photo', [
            'photo' => UploadedFile::fake()->image('profile.png'),
        ])->assertUnauthorized();
    }
}
