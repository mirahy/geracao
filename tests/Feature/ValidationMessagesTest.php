<?php

namespace Tests\Feature;

use App\Filament\Pages\RegisterUser;
use App\Filament\Resources\ConsumerUnits\Pages\CreateConsumerUnit;
use App\Models\ConsumerUnit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_translation_covers_every_laravel_rule(): void
    {
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $portuguese = require lang_path('pt_BR/validation.php');

        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($portuguese))));
        $this->assertSame('pt_BR', app()->getLocale());
    }

    public function test_rule_messages_are_translated_instead_of_showing_the_key(): void
    {
        $this->assertSame('O campo e-mail é obrigatório.', trans('validation.required', ['attribute' => 'e-mail']));
        $this->assertStringNotContainsString('validation.', trans('validation.unique', ['attribute' => 'e-mail']));
        $this->assertStringNotContainsString('validation.', trans('validation.max.string', ['attribute' => 'nome', 'max' => 5]));
    }

    public function test_duplicate_email_on_registration_shows_a_readable_message(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['email' => 'ja@existe.com']);

        Livewire::test(RegisterUser::class)
            ->fillForm(['name' => 'Novo', 'email' => 'ja@existe.com', 'password' => 'segredo123', 'passwordConfirmation' => 'segredo123'])
            ->call('register')
            ->assertHasFormErrors(['email'])
            ->assertDontSee('validation.unique');
    }

    public function test_required_and_unique_fields_on_unit_form_show_readable_messages(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAs(User::factory()->administrator()->create());
        ConsumerUnit::create(['name' => 'Existente', 'uc_number' => '12345', 'role' => 'receiver']);

        Livewire::test(CreateConsumerUnit::class)
            ->fillForm(['name' => '', 'uc_number' => '12345'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'uc_number' => 'unique'])
            ->assertDontSee('validation.');
    }
}
