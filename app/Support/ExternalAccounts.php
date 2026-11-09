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
        $email = strtolower(trim($email ?? ''));
        if (strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        $user = (clone $identity)->first();
        if ($user) {
            $this->complete($user, $provider, $email, $name);

            return $user->active ? $user : null;
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

    /**
     * Fills in an account created while the provider withheld the profile:
     * the placeholder e-mail and default name give way to the real ones.
     */
    private function complete(User $user, string $provider, string $email, ?string $name): void
    {
        $changes = [];
        if ($email !== '' && str_ends_with($user->email, '@accounts.invalid') && ! User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            $changes['email'] = $email;
        }
        $name = mb_substr(trim($name ?? ''), 0, 100);
        if ($name !== '' && $user->name === 'Compte '.strtoupper($provider)) {
            $changes['name'] = $name;
        }
        if ($changes !== []) {
            $user->update($changes);
        }
    }
}
