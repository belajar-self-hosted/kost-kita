<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\CashTransaction;
use App\Models\DepositAccount;
use App\Models\DepositSettlement;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MaintenanceJob;
use App\Models\Property;
use App\Models\Resident;
use App\Models\Room;
use App\Models\RoomPrice;
use App\Models\RoomType;
use App\Models\Tenancy;
use App\Policies\BookingPolicy;
use App\Policies\CashTransactionPolicy;
use App\Policies\DepositAccountPolicy;
use App\Policies\DepositSettlementPolicy;
use App\Policies\ExpensePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\MaintenanceJobPolicy;
use App\Policies\PropertyPolicy;
use App\Policies\ResidentPolicy;
use App\Policies\RoomPolicy;
use App\Policies\RoomPricePolicy;
use App\Policies\RoomTypePolicy;
use App\Policies\TenancyPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Property::class, PropertyPolicy::class);
        Gate::policy(Room::class, RoomPolicy::class);
        Gate::policy(RoomType::class, RoomTypePolicy::class);
        Gate::policy(RoomPrice::class, RoomPricePolicy::class);
        Gate::policy(Resident::class, ResidentPolicy::class);
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Tenancy::class, TenancyPolicy::class);
        Gate::policy(MaintenanceJob::class, MaintenanceJobPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(CashTransaction::class, CashTransactionPolicy::class);
        Gate::policy(DepositAccount::class, DepositAccountPolicy::class);
        Gate::policy(DepositSettlement::class, DepositSettlementPolicy::class);
        Gate::policy(Expense::class, ExpensePolicy::class);
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
