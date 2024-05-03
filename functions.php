<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

function upAffiliateManagerImportJS(){
    ?>
        <script>
            (function(){
                function ImportRun(i){
                    jQuery.ajax({
                        url: ajaxurl+'?action=wc-up-affiliate-import-run&run='+i,
                        success: function(result){
                            var n = parseInt(result, 10)
                            if (!isNaN(n)){
                                setTimeout(function(){
                                        ImportRun(n)
                                        document.getElementById('import_process').innerHTML += ' .'
                                }, 100)
                            } else {
                                document.getElementById('import_process').innerHTML = result
                            }
                        },
                        timeout: 600000
                    })
                };
                jQuery(document).ready(function(){
                    ImportRun(1)
                })
            })()
        </script>
    <?php
}

function upAffiliateManagerImportCleanup($import_dir)
{
    array_map('unlink', glob("$import_dir/*"));
    if (is_dir($import_dir)) rmdir($import_dir);
}

/**
 * @return string
 */
function upAffiliateManagerImportProducts2($run = 0): string
{
    $options = get_option(UP_AFFILIATE_MANAGER_OPTIONS);
    $batch_size = isset($options['batch_size']) ? intval($options['batch_size']) : 50;
    $import_dir = __DIR__ . '/temp';

    if ($run === 0) {

        upAffiliateManagerImportCleanup($import_dir);
        if (!is_dir($import_dir)) mkdir($import_dir);

        $client = new Client();
        $token = [
            'token' => $options['token'],
        ];
        $parameters = http_build_query($token);
        try {
            $response = $client->get(UP_AFFILIATE_MANAGER_API_URL . '/rss/' . $options['language'] . '?' . $parameters);
        } catch (GuzzleException $exception) {
            return $exception->getMessage();
        }

        if (empty($response->getBody())) {
            return 'No response from API';
        }

        $rawProducts = json_decode($response->getBody(), true);
        $nProducts = count($rawProducts);

        if ($nProducts === 0) {
            return 'No products found in API';
        }

        $batches = array_chunk($rawProducts, $batch_size);
        $batch_count = count($batches);

        foreach ($batches as $n => $part) {
            file_put_contents(sprintf('%s/part-%d.json', $import_dir, $n+1), json_encode($part));
        }

        return esc_html__("Found $nProducts products. Importing $batch_count batches (~$batch_size products in batch), please don't close this page . . .", UP_AFFILIATE_MANAGER_PROJECT) . upAffiliateManagerImportJS();
    }

    $import_part_file = sprintf('%s/part-%d.json', $import_dir, $run);
    $import_result_file = sprintf('%s/results.json', $import_dir);
    $rawProducts = $imported = $updated = $deleted = [];

    extract(upAffiliateManagerImportIncludeExcludeGroups($options)); //includeGroups[], excludeGroups[]

    if (file_exists($import_result_file)) {
        extract(json_decode(file_get_contents($import_result_file), true));
    }

    if (!file_exists($import_part_file)) {
        if (!empty($includeGroups)) {
            $deleted = upAffiliateManagerImportSetNotIncludedOutOfStock(array_diff(getProductIds(), $imported, $updated));
        }
        upAffiliateManagerImportCleanup($import_dir);
        upAffiliateManagerUpdateOptions();
        return esc_html__('Product import complete', UP_AFFILIATE_MANAGER_PROJECT) . ': '
            . count($imported) . ' ' . esc_html__('imported') . ', '
            . count($updated) . ' ' . esc_html__('updated') . ', '
            . count($deleted) . ' ' . esc_html__('deleted');
    } else {
        $rawProducts = json_decode(file_get_contents($import_part_file), true);
    }

    $products = [];
    foreach ($rawProducts as $n => $product) {
        $products[$product['group_id']][] = $product;
    }
    foreach ($products as $group) {
        if (!empty($includeGroups) && !in_array($group[0]['group_id'], $includeGroups)) {
            continue;
        }
        if (!empty($excludeGroups) && in_array($group[0]['group_id'], $excludeGroups)) {
            continue;
        }
        $product = upAffiliateManagerGetProductBySku($group[0]['group_id']);
        if ($product) {
            $skus = [];
            $attributes = setProductAttributes($group);
            $product->set_attributes($attributes);
            // update
            foreach ($group as $item) {
                $skus[] = (string)$item['product_id'];
                try {
                    $productVariation = getProductVariationBySku($item['product_id']);
                } catch (WC_Data_Exception $exception) {
                    continue;
                }
                if ($productVariation) {
                    // update
                    updateProductVariation($productVariation, $item);
                } else {
                    // add
                    try {
                        addProductVariation($product, $item);
                    } catch (WC_Data_Exception $exception) {
                        continue;
                    }
                }
            }
            $product->set_stock_status();
            // $product->set_category_ids(
            // getCategoryByName($group[0]['category_name'], $group[0]['category_seo_description'])
            // );
            $imageId = $product->get_image_id();
            if (!$imageId) {
                $imageId = upAffiliateManagerGetIdFromPictureUrl($group[0]['image']);
                $product->set_image_id($imageId);
            }
            $productId = $product->save();

            // delete
            $productVariations = $product->get_children();
            $skusOnShop = [];
            foreach ($productVariations as $productVariationId) {
                $productVariation = wc_get_product($productVariationId);
                $skusOnShop[] = (string)$productVariation->get_sku();
            }
            $skusNotInStock = array_diff($skusOnShop, $skus);
            foreach ($skusNotInStock as $skuNotInStock) {
                try {
                    $productVariation = getProductVariationBySku($skuNotInStock);
                } catch (WC_Data_Exception $exception) {
                    continue;
                }
                if ($productVariation) {
                    $productVariation->set_stock_status('outofstock');
                    $productVariation->save();
                }
            }
            $updated[] = $productId;
        } else {
            // add
            $product = new WC_Product_Variable();
            try {
                $product->set_name($group[0]['group_name']);
                $product->set_description($group[0]['product_seo_description'] ?? '');
                $product->set_short_description($group[0]['product_description'] ?? '');
                $product->set_sku($group[0]['group_id']);
                $product->set_category_ids(
                    getCategoryByName($group[0]['category_name'], $group[0]['category_seo_description'])
                );
                $product->set_reviews_allowed(false);
                $product->set_status('publish');
                $product->set_stock_status();
            // $product->set_gallery_image_ids([$imageId]);
            } catch (WC_Data_Exception $exception) {
                return $exception->getMessage();
            }

            $attributes = setProductAttributes($group);
            $product->set_attributes($attributes);
            $productId = $product->save();
            $imageId = upAffiliateManagerGetIdFromPictureUrl($group[0]['image']);
            if ($imageId) {
                $product->set_image_id($imageId);
            }
            $product->save();

            foreach ($group as $item) {
                // add
                try {
                    addProductVariation($product, $item);
                } catch (WC_Data_Exception $exception) {
                    return $exception->getMessage();
                }
            }
            $imported[] = $productId;
        }
    }

    file_put_contents($import_result_file, json_encode(compact('imported', 'updated')));
    unlink($import_part_file);

    return strval(++$run);
}

function upAffiliateManagerSettingsRegisterBatchSize()
{
    $options = get_option(UP_AFFILIATE_MANAGER_OPTIONS);
    if (!isset($options['batch_size'])) {
        $options['batch_size'] = 50;
    }
    echo "<input id = 'wc_up_affiliate_manager_setting_import_batch_size' name='wc_up_affiliate_manager_options[batch_size]' type = 'number' class='regular-text' value = '" . esc_attr($options['batch_size']) . "' />";
    echo "<p class='description' > If import fails - try smaller batch size </p > ";
}

function upAffiliateManagerImportSetNotIncludedOutOfStock($productsNotInStock)
{
    $deleted = [];
    foreach ($productsNotInStock as $productNotInStock) {
        $product = wc_get_product($productNotInStock);
        if ($product) {
            $product->set_stock_status('outofstock');
            $deleted[] = $product->save();
            foreach ($product->get_children() as $productVariationId) {
                $productVariation = wc_get_product($productVariationId);
                $productVariation->set_stock_status('outofstock');
                $productVariation->save();
            }
        }
    }
    return $deleted;
}

function upAffiliateManagerImportIncludeExcludeGroups($options)
{
    $excludeGroups = $includeGroups = [];
    if (isset($options['include_groups']) && $options['include_groups'] !== '') {
        $includeGroups = explode(',', $options['include_groups']);
    }
    if (isset($options['exclude_groups']) && $options['exclude_groups'] !== '') {
        $excludeGroups = explode(',', $options['exclude_groups']);
    }
    return compact('includeGroups', 'excludeGroups');
}
