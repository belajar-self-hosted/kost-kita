<?php

namespace Tests\Support;

use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;

trait OwnerTestData
{
    /** @return array{owner:User,tenant:Tenant,property:Property,room:Room} */
    protected function createOwnerWithRoom(): array
    {
        $owner = User::factory()->create()->refresh();
        $tenant = Tenant::query()->create([
            'owner_user_id' => $owner->id,
            'display_name' => 'Test Owner',
        ]);
        $property = $tenant->properties()->create(['name' => 'Test Kost']);
        $room = $property->rooms()->create([
            'tenant_id' => $tenant->id,
            'code' => 'A-01',
            'operational_status' => 'rentable',
        ]);

        $this->actingAs($owner);

        return compact('owner', 'tenant', 'property', 'room');
    }
}
