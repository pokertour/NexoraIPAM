<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class ExternalAccounts
{
    public function resolve(string $provider, string $identifier, ?string $email, ?string $name): ?User
    {
        $identity = User::where('provider', $provider)->where('external_id', $identifier);
        $user = (clone $identity)->first();
        if ($user) {
            return $user->active ? $user : null;
        }

        $email = strtolower(trim($email ?? ''));
        if (strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        if ($provider === 'ldap' && $email !== '') {
            try {
                User::where('provider', 'ldap')->whereNull('external_id')->where('email', $email)
                    ->update(['external_id' => $identifier]);
            } catch (UniqueConstraintViolationException $exception) {
                if (! (clone $identity)->exists()) {
                    throw $exception;
                }
            }

            $legacy = (clone $identity)->first();
            if ($legacy) {
                return $legacy->active ? $legacy : null;
            }
        }

        $fallback = $provider.'-'.substr($identifier, 0, 48).'@accounts.invalid';
        $contact = $email !== '' && ! User::where('email', $email)->exists() ? $email : $fallback;
        $attributes = [
            'name' => mb_substr(trim($name ?? '') ?: ($email ?: 'Compte '.strtoupper($provider)), 0, 100),
            'email' => $contact,
            'password' => Str::random(48),
            'role' => 'viewer',
        ];

        try {
            $user = User::firstOrCreate(['provider' => $provider, 'external_id' => $identifier], $attributes);
        } catch (UniqueConstraintViolationException $exception) {
            if ($contact === $fallback) {
                throw $exception;
            }

            $user = User::firstOrCreate(['provider' => $provider, 'external_id' => $identifier], [
                'email' => $fallback,
            ] + $attributes);
        }

        return $user->active ? $user : null;
    }
}
