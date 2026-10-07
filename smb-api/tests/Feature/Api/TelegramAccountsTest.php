<?php

use App\Models\TelegramAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

it('requires telegram management permission to list mappings', function (): void {
    $this->getJson('/api/v1/telegram/accounts')->assertUnauthorized();

    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/telegram/accounts')->assertForbidden();
});

it('limits telegram mapping targets to active users in the managers site scope', function (): void {
    $siteA = createSite('Telegram Scope A', 'telegram-scope-a');
    $siteB = createSite('Telegram Scope B', 'telegram-scope-b');
    app(RolesAndPermissionsSeeder::class)->run();
    $manager = User::factory()->create(['name' => 'Scope Manager']);
    $manager->sites()->attach($siteA->id);
    grantPermission($manager, 'telegram.manage');
    $inside = User::factory()->create(['name' => 'Inside Scope']);
    $inside->sites()->attach($siteA->id);
    $outside = User::factory()->create(['name' => 'Outside Scope']);
    $outside->sites()->attach($siteB->id);
    $admin = User::factory()->create(['name' => 'Administrative User']);
    $admin->assignRole('ADMIN');
    $admin->sites()->attach($siteA->id);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/telegram/accounts/eligible-users?per_page=50')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonFragment(['name' => 'Scope Manager'])
        ->assertJsonFragment(['name' => 'Inside Scope'])
        ->assertJsonMissing(['name' => 'Outside Scope'])
        ->assertJsonMissing(['name' => 'Administrative User']);
});

it('does not reveal or modify telegram mappings outside the managers site scope', function (): void {
    $siteA = createSite('Telegram Visible Scope', 'telegram-visible-scope');
    $siteB = createSite('Telegram Hidden Scope', 'telegram-hidden-scope');
    app(RolesAndPermissionsSeeder::class)->run();
    $manager = User::factory()->create();
    $manager->sites()->attach($siteA->id);
    grantPermission($manager, 'telegram.manage');
    $outside = User::factory()->create();
    $outside->sites()->attach($siteB->id);
    $account = TelegramAccount::create([
        'user_id' => $outside->id,
        'telegram_id' => '1234509876',
        'is_active' => false,
    ]);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/telegram/accounts')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 0)
        ->assertJsonMissing(['telegram_id' => '1234509876']);

    $this->postJson('/api/v1/telegram/accounts', ['user_id' => $outside->id, 'telegram_id' => 5566778899])
        ->assertNotFound();
    $this->patchJson("/api/v1/telegram/accounts/{$account->id}", ['is_active' => true])->assertNotFound();
    $this->deleteJson("/api/v1/telegram/accounts/{$account->id}")->assertNotFound();
});

it('creates pending telegram mappings and lists them with pagination', function (): void {
    $site = createSite('Telegram mapping', 'telegram-mapping');
    $operator = User::factory()->create();
    $operator->sites()->attach($site->id);
    grantPermission($operator, 'telegram.manage');
    $mappedUser = User::factory()->create(['name' => 'Telegram Operator']);
    $mappedUser->sites()->attach($site->id);

    $this->actingAs($operator, 'sanctum')
        ->postJson('/api/v1/telegram/accounts', [
            'user_id' => $mappedUser->id,
            'telegram_id' => 123456789,
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.telegram_id', '123456789');

    $this->getJson('/api/v1/telegram/accounts?is_active=0&per_page=10')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.items.0.user.name', 'Telegram Operator');

    expect(Activity::query()->where('description', 'telegram.account.created')->exists())->toBeTrue();
});

it('requires explicit approval and audits activation and revocation', function (): void {
    $site = createSite('Telegram approval', 'telegram-approval');
    $admin = User::factory()->create();
    $admin->sites()->attach($site->id);
    grantPermission($admin, 'telegram.manage');
    $mappedUser = User::factory()->create();
    $mappedUser->sites()->attach($site->id);
    $account = TelegramAccount::create([
        'user_id' => $mappedUser->id,
        'telegram_id' => '9988776655',
        'is_active' => false,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/v1/telegram/accounts/{$account->id}", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.approved_by', $admin->id);

    $this->deleteJson("/api/v1/telegram/accounts/{$account->id}")->assertOk();
    expect(TelegramAccount::withTrashed()->findOrFail($account->id)->trashed())->toBeTrue()
        ->and(Activity::query()->whereIn('description', ['telegram.account.updated', 'telegram.account.deleted'])->count())->toBe(2);
});

it('validates active local users and unique telegram identity', function (): void {
    $site = createSite('Telegram validation', 'telegram-validation');
    $admin = User::factory()->create();
    $admin->sites()->attach($site->id);
    grantPermission($admin, 'telegram.manage');
    $mappedUser = User::factory()->create();
    $mappedUser->sites()->attach($site->id);
    TelegramAccount::create(['user_id' => $mappedUser->id, 'telegram_id' => '4455667788', 'is_active' => false]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/telegram/accounts', ['user_id' => $mappedUser->id, 'telegram_id' => 4455667788])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('telegram_id');

    $inactiveUser = User::factory()->create(['is_active' => false]);
    $this->postJson('/api/v1/telegram/accounts', ['user_id' => $inactiveUser->id, 'telegram_id' => 5544332211])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
});

it('reuses a revoked Telegram identity only as a new pending mapping', function (): void {
    $site = createSite('Telegram reuse', 'telegram-reuse');
    $admin = User::factory()->create();
    $admin->sites()->attach($site->id);
    grantPermission($admin, 'telegram.manage');
    $target = User::factory()->create();
    $target->sites()->attach($site->id);
    $account = TelegramAccount::create([
        'user_id' => $target->id,
        'telegram_id' => '9090909090',
        'is_active' => true,
        'approved_by' => $admin->id,
        'approved_at' => now(),
    ]);
    $account->delete();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/v1/telegram/accounts', ['user_id' => $target->id, 'telegram_id' => '9090909090'])
        ->assertCreated()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.approved_at', null);

    expect(TelegramAccount::withTrashed()->findOrFail($account->id)->trashed())->toBeFalse();
    expect(Activity::query()->where('description', 'telegram.account.restored')->exists())->toBeTrue();
});
