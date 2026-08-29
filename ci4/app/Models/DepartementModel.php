<?php

namespace App\Models;

use CodeIgniter\Model;

class DepartementModel extends Model
{
    protected $table            = 'departement';
    protected $primaryKey       = 'CodeDept';
    protected $useAutoIncrement = false;

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['CodeDept', 'nom', 'code_region', 'Limitrophe'];

    protected $useTimestamps = false;
}
