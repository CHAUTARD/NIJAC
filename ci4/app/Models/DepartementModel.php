<?php

namespace App\Models;

use CodeIgniter\Model;

class DepartementModel extends Model
{
    protected $table            = 'departement';
    protected $primaryKey       = 'code';
    protected $useAutoIncrement = false;

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['code', 'nom', 'code_region', 'Limitrophe'];

    protected $useTimestamps = false;
}
