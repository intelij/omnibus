<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Canonical Zimbabwe location dataset. Idempotent via slug+country upsert —
 * safe to run repeatedly; new entries can also be added through admin.
 */
class ZimbabweLocationsSeeder extends Seeder
{
    /** name → [type, province, lat, lng] */
    private const LOCATIONS = [
        'Harare' => ['city', 'Harare', -17.8252, 31.0335],
        'Bulawayo' => ['city', 'Bulawayo', -20.1456, 28.5865],
        'Chitungwiza' => ['city', 'Harare', -18.0127, 31.0756],
        'Mutare' => ['city', 'Manicaland', -18.9735, 32.6696],
        'Gweru' => ['city', 'Midlands', -19.4500, 29.8167],
        'Kwekwe' => ['city', 'Midlands', -18.9281, 29.8149],
        'Masvingo' => ['city', 'Masvingo', -20.0743, 30.8384],
        'Chinhoyi' => ['city', 'Mashonaland West', -17.3667, 30.2000],
        'Marondera' => ['town', 'Mashonaland East', -18.1833, 31.5500],
        'Kadoma' => ['town', 'Mashonaland West', -18.3333, 29.9167],
        'Victoria Falls' => ['city', 'Matabeleland North', -17.9236, 25.8407],
        'Hwange' => ['town', 'Matabeleland North', -18.3643, 26.4959],
        'Bindura' => ['town', 'Mashonaland Central', -17.3000, 31.3333],
        'Zvishavane' => ['town', 'Midlands', -20.3333, 30.0667],
        'Beitbridge' => ['town', 'Matabeleland South', -22.2167, 30.0000],
        'Redcliff' => ['town', 'Midlands', -19.0333, 29.7833],
        'Ruwa' => ['town', 'Mashonaland East', -17.8897, 31.2446],
        'Norton' => ['town', 'Mashonaland West', -17.8833, 30.7000],
        'Kariba' => ['town', 'Mashonaland West', -16.5167, 28.8000],
        'Karoi' => ['town', 'Mashonaland West', -16.8081, 29.6925],
        'Rusape' => ['town', 'Manicaland', -18.5333, 32.1333],
        'Chipinge' => ['town', 'Manicaland', -20.1883, 32.6237],
        'Chiredzi' => ['town', 'Masvingo', -21.0500, 31.6667],
        'Chegutu' => ['town', 'Mashonaland West', -18.1301, 30.1407],
        'Shurugwi' => ['town', 'Midlands', -19.6667, 30.0000],
        'Plumtree' => ['town', 'Matabeleland South', -20.4833, 27.8167],
        'Gokwe' => ['town', 'Midlands', -18.2167, 28.9333],
        'Mutoko' => ['town', 'Mashonaland East', -17.4000, 32.2333],
        'Murehwa' => ['town', 'Mashonaland East', -17.6433, 31.7840],
        'Nyanga' => ['town', 'Manicaland', -18.2167, 32.7500],
        'Lupane' => ['town', 'Matabeleland North', -18.9167, 27.8000],
        'Filabusi' => ['town', 'Matabeleland South', -20.5333, 29.2833],
        'Esigodini' => ['town', 'Matabeleland South', -20.3000, 28.9333],
    ];

    public function run(): void
    {
        foreach (self::LOCATIONS as $name => [$type, $province, $lat, $lng]) {
            Location::updateOrCreate(
                ['slug' => Str::slug($name), 'country_code' => 'ZW'],
                [
                    'name' => $name, 'type' => $type, 'province' => $province,
                    'latitude' => $lat, 'longitude' => $lng, 'is_active' => true,
                ],
            );
        }
    }
}
