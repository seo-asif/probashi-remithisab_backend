<?php

namespace Tests\Feature\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\DeviceRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'a1b2c3d4e5f60718';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('registration_devices.hash_key', str_repeat('a', 64));
        config()->set('registration_devices.max_registrations', 5);
        config()->set('registration_devices.reservation_hours', 24);
    }

    private function service(): DeviceRegistrationService
    {
        return app(DeviceRegistrationService::class);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function reserve(User $user, bool $confirmed = false): void
    {
        DB::transaction(fn() =>
            $this->service()->reserve($user, self::DEVICE, $confirmed)
        );
    }

    public function test_device_hash_is_keyed_and_deterministic(): void
    {
        $hash = $this->service()->deviceHash(self::DEVICE);

        $this->assertSame(64, strlen($hash));
        $this->assertNotSame(self::DEVICE, $hash);
        $this->assertSame($hash, $this->service()->deviceHash(self::DEVICE));
    }

    public function test_five_registrations_are_allowed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->reserve($this->user(), true);
        }

        $this->assertDatabaseCount('device_registrations', 5);
        $this->assertDatabaseCount('registration_devices', 1);
    }

    public function test_sixth_registration_is_rejected(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->reserve($this->user(), true);
        }

        try {
            $this->reserve($this->user(), true);
            $this->fail('Sixth registration should be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame(
                'DEVICE_REGISTRATION_LIMIT',
                $exception->errorCode
            );
        }

        $this->assertDatabaseCount('device_registrations', 5);
    }

    public function test_unverified_registration_expires_after_24_hours(): void
    {
        $first = $this->user();

        $this->reserve($first);

        $this->assertDatabaseHas('device_registrations', [
            'user_id'      => $first->id,
            'confirmed_at' => null,
        ]);

        $this->travel(25)->hours();

        $this->reserve($this->user(), true);

        $this->assertDatabaseCount('device_registrations', 2);
    }

    public function test_expired_reservation_cannot_be_confirmed(): void
    {
        $user = $this->user();

        $this->reserve($user);

        $this->travel(25)->hours();

        $this->expectException(ApiException::class);

        DB::transaction(fn() => $this->service()->confirm($user));
    }

    public function test_confirming_reservation_consumes_permanent_slot(): void
    {
        $user = $this->user();

        $this->reserve($user);

        DB::transaction(fn() => $this->service()->confirm($user));

        $this->assertDatabaseHas('device_registrations', [
            'user_id'        => $user->id,
            'reserved_until' => null,
        ]);

        $this->assertNotNull(
            DB::table('device_registrations')
                ->where('user_id', $user->id)
                ->value('confirmed_at')
        );
    }

    public function test_confirmed_registration_survives_user_deletion(): void
    {
        $user = $this->user();

        $this->reserve($user, true);

        $user->delete();

        $this->assertDatabaseHas('device_registrations', [
            'user_id' => null,
        ]);
    }

    public function test_different_devices_have_independent_limits(): void
    {
        $firstDevice = 'a1b2c3d4e5f60718';
        $secondDevice = 'b1c2d3e4f5061728';

        for ($i = 0; $i < 5; $i++) {
            DB::transaction(
                fn() => $this->service()->reserve(
                    $this->user(),
                    $firstDevice,
                    true
                )
            );
        }

        DB::transaction(
            fn() => $this->service()->reserve(
                $this->user(),
                $secondDevice,
                true
            )
        );

        $this->assertDatabaseCount('registration_devices', 2);
        $this->assertDatabaseCount('device_registrations', 6);
    }
}
