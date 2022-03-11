<?php

namespace src;

use PDO;

/**
 * Class ProductService
 * @package src
 */
class ProductService{

    /**
     * @var Database
     */
    private $database;

    /**
     * @var \PDO
     */
    private $dbh;

    /**
     * @var
     */
    private $dbPrefix = '';

    public function __construct()
    {
        $this->database = new Database();
        $this->dbh = Database::getConnection();

        /*$config = Config::load();
        $dbConfig = $config['db'];
        $this->dbPrefix = $dbConfig['prefix'];*/
    }

    public function getManufacturerFromId($manId, $storeId = 1)
    {
        $manSql = "SELECT value FROM eav_attribute_option_value WHERE option_id = " . $manId . " AND store_id = " . $storeId;
        $stmt = $this->dbh->prepare($manSql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        $stmt->execute([
            ':limit' => 1,
            ':offset' => 0,
        ]);

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $data[0]['value'];
    }

    /**
     * @param int $limit
     * @param int $offset
     * @param null $threads
     * @param null $seed
     * @return array
     */
    public function getData(int $limit = 100, int $offset = 0, $threads = null, $seed = null)
    {
        $sql = 'SELECT 
                    DISTINCT(cpe.entity_id),
                    cpe.attribute_set_id,
                    cpe.type_id,
                    cpe.sku,
                    cpe.has_options,
                    cpe.required_options,
                    cpe.created_at,
                    cpe.updated_at,
                    categories_aggregated.category_id,
                    categories_aggregated.category_name,
                    ciss.qty as stock_quantity,
                    CONCAT ("https://mdev.cruisercustomizing.com/", url.request_path) AS request_path,
                    url.metadata
                FROM ' . $this->dbPrefix . 'catalog_product_entity cpe
                LEFT JOIN (
                    SELECT 
                        ccp.product_id, 
                        ccp.category_id, 
                        ccev.value as category_name
                    
                    FROM ' . $this->dbPrefix . 'catalog_category_product ccp
                    INNER JOIN ' . $this->dbPrefix . 'catalog_category_entity cce
                    ON ccp.category_id = cce.entity_id
                    INNER JOIN ' . $this->dbPrefix . 'catalog_category_entity_varchar ccev
                    ON ccev.entity_id = ccp.category_id
                    INNER JOIN ' . $this->dbPrefix . 'eav_attribute ea
                    ON ea.attribute_id = ccev.attribute_id
                    WHERE  ea.entity_type_id=3 AND store_id = 0 AND attribute_code = "name"
                ) categories_aggregated
                ON cpe.entity_id = categories_aggregated.product_id
                
                INNER JOIN (SELECT * FROM url_rewrite WHERE metadata != "") url ON url.entity_id = cpe.entity_id
                
                Inner Join catalog_product_entity_int as i on i.entity_id=cpe.entity_id
                Inner Join eav_attribute_option_value as ov on i.value=ov.option_id
                
                LEFT JOIN
                (SELECT * FROM ' . $this->dbPrefix . 'review_entity_summary WHERE entity_type = 1 AND store_id = 0 ) res
                ON cpe.entity_id = res.entity_pk_value
                
                LEFT JOIN
                (SELECT * FROM ' . $this->dbPrefix . 'cataloginventory_stock_status WHERE stock_id = 1 AND website_id = 0 ) ciss
                ON cpe.entity_id = ciss.product_id
                
                WHERE ov.value="OEM Part" 
                
                ';

        if($threads && $seed !== null){
            $sql .= ' AND row_id % ' . $threads . ' = ' . $seed;
        }

        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $this->dbh->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));

        $stmt->execute([
            ':limit' => $limit,
            ':offset' => $offset,
        ]);

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $data;
    }

    /**
     * @param array $row
     * @return array
     */
    public function getRow(array $row){
        $id = $row['entity_id'];
        $data = [
            'id' => $row['entity_id'],
            'sku' => $row['sku'],
            // 'attribute_set_id' => $row['attribute_set_id'],
            // 'type_id' => $row['type_id'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            // 'required_options' => $row['required_options'],
            'categories' => [$row['category_name']],
            'category_ids' => [$row['category_id']],
//            'rating_summary' => $row['rating_summary'],
//            'reviews_count' => $row['reviews_count'],
            'url' => $row['request_path'],
            // 'stock_quantity' => intval($row['stock_quantity'])
        ];

        $characteristics = [];

        $eavAttributes = $this->getEavAttributes($id);

        foreach ($eavAttributes as $attribute){
            $code = $attribute['attribute_code'];
            $value = $attribute['value'];
            $data[$code] = $value;

            if($attribute['is_user_defined']){
                $characteristics[] = [
                    'label' => $attribute['frontend_label'],
                    'value' => $attribute['value']
                ];
            }
        }

        $data['characteristics'] = $characteristics;
        $data['name_exact'] = $data['name'];
        // $data['name_suggest'] = $this->getNameSuggest($data['name']);

        return $data;
    }

    /**
     * @param $entityId
     * @return array
     */
    private function getEavAttributes($entityId){
        $sql = '
            (SELECT
                  entity_id,
                attribute_code,
                frontend_label,
                value,
                is_user_defined,
                store_id
            FROM ' . $this->dbPrefix . 'catalog_product_entity_varchar cpe
            INNER JOIN ' . $this->dbPrefix . 'eav_attribute ea
            ON ea.attribute_id = cpe.attribute_id
            WHERE cpe.entity_id = :entity_id AND ea.entity_type_id=4 AND store_id = 0
            )
            
            UNION
            
            (
            SELECT
                  entity_id,
                attribute_code,
                frontend_label,
                value,
                is_user_defined,
                store_id
            FROM ' . $this->dbPrefix . 'catalog_product_entity_text cpe
            INNER JOIN ' . $this->dbPrefix . 'eav_attribute ea
            ON ea.attribute_id = cpe.attribute_id
            WHERE cpe.entity_id = :entity_id AND ea.entity_type_id=4 AND store_id = 0
            )
            
            UNION
            
            (
            SELECT
                  entity_id,
                attribute_code,
                frontend_label,
                value,
                is_user_defined,
                store_id
            FROM ' . $this->dbPrefix . 'catalog_product_entity_int cpe
            INNER JOIN ' . $this->dbPrefix . 'eav_attribute ea
            ON ea.attribute_id = cpe.attribute_id
            WHERE cpe.entity_id = :entity_id AND ea.entity_type_id=4 AND store_id = 0
            )
            
            UNION
            
            (
            SELECT
                  entity_id,
                attribute_code,
                frontend_label,
                value,
                is_user_defined,
                store_id
            FROM ' . $this->dbPrefix . 'catalog_product_entity_decimal cpe
            INNER JOIN ' . $this->dbPrefix . 'eav_attribute ea
            ON ea.attribute_id = cpe.attribute_id
            WHERE cpe.entity_id = :entity_id AND ea.entity_type_id=4 AND store_id = 0
            )';

        $stmt = $this->dbh->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
        $stmt->execute([':entity_id' => $entityId,]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $data;
    }

    /**
     * @param $name
     * @return array
     */
    private function getNameSuggest($name){
        $words = explode(' ',$name);

        $input = [];
        foreach ($words as $word){
            if(strlen($word) > 3){
                $input[] = $word;
            }
        }

        return [
            "input" => $input
        ];
    }
}
