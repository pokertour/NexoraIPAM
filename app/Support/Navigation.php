<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;

/**
 * The map of the workspace: groups of the sidebar, tabs that join related
 * pages, and the pages offered by the global search.
 */
final class Navigation
{
    /**
     * Pages shown as tabs of one sidebar entry, keyed by that entry.
     *
     * @var array<string, list<array{route: string, label: string, keywords: string}>>
     */
    public const TABS = [
        'patching' => [
            ['route' => 'outlets', 'label' => 'Prises murales', 'keywords' => 'brassage prises'],
            ['route' => 'patch-plan', 'label' => 'Plan de brassage', 'keywords' => 'brassage vlan impression'],
        ],
        'settings' => [
            ['route' => 'preferences', 'label' => 'Connexion et e-mails', 'keywords' => 'paramètres smtp ldap openid sso e-mail authentification alertes'],
            ['route' => 'metadata', 'label' => 'Étiquettes et champs', 'keywords' => 'paramètres tags champs personnalisés'],
            ['route' => 'api-tokens', 'label' => 'Jetons d’API', 'keywords' => 'paramètres api rest token'],
        ],
    ];

    /**
     * Sidebar groups. A group without a label stays open; the others fold.
     * An item may stand for several pages: it is active on any of them.
     *
     * @return list<array{key: string, label: ?string, can: ?string, folded: bool, items: list<array{route: string, label: string, icon: string, routes: list<string>, keywords: string}>}>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'main', 'label' => null, 'can' => null, 'folded' => false, 'items' => [
                self::item('dashboard', 'Vue d’ensemble', 'dashboard', keywords: 'accueil tableau de bord dashboard'),
                self::item('alerts', 'Alertes', 'bell', keywords: 'conflits notifications'),
            ]],
            ['key' => 'addressing', 'label' => 'Adressage', 'can' => null, 'folded' => false, 'items' => [
                self::item('subnets', 'Sous-réseaux', 'subnets', keywords: 'réseaux préfixes cidr découpage'),
                self::item('addresses', 'Adresses IP', 'addresses', keywords: 'ip hôtes'),
                self::item('vlans', 'VLAN', 'vlans'),
            ]],
            ['key' => 'infrastructure', 'label' => 'Infrastructure', 'can' => null, 'folded' => false, 'items' => [
                self::item('devices', 'Équipements', 'switch', keywords: 'switchs routeurs snmp ports'),
                self::item('topology', 'Topologie', 'topology', keywords: 'carte liaisons lldp wan internet'),
                self::item('racks', 'Baies', 'rack', keywords: 'racks câbles'),
                self::item('outlets', 'Brassage', 'patch', ['outlets', 'patch-plan']),
                self::item('locations', 'Emplacements', 'locations', keywords: 'sites bâtiments étages'),
            ]],
            ['key' => 'tools', 'label' => 'Diagnostic', 'can' => null, 'folded' => false, 'items' => [
                self::item('tools', 'Outils', 'tools', keywords: 'calculatrice mac fabricant'),
                self::item('scans', 'Historique des scans', 'history', keywords: 'découvertes interrogations'),
            ]],
            ['key' => 'admin', 'label' => 'Administration', 'can' => 'administer', 'folded' => true, 'items' => [
                self::item('accounts', 'Utilisateurs', 'accounts', keywords: 'comptes rôles'),
                self::item('preferences', 'Paramètres', 'preferences', ['preferences', 'metadata', 'api-tokens']),
                self::item('audit', 'Journal d’audit', 'audit', keywords: 'historique traces'),
            ]],
        ];
    }

    /**
     * Groups the current user may see.
     *
     * @return list<array{key: string, label: ?string, can: ?string, folded: bool, items: list<array{route: string, label: string, icon: string, routes: list<string>, keywords: string}>}>
     */
    public static function visibleGroups(): array
    {
        return array_values(array_filter(self::groups(), fn (array $group): bool => $group['can'] === null || Gate::allows($group['can'])));
    }

    /**
     * Pages whose name or keywords match a search, tabs included.
     *
     * @return list<array{label: string, detail: ?string, url: string, icon: string}>
     */
    public static function search(string $term): array
    {
        $needle = mb_strtolower($term);
        $pages = [];
        foreach (self::visibleGroups() as $group) {
            foreach ($group['items'] as $item) {
                $tabs = self::tabsOf($item['route']) ?? [['route' => $item['route'], 'label' => $item['label'], 'keywords' => $item['keywords']]];
                foreach ($tabs as $tab) {
                    $label = __($tab['label']);
                    $haystack = mb_strtolower(implode(' ', [$label, $tab['label'], __($item['label']), $item['label'], $tab['keywords']]));
                    if (str_contains($haystack, $needle) && ! isset($pages[$tab['route']])) {
                        $pages[$tab['route']] = [
                            'label' => $label,
                            'detail' => $group['label'] ? __($group['label']).($tab['label'] !== $item['label'] ? ' · '.__($item['label']) : '') : null,
                            'url' => route($tab['route']),
                            'icon' => $item['icon'],
                        ];
                    }
                }
            }
        }

        return array_values($pages);
    }

    /**
     * The tabs a page belongs to, if any.
     *
     * @return list<array{route: string, label: string, keywords: string}>|null
     */
    public static function tabsOf(string $route): ?array
    {
        foreach (self::TABS as $tabs) {
            if (in_array($route, array_column($tabs, 'route'), true)) {
                return $tabs;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $routes
     * @return array{route: string, label: string, icon: string, routes: list<string>, keywords: string}
     */
    private static function item(string $route, string $label, string $icon, array $routes = [], string $keywords = ''): array
    {
        return ['route' => $route, 'label' => $label, 'icon' => $icon, 'routes' => $routes ?: [$route], 'keywords' => $keywords];
    }
}
