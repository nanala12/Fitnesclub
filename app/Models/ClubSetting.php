<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $club_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $street_address
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $map_url
 */
class ClubSetting extends Model
{
    protected $attributes = ['is_schedule_visible' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_schedule_visible' => 'boolean'];
    }
}
