<?php

use App\Models\User;

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $email = 'register-test-' . uniqid() . '@example.com';

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', $email)->first();

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});