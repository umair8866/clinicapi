<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = Branch::query()->orderBy('id')->limit(2)->pluck('id');
        if ($branches->count() < 2) {
            throw new \RuntimeException('Seed at least two branches first.');
        }
        DB::transaction(function () use ($branches): void {
            foreach ([
                ['admin@gmail.com', 'System Admin', UserRole::Admin, null],
                ['branchadmin@gmail.com', 'Branch Admin', UserRole::ClinicAdmin, $branches[0]],
                ['staff@gmail.com', 'Branch A Staff', UserRole::ClinicUser, $branches[0]],
                ['staffb@gmail.com', 'Branch B Staff', UserRole::ClinicUser, $branches[1]],
            ] as [$email, $name, $role, $branchId]) {
                $user = User::query()->firstOrNew(['email' => $email]);
                $user->name = $name;
                $user->password = 'password';
                $user->role = $role;
                $user->is_active = true;
                $user->save();
                $user->branches()->sync($branchId === null ? [] : [$branchId]);
            }
        });
    }
}
