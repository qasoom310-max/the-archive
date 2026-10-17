<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Erp\Modules\ModuleManager;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Modules\Rental\Livewire\CustomerForm;
use Modules\Rental\Models\RentalCustomer;
use Tests\TestCase;

/**
 * Switching an existing customer between Individual and Company: a super
 * admin always may, anyone else only when granted it by name.
 */
final class CustomerTypeChangeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->install('rental');
        $this->grantEveryone('rental.customer');
    }

    private function customer(): RentalCustomer
    {
        return RentalCustomer::query()->create(['name' => 'A.M Yateem Bros W.L.L', 'type' => RentalCustomer::TYPE_INDIVIDUAL]);
    }

    public function test_a_granted_user_can_switch_a_customer_to_company_and_back(): void
    {
        $this->actingAs(User::factory()->create(['can_change_customer_type' => true]));
        $customer = $this->customer();

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertSee('Company')
            ->set('type', RentalCustomer::TYPE_COMPANY)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(RentalCustomer::TYPE_COMPANY, $customer->refresh()->type);

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->set('type', RentalCustomer::TYPE_INDIVIDUAL)
            ->call('save');
        $this->assertSame(RentalCustomer::TYPE_INDIVIDUAL, $customer->refresh()->type);
    }

    public function test_anyone_else_keeps_the_saved_type(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = $this->customer();

        $form = Livewire::test(CustomerForm::class, ['id' => $customer->id]);
        $form->assertViewHas('canChangeType', false);
        $form->set('type', RentalCustomer::TYPE_COMPANY)->call('save');

        $this->assertSame(RentalCustomer::TYPE_INDIVIDUAL, $customer->refresh()->type);
    }

    public function test_a_super_admin_still_can(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_super_admin' => true]));
        $customer = $this->customer();

        Livewire::test(CustomerForm::class, ['id' => $customer->id])
            ->assertViewHas('canChangeType', true);
    }

    public function test_the_migration_grants_abbas_ali_and_via_only(): void
    {
        $abbas = User::factory()->create(['name' => 'Abbas Hamdan', 'email' => 'hamdanabbas98@gmail.com']);
        $ali = User::factory()->create(['name' => 'ali', 'email' => 'ali.x@wanaan-bh.com']);
        $via = User::factory()->create(['name' => 'Via Office', 'email' => 'via@wanaan-bh.com']);
        $other = User::factory()->create(['name' => 'Alison', 'email' => 'alison@wanaan-bh.com']);

        $migration = require base_path('database/migrations/2026_10_17_100001_add_can_change_customer_type_to_users.php');
        $migration->up();

        foreach ([$abbas, $ali, $via] as $user) {
            $this->assertTrue($user->refresh()->canChangeCustomerType(), $user->name);
        }
        $this->assertFalse($other->refresh()->canChangeCustomerType());
    }
}
