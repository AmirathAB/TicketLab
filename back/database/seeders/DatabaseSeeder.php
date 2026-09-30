<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Crée le compte administrateur (ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD
     * dans .env) puis les templates prédéfinis. Relançable sans doublon.
     */
    public function run(): void
    {
        $admin = config('ticketlab.admin');

        User::updateOrCreate(
            ['email' => $admin['email']],
            ['name' => $admin['name'], 'password' => Hash::make($admin['password'])]
        );

        $this->call(TemplateSeeder::class);
    }
}
