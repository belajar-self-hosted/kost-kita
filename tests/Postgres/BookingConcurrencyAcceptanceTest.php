<?php

namespace Tests\Postgres;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Resident;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BookingService;
use App\Support\RoomAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class BookingConcurrencyAcceptanceTest extends TestCase
{
    private ?int $tenantId = null;

    private ?int $ownerId = null;

    private ?int $propertyId = null;

    private ?int $roomId = null;

    protected function setUp(): void
    {
        $requiredDatabase = getenv('KOSTKITA_PG_TEST_DATABASE');
        $configuredDatabase = getenv('DB_DATABASE');
        if (! $requiredDatabase || ! $configuredDatabase || $requiredDatabase !== $configuredDatabase || getenv('DB_CONNECTION') !== 'pgsql') {
            throw new RuntimeException('Set DB_CONNECTION=pgsql and set DB_DATABASE to the same dedicated database name as KOSTKITA_PG_TEST_DATABASE before running this suite.');
        }

        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The PostgreSQL concurrency acceptance suite requires the pgsql driver.');
        }
        if (! Schema::hasTable('bookings') || ! Schema::hasTable('properties')) {
            throw new RuntimeException('The dedicated PostgreSQL test database must have the KostKita migrations applied before this suite runs.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tenantId !== null) {
            DB::table('audit_logs')->where('tenant_id', $this->tenantId)->delete();
            DB::table('invoice_adjustments')->where('tenant_id', $this->tenantId)->delete();
            DB::table('booking_grace_extensions')->where('tenant_id', $this->tenantId)->delete();
            DB::table('tenancy_extensions')->where('tenant_id', $this->tenantId)->delete();
            DB::table('invoice_lines')->where('tenant_id', $this->tenantId)->delete();
            DB::table('invoices')->where('tenant_id', $this->tenantId)->delete();
            DB::table('deposit_accounts')->where('tenant_id', $this->tenantId)->delete();
            DB::table('tenancies')->where('tenant_id', $this->tenantId)->delete();
            DB::table('maintenance_jobs')->where('tenant_id', $this->tenantId)->delete();
            DB::table('bookings')->where('tenant_id', $this->tenantId)->delete();
            DB::table('residents')->where('tenant_id', $this->tenantId)->delete();
            DB::table('rooms')->where('tenant_id', $this->tenantId)->delete();
            DB::table('room_types')->where('tenant_id', $this->tenantId)->delete();
            DB::table('properties')->where('tenant_id', $this->tenantId)->delete();
            DB::table('tenants')->where('id', $this->tenantId)->delete();
            DB::table('users')->where('id', $this->ownerId)->delete();
        }

        parent::tearDown();
    }

    public function test_two_concurrent_confirmations_cannot_create_overlapping_confirmed_bookings(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createCommittedRoomFixture();
        $first = $this->pendingBooking($owner, $tenant, $property, $room, '2026-11-10', '2026-12-10', 'Concurrent One');
        $second = $this->pendingBooking($owner, $tenant, $property, $room, '2026-11-15', '2026-12-15', 'Concurrent Two');

        $results = $this->runConcurrent([
            ['confirm', $first->id],
            ['confirm', $second->id],
        ]);

        $this->assertSame(1, Booking::query()->where('tenant_id', $tenant->id)->where('status', 'confirmed')->count(), implode(', ', $results));
        $this->assertContains('confirmed', $results);
        $this->assertContains('rejected', $results);
    }

    public function test_no_show_expiry_racing_with_new_booking_confirmation_leaves_one_valid_blocker(): void
    {
        ['owner' => $owner, 'tenant' => $tenant, 'property' => $property, 'room' => $room] = $this->createCommittedRoomFixture();
        $expired = $this->pendingBooking($owner, $tenant, $property, $room, '2026-11-10', '2026-12-10', 'Expired Booking');
        $expired->update([
            'status' => 'confirmed',
            'confirmed_at' => Carbon::parse('2026-11-09 12:00:00', 'Asia/Jakarta')->utc(),
            'check_in_deadline_at' => Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta')->utc(),
        ]);
        $newBooking = $this->pendingBooking($owner, $tenant, $property, $room, '2026-11-10', '2026-12-10', 'New Booking');
        $this->travelTo(Carbon::parse('2026-11-12 00:00:00', 'Asia/Jakarta'));

        $results = $this->runConcurrent([
            ['expire', $room->id],
            ['confirm', $newBooking->id],
        ]);

        $this->assertSame('no_show', $expired->fresh()->status, implode(', ', $results));
        $this->assertSame('confirmed', $newBooking->fresh()->status, implode(', ', $results));
        $this->assertSame(1, Booking::query()->where('tenant_id', $tenant->id)->where('status', 'confirmed')->count());
    }

    /** @param array<int, array{0:string,1:int}> $operations
     * @return array<int,string>
     */
    private function runConcurrent(array $operations): array
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('This acceptance test requires the PHP pcntl extension to run concurrent database requests.');
        }

        $directory = sys_get_temp_dir().'/kostkita-pg-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the PostgreSQL concurrency barrier directory.');
        }

        DB::disconnect();
        $children = [];
        foreach ($operations as $index => [$operation, $recordId]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork a PostgreSQL acceptance worker.');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::reconnect();
                    file_put_contents($directory.'/ready-'.$index, '1');
                    $deadline = microtime(true) + 20;
                    while (! is_file($directory.'/go') && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (! is_file($directory.'/go')) {
                        throw new RuntimeException('Timed out waiting for the concurrent start barrier.');
                    }

                    $result = $this->performOperation($operation, $recordId);
                    file_put_contents($directory.'/result-'.$index, $result);
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents($directory.'/result-'.$index, 'error: '.$exception::class.' '.$exception->getMessage());
                    exit(1);
                }
            }

            $children[] = [$pid, $index];
        }

        $deadline = microtime(true) + 20;
        while (count(glob($directory.'/ready-*') ?: []) < count($operations) && microtime(true) < $deadline) {
            usleep(10000);
        }
        file_put_contents($directory.'/go', '1');

        foreach ($children as [$pid]) {
            pcntl_waitpid($pid, $status);
            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException('A PostgreSQL concurrency worker failed: '.implode(', ', glob($directory.'/result-*') ?: []));
            }
        }
        DB::reconnect();

        $results = [];
        foreach (array_keys($operations) as $index) {
            $result = file_get_contents($directory.'/result-'.$index);
            if (! is_string($result) || str_starts_with($result, 'error:')) {
                throw new RuntimeException('A PostgreSQL concurrency worker failed: '.($result ?: 'missing result'));
            }
            $results[] = $result;
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);

        return $results;
    }

    private function performOperation(string $operation, int $recordId): string
    {
        try {
            if ($operation === 'confirm') {
                $booking = Booking::query()->whereKey($recordId)->firstOrFail();
                app(BookingService::class)->confirm($booking->tenant, $booking->tenant->ownerUser, $booking->id);

                return 'confirmed';
            }

            $room = Room::query()->whereKey($recordId)->firstOrFail();
            app(RoomAvailabilityService::class)->reconcileExpiredBookingsForRoom($room);

            return 'expired';
        } catch (ValidationException) {
            return 'rejected';
        }
    }

    /** @return array{owner:User,tenant:Tenant,property:Property,room:Room} */
    private function createCommittedRoomFixture(): array
    {
        $owner = User::factory()->create()->refresh();
        $tenant = Tenant::query()->create(['owner_user_id' => $owner->id, 'display_name' => 'PG Concurrency '.bin2hex(random_bytes(4))]);
        $property = $tenant->properties()->create(['name' => 'PG Race Property']);
        $room = $property->rooms()->create([
            'tenant_id' => $tenant->id,
            'code' => 'PG-'.bin2hex(random_bytes(3)),
            'operational_status' => 'rentable',
        ]);
        $this->tenantId = $tenant->id;
        $this->ownerId = $owner->id;
        $this->propertyId = $property->id;
        $this->roomId = $room->id;

        return compact('owner', 'tenant', 'property', 'room');
    }

    private function pendingBooking(User $owner, Tenant $tenant, Property $property, Room $room, string $start, string $end, string $name): Booking
    {
        $resident = Resident::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => $name,
            'whatsapp_number' => '+628123456789',
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'room_id' => $room->id,
            'resident_id' => $resident->id,
            'status' => 'pending',
            'planned_start_date' => $start,
            'planned_end_exclusive' => $end,
            'agreed_monthly_rent_rupiah' => 1500000,
            'created_by' => $owner->id,
        ]);
    }
}
