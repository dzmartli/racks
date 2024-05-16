<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\RackControllers;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;

class DeleteRackControllerTest extends TestCase
{
    use DatabaseMigrations;
    use WithoutMiddleware;

    public $path = '/api/v1/auth/rack'; // @phpstan-ignore-line

    public $now; // @phpstan-ignore-line

    public $firstUser; // @phpstan-ignore-line

    public $secondUser; // @phpstan-ignore-line

    protected function afterRefreshingDatabase(): void
    {
        $this->now = CarbonImmutable::now()->micro(0);
        Carbon::setTestNow($this->now);
        $this->artisan('db:seed');
        $this->firstUser = User::where(['name' => 'first_user'])->first();
        $this->secondUser = User::where(['name' => 'second_user'])->first();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        Carbon::setTestNow(null);
    }

    public function test_noSuchRack(): void
    {
        $this->be($this->firstUser)
            ->json('DELETE', $this->path.'/6')
            ->assertStatus(404)
            ->assertJson(['data' => [
                'message' => 'No such rack',
            ]]);
    }

    public function test_permissionException(): void
    {
        $this->be($this->secondUser)
            ->json('DELETE', $this->path.'/1')
            ->assertStatus(403)
            ->assertJson(['data' => [
                'message' => 'Action not allowed for this department',
            ]]);
    }

    public function test_rackDeleted(): void
    {
        $this->be($this->firstUser)
            ->json('DELETE', $this->path.'/1')
            ->assertStatus(204);
    }
}
