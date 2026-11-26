<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_closes_once_an_account_exists(): void
    {
        User::factory()->create();

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertForbidden();
        $this->assertGuest();
    }

    public function test_registration_can_be_reopened_with_config(): void
    {
        User::factory()->create();
        config(['droids.open_registration' => true]);

        $this->get(route('register'))->assertOk();
    }
}
