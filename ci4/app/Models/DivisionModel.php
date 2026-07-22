<?php

namespace App\Models;

use CodeIgniter\Model;

class DivisionModel extends Model
{
    protected $table            = 'division';
    protected $primaryKey       = 'Division';
    protected $useAutoIncrement = false;

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['Division', 'Ord', 'Nom', 'Color', 'ArbitrageCRA'];

    protected $useTimestamps = false;
}
