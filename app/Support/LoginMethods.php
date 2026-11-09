<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Sign-in methods shown on the login page, with the label and icon chosen in
 * the settings, the preferred one first.
 */
class LoginMethods
{
    /** @var array<string, string> Icons a sign-in button can show, by icon name. */
    public const ICONS = [
        'key' => 'Clé', 'shield' => 'Bouclier', 'building' => 'Bâtiment', 'cloud' => 'Nuage',
        'globe' => 'Globe', 'accounts' => 'Utilisateurs', 'microsoft' => 'Microsoft',
    ];

    /** @var array<string, array{label: string, icon: string}> */
    public const DEFAULTS = [
        'local' => ['label' => 'Compte interne', 'icon' => 'accounts'],
        'ldap' => ['label' => 'Annuaire LDAP', 'icon' => 'building'],
        'oidc' => ['label' => 'Connexion avec OpenID Connect', 'icon' => 'key'],
    ];

    /**
     * Enabled methods, the preferred one first. The local form is always
     * offered: the installation account keeps it as a fallback.
     *
     * @return list<array{key: string, label: string, icon: string}>
     */
    public static function available(): array
    {
        $methods = [];
        foreach (['local', 'ldap', 'oidc'] as $key) {
            $settings = $key === 'local' ? [] : Setting::read($key);
            if ($key !== 'local' && ! ($settings['enabled'] ?? false)) {
                continue;
            }
            $icon = $settings['button_icon'] ?? null;
            $methods[] = [
                'key' => $key,
                'label' => filled($settings['button_label'] ?? null) ? $settings['button_label'] : __(self::DEFAULTS[$key]['label']),
                'icon' => isset(self::ICONS[$icon]) ? $icon : self::DEFAULTS[$key]['icon'],
            ];
        }

        $primary = Setting::read('login')['primary'] ?? 'local';
        usort($methods, fn (array $left, array $right): int => ($right['key'] === $primary) <=> ($left['key'] === $primary));

        return $methods;
    }
}
