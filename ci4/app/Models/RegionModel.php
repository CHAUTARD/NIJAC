<?php

namespace App\Models;

use CodeIgniter\Model;

class RegionModel extends Model
{
    protected $table            = 'region';
    protected $primaryKey       = 'code';
    protected $useAutoIncrement = false;

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;

    protected $allowedFields = ['code', 'nom', 'Gentile', 'chef_lieu'];

    protected $useTimestamps = false;
}
