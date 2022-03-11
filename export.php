<?php

use src\Config;
use src\ProductService;

require '_bootstrap.php';

$threads = null;
$seed = null;
if(isset($argv[1],$argv[2])){
    $threads = $argv[1];
    $seed = $argv[2];

    if(!is_numeric($threads) || !is_numeric($seed)){
        throw new Exception('Invalid parameters');
    }
}

$limit = 1000;
$page = 1;
$offset = 0;
$storeId = 1;

$productService = new ProductService();

$config = Config::load();
$baseUrl = $config['base_url'];
$headers = ['Content-Type: application/json'];
$file = 'product.csv';

if (file_exists($file)) {
    unlink($file);
}

file_put_contents($file, '"SKU","Name","MPN","Manufacturer","URL"'. "\n");

$index = 1;
for(;;){
    $data = $productService->getData($limit, $offset, $threads, $seed);

    if(!count($data)){
        break;
    }

    $message = '### page ' . ($offset+1) . " ###\n";
    echo $message;

    foreach ($data as $row){
        $id = $row['entity_id'];

        $row = $productService->getRow($row);

        // print_r($row); die;

        $catcsv = null;
        foreach($row['categories'] as $category) {
            $catcsv .=  ' ,' . $category;
        }

        $mpn = null;
        $manufacturer = null;
        foreach ($row['characteristics'] as $characteristic) {
            if (strtolower($characteristic['label']) === 'mpn') {
                $mpn = $characteristic['value'];
            }

            if (strtolower($characteristic['label']) === 'manufacturerid') {
                $manufacturer = $productService->getManufacturerFromId($characteristic['value'], $storeId);
            }
        }

        $current = file_get_contents($file);

        $current .= '"' . $row['sku'] . '","' . $row['name_exact'] . '","' . $row['mpn'] . '","' . $manufacturer . '","' . $row['url'] . '"';

        file_put_contents($file, $current. "\n");

        $message = 'exported product #' . $index . ', id: ' . $id . "\n";
        echo $message;

        $index++;
    }

    $page++;
    $offset += $limit;
}
