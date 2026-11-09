<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'provider', 'external_id', 'active', 'must_change_password'])]
#[Hidden(['password', 'remember_token', 'two_factor'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    protected $attributes = [
        'role' => 'viewer',
        'provider' => 'local',
        'active' => true,
        'must_change_password' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor' => 'encrypted:array',
            'active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['email_verified_at', 'password', 'two_factor', 'pending_email'];
    }

    public function auditLabel(): string
    {
        return $this->name.' · '.$this->email;
    }
}
