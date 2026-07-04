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

    protected $allowedFields = [
        'Login', 'Password', 'Nom', 'Prenom', 'Role', 'Id_Departement', 'Actif', 'ChangeLogin',
    ];

    protected $useTimestamps = false;
}
