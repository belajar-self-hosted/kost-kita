<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Room;
use App\Support\RoomAvailabilityService;
use Illuminate\Console\Command;

class ExpireNoShowBookings extends Command
{
    protected $signature = 'bookings:expire-no-show';

    protected $description = 'Expire confirmed bookings whose Asia/Jakarta grace period has ended';

    public function handle(RoomAvailabilityService $availability): int
    {
        $now = now('UTC');
        $rooms = Booking::query()
            ->where('status', 'confirmed')
            ->whereNotNull('check_in_deadline_at')
            ->where('check_in_deadline_at', '<=', $now)
            ->select(['tenant_id', 'property_id', 'room_id'])
            ->distinct()
            ->orderBy('tenant_id')
            ->orderBy('property_id')
            ->orderBy('room_id')
            ->get();

        $expiredCount = 0;
        foreach ($rooms as $scope) {
            $room = Room::query()
                ->where('tenant_id', $scope->tenant_id)
                ->where('property_id', $scope->property_id)
                ->whereKey($scope->room_id)
                ->first();
            if ($room) {
                $expiredCount += $availability->reconcileExpiredBookingsForRoom($room);
            }
        }

        $this->info("Expired {$expiredCount} booking(s) to NO_SHOW.");

        return self::SUCCESS;
    }
}
