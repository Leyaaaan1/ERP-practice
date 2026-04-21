<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

/**
 * CustomerSeeder
 * Seeds a handful of realistic customer records for testing the sales flow.
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            [
                'name'    => 'Juan dela Cruz',
                'email'   => 'juan.delacruz@example.com',
                'phone'   => '09171234567',
                'address' => '123 Rizal Street, Binondo, Manila',
            ],
            [
                'name'    => 'Maria Santos',
                'email'   => 'maria.santos@example.com',
                'phone'   => '09281234567',
                'address' => '456 Bonifacio Ave, Makati City',
            ],
            [
                'name'    => 'Tech Solutions Inc.',
                'email'   => 'procurement@techsolutions.ph',
                'phone'   => '028881234',
                'address' => '789 Ayala Ave, Makati City',
            ],
            [
                'name'    => 'Pedro Reyes',
                'email'   => 'pedro.reyes@gmail.com',
                'phone'   => '09351234567',
                'address' => '321 Magsaysay Blvd, Davao City',
            ],
            [
                'name'    => 'Sunshine Hardware Store',
                'email'   => 'orders@sunshinehardware.com',
                'phone'   => '032 412 5678',
                'address' => '55 Colon Street, Cebu City',
            ],
        ];

        foreach ($customers as $data) {
            Customer::create($data);
        }

        $this->command->info('✅ Customers seeded: ' . count($customers));
    }
}
