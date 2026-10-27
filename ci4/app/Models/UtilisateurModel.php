<?php

namespace App\Models;

use CodeIgniter\Model;

class UtilisateurModel extends Model
{
    protected $table            = 'Utilisateur';
    protected $primaryKey       = 'Id_Utilisateur';
    protected $useAutoIncrement = true;

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    // Plus de colonne Role : les rôles vivent dans utilisateur_role (enregistrerRolesUtilisateur()).
    protected $allowedFields = [
        'Login', 'Password', 'Nom', 'Prenom', 'Id_Departement', 'Actif', 'ChangeLogin', 'Email',
    ];

    protected $useTimestamps = false;
}
