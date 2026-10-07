<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'devices.view', 'devices.create', 'devices.update', 'devices.delete',
            'devices.lock', 'devices.unlock', 'devices.location', 'devices.camera', 'devices.command',
            'sites.view', 'sites.manage', 'teams.manage', 'users.manage', 'telegram.manage',
            'audit.view', 'settings.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $rolePermissions = [
            'SUPER_ADMIN' => $permissions,
            'ADMIN' => $permissions,
            'OPERATOR' => ['devices.view', 'devices.lock', 'devices.unlock', 'devices.location', 'devices.camera', 'devices.command', 'sites.view'],
            'VIEWER' => ['devices.view', 'sites.view'],
        ];

        foreach ($rolePermissions as $roleName => $rolePermissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($rolePermissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
