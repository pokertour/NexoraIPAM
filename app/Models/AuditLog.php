<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var array<string, string> */
    public const ACTIONS = [
        'created' => 'Création',
        'updated' => 'Modification',
        'deleted' => 'Suppression',
        'auth.login' => 'Connexion',
        'auth.failed' => 'Échec de connexion',
        'auth.logout' => 'Déconnexion',
        'password.changed' => 'Mot de passe modifié',
        'two_factor.enabled' => '2FA activée',
        'two_factor.disabled' => '2FA désactivée',
        'settings.updated' => 'Paramètres modifiés',
        'mail.tested' => 'Test SMTP',
        'discovery.queued' => 'Découverte demandée',
        'discovery.completed' => 'Découverte terminée',
        'device.queued' => 'Interrogation SNMP demandée',
        'device.polled' => 'Interrogation SNMP',
        'dhcp.imported' => 'Import DHCP',
        'api_token.created' => 'Jeton d’API créé',
        'api_token.revoked' => 'Jeton d’API révoqué',
        'alert.opened' => 'Alerte ouverte',
        'alert.acknowledged' => 'Alerte acquittée',
    ];

    /** @var array<string, string> */
    public const SUBJECTS = [
        'location' => 'Emplacement',
        'subnet' => 'Sous-réseau',
        'ip_address' => 'Adresse IP',
        'vlan' => 'VLAN',
        'user' => 'Utilisateur',
        'tag' => 'Étiquette',
        'custom_field' => 'Champ personnalisé',
        'network_device' => 'Équipement',
        'rack' => 'Baie',
        'rack_item' => 'Élément de baie',
        'alert' => 'Alerte',
        'settings' => 'Paramètres',
    ];

    protected $fillable = ['user_id', 'actor', 'action', 'subject_type', 'subject_id', 'subject_label', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
