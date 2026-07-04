<?php

namespace App\Models;

use CodeIgniter\Model;

class DivisionModel extends Model
{
    protected $table            = 'division';
    protected $primaryKey       = 'Id_Division';
    protected $useAutoIncrement = true;

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['Division', 'Ord', 'Nom', 'Color', 'ArbitrageCRA'];

    protected $useTimestamps = false;
}
