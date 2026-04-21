<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            [
                'name'           => 'Manila Electronics Wholesale',
                'email'          => 'sales@manilaelectronics.com',
                'phone'          => '028001234',
                'address'        => '10 Tutuban Center, C.M. Recto Ave, Manila',
                'contact_person' => 'Robert Lim',
            ],
            [
                'name'           => 'Pacific Office Supplies Co.',
                'email'          => 'orders@pacificoffice.ph',
                'phone'          => '025551234',
                'address'        => '22 Shaw Boulevard, Mandaluyong City',
                'contact_person' => 'Grace Tan',
            ],
            [
                'name'           => 'Visayas Hardware Distributors',
                'email'          => 'info@visayashardware.com',
                'phone'          => '032 7651234',
                'address'        => '88 Osmena Blvd, Cebu City',
                'contact_person' => 'Eduardo Flores',
            ],
        ];

        foreach ($suppliers as $data) {
            Supplier::create($data);
        }

        $this->command->info('✅ Suppliers seeded: ' . count($suppliers));
    }
}
