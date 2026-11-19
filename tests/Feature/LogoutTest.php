<?php

use App\Models\User;

it('logs the user out and redirects them away from the app', function () {
    $this->actingAs(User::factory()->create());

    $this->post(route('logout'))->assertRedirect();

    $this->assertGuest();
});
