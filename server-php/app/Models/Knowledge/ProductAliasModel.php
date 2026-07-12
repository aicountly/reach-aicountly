<?php

namespace App\Models\Knowledge;

use CodeIgniter\Model;

class ProductAliasModel extends Model
{
    protected $table         = 'reach_product_aliases';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $allowedFields = [
        'product_id', 'alias', 'source', 'created_by',
    ];

    public function forProduct(int $productId): array
    {
        return $this->where('product_id', $productId)->findAll();
    }

    public function aliasExists(string $alias, int $productId, ?int $excludeId = null): bool
    {
        $builder = $this->where('product_id', $productId)->where('alias', $alias);
        if ($excludeId !== null) {
            $builder = $builder->where('id !=', $excludeId);
        }
        return $builder->countAllResults() > 0;
    }
}
