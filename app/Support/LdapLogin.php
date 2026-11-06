<?php

namespace App\Support;

class LdapLogin
{
    /** @return array{id: string, name: ?string}|null */
    public function verify(array $settings, string $email, string $password): ?array
    {
        if (! extension_loaded('ldap') || $password === '' || str_contains($password, "\0") || blank($settings['group_dn'] ?? null) ||
            ! ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND)) {
            return null;
        }

        $connection = ldap_connect($settings['uri']);

        if (! $connection) {
            return null;
        }

        try {
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5);

            if (! @ldap_bind($connection, $settings['bind_dn'], $settings['password'])) {
                return null;
            }

            $mail = ldap_escape($email, '', LDAP_ESCAPE_FILTER);
            $group = ldap_escape($settings['group_dn'], '', LDAP_ESCAPE_FILTER);
            $filter = "(&(objectCategory=person)(objectClass=user)(mail={$mail})(memberOf:1.2.840.113556.1.4.1941:={$group}))";
            $result = @ldap_search($connection, $settings['base_dn'], $filter, ['objectGUID', 'displayName'], 0, 2, 5);
            $entry = $result && ldap_count_entries($connection, $result) === 1
                ? ldap_first_entry($connection, $result)
                : false;
            $dn = $entry ? ldap_get_dn($connection, $entry) : false;

            $guid = $entry ? (@ldap_get_values_len($connection, $entry, 'objectGUID')[0] ?? null) : null;
            $name = $entry ? (@ldap_get_values($connection, $entry, 'displayName')[0] ?? null) : null;
            if (! $dn || ! is_string($guid) || strlen($guid) !== 16 || ! @ldap_bind($connection, $dn, $password)) {
                return null;
            }

            return ['id' => hash('sha256', "ldap\0".$guid), 'name' => $name];
        } finally {
            ldap_unbind($connection);
        }
    }
}
