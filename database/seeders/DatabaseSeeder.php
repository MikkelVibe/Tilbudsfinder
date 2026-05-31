<?php

namespace Database\Seeders;

use App\Enums\GrocerHealthStatus;
use App\Models\Grocer;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ($this->grocers() as $grocer) {
            $existingGrocer = Grocer::query()->where('slug', $grocer['slug'])->first();

            if ($existingGrocer) {
                $existingGrocer->update([
                    'name' => $grocer['name'],
                    'website_url' => $grocer['website_url'],

                ]);

                continue;
            }

            Grocer::factory()->create([
                ...$grocer,
                'is_enabled' => true,
                'health_status' => GrocerHealthStatus::Healthy,
                'next_expected_import_at' => now(),
            ]);
        }

        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );
    }

    /**
     * @return list<array{slug: string, name: string, website_url: string}>
     */
    private function grocers(): array
    {
        return [
            ['slug' => 'rema1000', 'name' => 'REMA 1000', 'website_url' => 'https://rema1000.dk'],
            ['slug' => 'netto', 'name' => 'Netto', 'website_url' => 'https://netto.dk'],
            ['slug' => 'foetex', 'name' => 'føtex', 'website_url' => 'https://www.foetex.dk'],
            ['slug' => 'bilka', 'name' => 'Bilka', 'website_url' => 'https://www.bilka.dk'],
            ['slug' => 'kvickly', 'name' => 'Kvickly', 'website_url' => 'https://kvickly.coop.dk'],
            ['slug' => 'superbrugsen', 'name' => 'SuperBrugsen', 'website_url' => 'https://superbrugsen.coop.dk'],
            ['slug' => 'daglibrugsen', 'name' => "Dagli'Brugsen", 'website_url' => 'https://brugsen.coop.dk'],
            ['slug' => '365discount', 'name' => '365discount', 'website_url' => 'https://365discount.coop.dk'],
            ['slug' => 'meny', 'name' => 'MENY', 'website_url' => 'https://meny.dk'],
            ['slug' => 'spar', 'name' => 'SPAR', 'website_url' => 'https://spar.dk'],
            ['slug' => 'minkobmand', 'name' => 'Min Købmand', 'website_url' => 'https://min-kobmand.dk'],
        ];
    }
}
