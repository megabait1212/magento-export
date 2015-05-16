<?php
/*
 * Aten Software Product Data Exporter for Magento
 * 
 * Copyright (c) 2014. Aten Software LLC. All Rights Reserved.
 * Author: Shailesh Humbad
 * Website: http://www.atensoftware.com/p187.php
 *
 * This file is part of Aten Software Product Data Exporter for Magento.
 *
 * Aten Software Product Data Exporter for Magento is free software: 
 * you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Aten Software Product Data Exporter for Magento
 * is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * See http://www.gnu.org/licenses/ for a copy of the GNU General Public License.
 *
 * */

// Uncomment to enable debugging
//ini_set('display_errors', '1');
//ini_set('error_reporting', E_ALL);
// Increase memory limit
// ini_set('memory_limit', '1024M');
// Include the Magento application
define('MAGENTOROOT', realpath(dirname(__FILE__)));
require_once(MAGENTOROOT . '/app/Mage.php');

// Execute the class by constructing it
$exporter = new AtenExporterForMagento();

// Class to hold all functionality for the exporter
class AtenExporterForMagento {

    // Set the password to export data here
    const PASSWORD = '';
    // Version of this script
    const VERSION = '2015-05-16';

    // Helper variables
    private $_tablePrefix;
    private $_storeId;
    private $_websiteId;
    private $_mediaBaseUrl;
    private $_webBaseUrl;
    private $_dbi;
    private $IncludeDisabled;
    private $ExcludeOutOfStock;
    private $DownloadAsAttachment;

    // Initialize the Mage application
    function __construct() {
        // Increase maximum execution time to 4 hours
        ini_set('max_execution_time', 14400);

        // Set working directory to magento root folder
        chdir(MAGENTOROOT);

        // Make files written by the profile world-writable/readable
        umask(0);

        // Initialize the admin application
        Mage::app('admin');

        // Get the table prefix
        $tableName = Mage::getSingleton('core/resource')->getTableName('core_website');
        //var_dump($tableName);
        $this->_tablePrefix = substr($tableName, 0, strpos($tableName, 'core_website'));

        // Get database connection to Magento (PDO MySQL object)
        $this->_dbi = Mage::getSingleton('core/resource')->getConnection('core_read');

        // Set default fetch mode to NUM to save memory
        $this->_dbi->setFetchMode(ZEND_DB::FETCH_NUM);

        // Run the main application
        $this->_runMain();
    }

    // Apply prefix to table names in the query
    private function _applyTablePrefix($query) {
        //var_dump(str_replace('PFX_', $this->_tablePrefix, $query));
        return str_replace('PFX_', $this->_tablePrefix, $query);
    }

    // Run the main application and call the appropriate function
    //   depending on the command.
    private function _runMain() {
        // Get the command line parameters if running in CLI-mode
        if ($this->_isCLI() == true) {
            if ($_SERVER['argc'] == 2) {
                // Get parameters from the command line
                //  and add them to the REQUEST array
                parse_str($_SERVER['argv'][1], $_REQUEST);
            }
        }
        // Get parameters from the REQUEST array
        $Command = isset($_REQUEST['Command']) ? $_REQUEST['Command'] : '';
        $this->_storeId = isset($_REQUEST['Store']) ? $_REQUEST['Store'] : '';
        $Password = isset($_REQUEST['Password']) ? $_REQUEST['Password'] : '';
        $this->ExcludeOutOfStock = (isset($_REQUEST['ExcludeOutOfStock']) && $_REQUEST['ExcludeOutOfStock'] == 'on') ? true : false;
        $this->IncludeDisabled = (isset($_REQUEST['IncludeDisabled']) && $_REQUEST['IncludeDisabled'] == 'on') ? true : false;
        $this->DownloadAsAttachment = (isset($_REQUEST['DownloadAsAttachment']) && $_REQUEST['DownloadAsAttachment'] == 'on') ? true : false;

        // If the command is export, then run the native export
        if ($Command == 'Export') {
            // Check password
            $this->_checkPassword($Password);

            // Validate store and get information
            $this->_getStoreInformation();

            // Run extraction
            $this->_extractFromMySQL();

            // End script
            return;
        }

        // If the command is not export, display the form
        $this->DisplayForm();
    }

    // Extract natively directly from the database
    private function _extractFromMySQL() {
        // Start sending file 
        if ($this->_isCLI() == false) {
            // Set up a file name
            $FileName = sprintf('%d_%d.csv', $this->_websiteId, $this->_storeId);

            $this->_startFileSend($FileName);
        }

        // Check if Amasty Product Labels table exists
        $query = "SHOW TABLES LIKE 'PFX_am_label'";
        $query = $this->_applyTablePrefix($query);
        $AmastyProductLabelsTableExists = $this->_dbi->fetchOne($query);
        $AmastyProductLabelsTableExists = !empty($AmastyProductLabelsTableExists);

        // Create a lookup table for the SKU to label_id
        $AmastyProductLabelsLookupTable = array();
        if ($AmastyProductLabelsTableExists == true) {
            // NOTE: Only fetch simple labels and ignore all matching rules.
            //   include_type=0 means "all matching SKUs and listed SKUs"
            //   include_type=1 means "all matching SKUs EXCEPT listed SKUs"
            //   include_type=2 means "listed SKUs only"
            $query = "SELECT label_id, name, include_sku
				FROM PFX_am_label
				WHERE include_type IN (0,2)
				ORDER BY pos DESC";
            $query = $this->_applyTablePrefix($query);
            $labelsTable = $this->_dbi->fetchAll($query);
            // Load each label into the lookup table
            foreach ($labelsTable as $row) {
                // Get the comma-separated SKUs
                $skus = explode(",", $row[2]);
                // Add each SKU to the lookup table
                foreach ($skus as $sku) {
                    $AmastyProductLabelsLookupTable[$sku] = array($row[0], $row[1]);
                }
            }
        }

        // Increase maximium length for group_concat (for additional image URLs field)
        $query = "SET SESSION group_concat_max_len = 1000000;";
        $this->_dbi->query($query);

        // By default, set media gallery attribute id to 703
        //  Look it up later
        $MEDIA_GALLERY_ATTRIBUTE_ID = 703;


        // Get the entity type for products
        $query = "SELECT entity_type_id FROM PFX_eav_entity_type
			WHERE entity_type_code = 'catalog_product'";
        $query = $this->_applyTablePrefix($query);
        $PRODUCT_ENTITY_TYPE_ID = $this->_dbi->fetchOne($query);


        // Get attribute codes and types
        $query = "SELECT attribute_id, attribute_code, backend_type, frontend_input
			FROM PFX_eav_attribute
			WHERE entity_type_id = $PRODUCT_ENTITY_TYPE_ID
			";
        $query = $this->_applyTablePrefix($query);
        $attributes = $this->_dbi->fetchAssoc($query);
        $attributeCodes = array();
        $blankProduct = array();
        $blankProduct['sku'] = '';
        foreach ($attributes as $row) {
            // Save attribute ID for media gallery
            if ($row['attribute_code'] == 'media_gallery') {
                $MEDIA_GALLERY_ATTRIBUTE_ID = $row['attribute_id'];
            }

            switch ($row['backend_type']) {
                case 'datetime':
                case 'decimal':
                case 'int':
                case 'text':
                case 'varchar':
                    $attributeCodes[$row['attribute_id']] = $row['attribute_code'];
                    $blankProduct[$row['attribute_code']] = '';
                    break;
                case 'static':
                    // ignore columns in entity table
                    // print("Skipping static attribute: ".$row['attribute_code']."\n");
                    break;
                default:
                    // print("Unsupported backend_type: ".$row['backend_type']."\n");
                    break;
            }

            // If the type is multiple choice, cache the option values
            //   in a lookup array for performance (avoids several joins/aggregations)
            if ($row['frontend_input'] == 'select' || $row['frontend_input'] == 'multiselect') {
                // Get the option_id => value from the attribute options
                $query = "
					SELECT
						 CASE WHEN SUM(aov.store_id) = 0 THEN MAX(aov.option_id) ELSE 
							MAX(CASE WHEN aov.store_id = " . $this->_storeId . " THEN aov.option_id ELSE NULL END)
						 END AS 'option_id'
						,CASE WHEN SUM(aov.store_id) = 0 THEN MAX(aov.value) ELSE 
							MAX(CASE WHEN aov.store_id = " . $this->_storeId . " THEN aov.value ELSE NULL END)
						 END AS 'value'
					FROM PFX_eav_attribute_option AS ao
					INNER JOIN PFX_eav_attribute_option_value AS aov
						ON ao.option_id = aov.option_id
					WHERE aov.store_id IN (" . $this->_storeId . ", 0)
						AND ao.attribute_id = " . $row['attribute_id'] . "
					GROUP BY aov.option_id
				";
                $query = $this->_applyTablePrefix($query);
                $result = $this->_dbi->fetchPairs($query);

                // If found, then save the lookup table in the attributeOptions array
                if (is_array($result)) {
                    $attributeOptions[$row['attribute_id']] = $result;
                } else {
                    // Otherwise, leave a blank array
                    $attributeOptions[$row['attribute_id']] = array();
                }
                $result = null;
            }
        }
        $blankProduct['category_ids'] = '';
        $blankProduct['qty'] = 0;
        $blankProduct['stock_status'] = '';
        $blankProduct['parent_id'] = '';
        $blankProduct['entity_id'] = '';
        $blankProduct['created_at'] = '';
        $blankProduct['updated_at'] = '';
        if ($AmastyProductLabelsTableExists == true) {
            $blankProduct['amasty_label_id'] = '';
            $blankProduct['amasty_label_name'] = '';
        }

        // Build queries for each attribute type
        $backendTypes = array(
            'datetime',
            'decimal',
            'int',
            'text',
            'varchar',
        );
        $queries = array();
        foreach ($backendTypes as $backendType) {
            // Get store value if there is one, otherwise, global value
            $queries[] = "
		SELECT CASE WHEN SUM(ev.store_id) = 0 THEN MAX(ev.value) ELSE 
			MAX(CASE WHEN ev.store_id = " . $this->_storeId . " THEN ev.value ELSE NULL END)
			END AS 'value', ev.attribute_id
		FROM PFX_catalog_product_entity
		INNER JOIN PFX_catalog_product_entity_$backendType AS ev
			ON PFX_catalog_product_entity.entity_id = ev.entity_id
		WHERE ev.store_id IN (" . $this->_storeId . ", 0)
		AND ev.entity_type_id = $PRODUCT_ENTITY_TYPE_ID
		AND ev.entity_id = @ENTITY_ID
		GROUP BY ev.attribute_id, ev.entity_id
		";
        }
        $query = implode(" UNION ALL ", $queries);
        $MasterProductQuery = $query;

        // Get all entity_ids for all products in the selected store
        //  into an array - require SKU to be defined
        $query = "
			SELECT cpe.entity_id
			FROM PFX_catalog_product_entity AS cpe
			INNER JOIN PFX_catalog_product_website as cpw
				ON cpw.product_id = cpe.entity_id
			WHERE cpw.website_id = " . $this->_websiteId . "
				AND IFNULL(cpe.sku, '') != ''
		";
        $query = $this->_applyTablePrefix($query);
        // Just fetch the entity_id column to save memory
        $entity_ids = $this->_dbi->fetchCol($query);

        // Print header row
        $headerFields = array();
        $headerFields[] = 'sku';
        foreach ($attributeCodes as $fieldName) {
            $headerFields[] = str_replace('"', '""', $fieldName);
        }
        $headerFields[] = 'category_ids';
        $headerFields[] = 'qty';
        $headerFields[] = 'stock_status';
        $headerFields[] = 'parent_id';
        $headerFields[] = 'entity_id';
        $headerFields[] = 'created_at';
        $headerFields[] = 'updated_at';
        $headerFields[] = 'attribute_set';
        $headerFields[] = 'type';
        if ($AmastyProductLabelsTableExists == true) {
            $headerFields[] = 'amasty_label_id';
            $headerFields[] = 'amasty_label_name';
        }
        print '"' . implode('","', $headerFields) . '"' . "\n";

        // Loop through each product and output the data
        foreach ($entity_ids as $entity_id) {
            // Check if the item is out of stock and skip if needed
            if ($this->ExcludeOutOfStock == true) {
                $query = "
					SELECT stock_status
					FROM PFX_cataloginventory_stock_status AS ciss
					WHERE ciss.website_id = " . $this->_websiteId . "
						AND ciss.product_id = " . $entity_id . "
				";
                $query = $this->_applyTablePrefix($query);
                $stock_status = $this->_dbi->fetchOne($query);
                // If stock status not found or equal to zero, skip the item
                if (empty($stock_status)) {
                    continue;
                }
            }

            // Create a new product record
            $product = $blankProduct;
            $product['entity_id'] = $entity_id;

            // Get the basic product information
            $query = "
				SELECT cpe.sku, cpe.created_at, cpe.updated_at
				FROM PFX_catalog_product_entity AS cpe
				WHERE cpe.entity_id = " . $entity_id . "
			";
            $query = $this->_applyTablePrefix($query);
            $entity = $this->_dbi->fetchRow($query);
            if (empty($entity) == true) {
                continue;
            }

            // Initialize basic product data
            $product['sku'] = $entity[0];
            $product['created_at'] = $entity[1];
            $product['updated_at'] = $entity[2];

            // Set label information
            if ($AmastyProductLabelsTableExists == true) {
                // Check if the SKU has a label
                if (array_key_exists($product['sku'], $AmastyProductLabelsLookupTable) == true) {
                    // Set the label ID and name
                    $product['amasty_label_id'] = $AmastyProductLabelsLookupTable[$product['sku']][0];
                    $product['amasty_label_name'] = $AmastyProductLabelsLookupTable[$product['sku']][1];
                }
            }

            // Fill the master query with the entity ID
            $query = str_replace('@ENTITY_ID', $entity_id, $MasterProductQuery);
            $query = $this->_applyTablePrefix($query);
            $result = $this->_dbi->query($query);

            // Escape the SKU (it may contain double-quotes)
            $product['sku'] = str_replace('"', '""', $product['sku']);

            // Loop through each field in the row and get the value
            while (true) {
                // Get next column
                // $column[0] = value
                // $column[1] = attribute_id
                $column = $result->fetch(Zend_Db::FETCH_NUM);
                // Break if no more rows
                if (empty($column)) {
                    break;
                }
                // Skip attributes that don't exist in eav_attribute
                if (!isset($attributeCodes[$column[1]])) {
                    continue;
                }

                // Translate the option option_id to a value.
                if (isset($attributeOptions[$column[1]]) == true) {
                    // Convert all option values
                    $optionValues = explode(',', $column[0]);
                    $convertedOptionValues = array();
                    foreach ($optionValues as $optionValue) {
                        if (isset($attributeOptions[$column[1]][$optionValue]) == true) {
                            // If a option_id is found, translate it
                            $convertedOptionValues[] = $attributeOptions[$column[1]][$optionValue];
                        }
                    }
                    // Erase values that are set to zero
                    if ($column[0] == '0') {
                        $column[0] = '';
                    } elseif (empty($convertedOptionValues) == false) {
                        //var_dump($convertedOptionValues);
                        // Use convert values if any conversions exist
                        $column[0] = implode(',', $convertedOptionValues);
                    }
                    // Otherwise, leave value as-is					
                }

                // Escape double-quotes and add to product array
                $product[$attributeCodes[$column[1]]] = str_replace('"', '""', $column[0]);
            }
            $result = null;

            // Skip product that are disabled or have no status
            //  if the checkbox is not checked (this is the default setting)
            if ($this->IncludeDisabled == false) {
                if (empty($product['status']) || $product['status'] == Mage_Catalog_Model_Product_Status::STATUS_DISABLED) {
                    continue;
                }
            }

            // Get category information
            $query = "
				SELECT DISTINCT fs.entity_id
				FROM PFX_catalog_category_product_index AS pi
					INNER JOIN PFX_catalog_category_flat_store_" . $this->_storeId . " AS fs
						ON pi.category_id = fs.entity_id
				WHERE pi.product_id = " . $entity_id . "
			";
            $query = $this->_applyTablePrefix($query);
            $categoriesTable = $this->_dbi->fetchAll($query);
            //$categoriesTable = array_reverse($categoriesTable);
            $categoriesT = array_column($categoriesTable, 0);
            //var_dump($categoriesT);
            $product['json_categories'] = implode(',', $categoriesT);
            // Save entire table in JSON format
            //$product['json_categories'] = json_encode($categoriesTable);
            // Escape double-quotes
            //$product['json_categories'] = str_replace('"', '""', $product['json_categories']);
            // Get attribute set
            $query = "
                SELECT attribute_set_name 
                FROM `PFX_eav_attribute_set` 
                WHERE attribute_set_id IN 
                    (SELECT attribute_set_id 
                    FROM `PFX_catalog_product_entity` 
                    WHERE entity_id =" . $entity_id . ")
                ";
            $query = $this->_applyTablePrefix($query);
            $attributeSet = $this->_dbi->fetchOne($query);
            //var_dump($attributeSet);
            $product['attribute_set'] = $attributeSet;

            // Get product type
            $query = "
                    SELECT type_id 
                    FROM `PFX_catalog_product_entity` 
                    WHERE entity_id =" . $entity_id;
            $query = $this->_applyTablePrefix($query);
            $productType = $this->_dbi->fetchOne($query);
            //var_dump($productType);
            $product['type'] = $productType;

            // Get stock quantity
            // NOTE: stock_id = 1 is the 'Default' stock
            $query = "
				SELECT qty, stock_status
				FROM PFX_cataloginventory_stock_status
				WHERE product_id=" . $entity_id . "
					AND website_id=" . $this->_websiteId . "
					AND stock_id = 1";
            $query = $this->_applyTablePrefix($query);
            $stockInfoResult = $this->_dbi->query($query);
            $stockInfo = $stockInfoResult->fetch();
            if (empty($stockInfo) == true) {
                $product['qty'] = '0';
                $product['stock_status'] = '';
            } else {
                $product['qty'] = $stockInfo[0];
                $product['stock_status'] = $stockInfo[1];
            }
            $stockInfoResult = null;

            // Get parent ID
            $query = "
				SELECT GROUP_CONCAT(parent_id SEPARATOR ',') AS parent_id
				FROM PFX_catalog_product_super_link AS super_link
				WHERE super_link.product_id=" . $entity_id . "";
            $query = $this->_applyTablePrefix($query);
            $parentId = $this->_dbi->fetchAll($query);
            if (empty($parentId) != true) {
                // Save value IDs for CJM automatic color swatches extension support
                $product['parent_id'] = $parentId[0][0];
            }

            // Override price with catalog price rule, if found
            $query = "
				SELECT crpp.rule_price
				FROM PFX_catalogrule_product_price AS crpp
				WHERE crpp.rule_date = CURDATE()
					AND crpp.product_id = " . $entity_id . "
					AND crpp.customer_group_id = 1
					AND crpp.website_id = " . $this->_websiteId;
            $query = $this->_applyTablePrefix($query);
            $rule_price = $this->_dbi->fetchAll($query);
            if (empty($rule_price) != true) {
                // Override price with catalog rule price
                $product['price'] = $rule_price[0][0];
            }
            //print_r($product);
            // Print out the line in CSV format
            print '"' . implode('","', $product) . '"' . "\n";
        }

        // Finish sending file 
        if ($this->_isCLI() == false) {
            $this->_endFileSend();
        }
    }

    // Join two URL paths and handle forward slashes
    private function _urlPathJoin($part1, $part2) {
        return rtrim($part1, '/') . '/' . ltrim($part2, '/');
    }

    // Send a output to the client browser as an inline attachment
    // Features: low-memory footprint, gzip compressed if supported
    private function _startFileSend($FileName) {
        // Supply last-modified date
        $gmdate_mod = gmdate('D, d M Y H:i:s', time()) . ' GMT';
        header("Last-Modified: $gmdate_mod");

        // Supply content headers
        header("Content-Type: text/plain; charset=UTF-8");
        $ContentDisposition = ($this->DownloadAsAttachment ? 'attachment' : 'inline');
        header('Content-Disposition: ' . $ContentDisposition . '; filename="' . $FileName . '"');
        // NOTE: Do not supply content-length header, because the file
        // may be sent gzip-compressed in which case the length would be wrong.
        // Add custom headers
        header("X-AtenSoftware-ShoppingCart: Magento " . Mage::getVersion());
        header("X-AtenSoftware-Version: " . self::VERSION);

        // Start gzip-chunked output buffering for faster downloads
        //   if zlib output compression is disabled
        if ($this->_isZlibOutputCompressionEnabled() == false) {
            ob_start("ob_gzhandler", 8192);
        }
    }

    // Finish sending the file
    private function _endFileSend() {
        // Complete output buffering
        if ($this->_isZlibOutputCompressionEnabled() == false) {
            ob_end_flush();
        }
    }

    // Returns true if zlib.output_compression is enabled, otherwise false
    private function _isZlibOutputCompressionEnabled() {
        $iniValue = ini_get('zlib.output_compression');
        return !( empty($iniValue) == true || $iniValue === 'Off' );
    }

    private function DisplayForm() {
        // Set character set to UTF-8
        header("Content-Type: text/html; charset=UTF-8");
        ?>
        <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        <html xmlns="http://www.w3.org/1999/xhtml">
            <head>
                <meta http-equiv="Content-type" content="text/html;charset=UTF-8" />
                <title>Aten Software Product Data Exporter for Magento</title>
                <style type="text/css">
                    fieldset { display: inline; }
                    fieldset label { text-align: left; display: block; }
                </style>
            </head>
            <body>
                <form method="get" action="">
                    <h2 style="text-align:center;"><a href="http://www.atensoftware.com/p187.php">Aten Software Product Data Exporter for Magento</a></h2>

                    <div style="clear:both;"></div>

                    <div style="text-align: center;">

                        <fieldset><legend>Select a store</legend>
                            <table style="margin: 1em auto;" cellpadding="2">
                                <tr>
                                    <th style="background-color:#cccccc;">Select</th>
                                    <th style="background-color:#cccccc;">Website ID</th>
                                    <th style="background-color:#cccccc;">Website</th>
                                    <th style="background-color:#cccccc;">Store ID</th>
                                    <th style="background-color:#cccccc;">Store</th>
                                </tr>
                                <?php
                                // List all active website-stores
                                $query = "SELECT
			 w.website_id
			,w.name as website_name
			,w.is_default
			,s.store_id
			,s.name as store_name
		FROM PFX_core_website AS w 
			INNER JOIN PFX_core_store AS s ON s.website_id = w.website_id
		WHERE s.is_active = 1 AND w.website_id > 0
		ORDER BY w.sort_order, w.name, s.sort_order, s.name";
                                $query = $this->_applyTablePrefix($query);
                                $result = $this->_dbi->query($query);
                                $isChecked = false;
                                while (true) {
                                    // Get next row
                                    $row = $result->fetch(Zend_Db::FETCH_ASSOC);
                                    // Break if no more rows
                                    if (empty($row)) {
                                        break;
                                    }
                                    // Display the store-website details with a radio button
                                    print '<tr>';
                                    print '<td style="text-align:center;">';
                                    print '<input type="radio" name="Store" value="';
                                    print $row['store_id'] . '"';
                                    // Check the first one
                                    if ($isChecked == false) {
                                        print ' checked="checked" ';
                                        $isChecked = true;
                                    }
                                    print '/></td>';
                                    print '<td style="text-align:center;">' . htmlentities($row['website_id']) . '</td>';
                                    print '<td>' . htmlentities($row['website_name']) . '</td>';
                                    print '<td style="text-align:center;">' . htmlentities($row['store_id']) . '</td>';
                                    print '<td>' . htmlentities($row['store_name']) . '</td>';
                                    print '</tr>';
                                    print "\n";
                                }
                                $result = null;
                                ?>
                            </table>
                        </fieldset>

                        <br>

                            <fieldset><legend>Select export options</legend>
                                <label for="ExcludeOutOfStock"><input type="checkbox" id="ExcludeOutOfStock" name="ExcludeOutOfStock" /> Exclude out-of-stock products (stock_status=0)</label>
                                <label for="IncludeDisabled"><input type="checkbox" id="IncludeDisabled" name="IncludeDisabled" /> Include disabled products (status=0)</label>
                            </fieldset>

                            <br>

                                <fieldset><legend>Select download method</legend>
                                    <label for="DownloadAsAttachment"><input type="checkbox" id="DownloadAsAttachment" name="DownloadAsAttachment" /> Check to download as a file (otherwise, the data will be displayed in your browser)</label>
                                </fieldset>

                                <br>

                                    <fieldset><legend>Enter the password</legend>
                                        <input type="text" name="Password" size="20" />
                                    </fieldset>

                                    <br>

                                        <input type="submit" value="Export the Product Data in CSV format" />
                                        <input type="hidden" name="Command" value="Export" />

                                        </div>

                                        </form>

                                        <div style="font-size:smaller; text-align:center; margin-top: 1em;">Copyright 2014 &middot; Aten Software LLC &middot; Version <?php echo self::VERSION; ?></div>
                                        </body>
                                        </html>
                                        <?php
                                    }

                                    // Die if the storeId is invalid
                                    private function _getStoreInformation() {
                                        // Check format of the ID
                                        if (0 == preg_match('|^\d+$|', $this->_storeId)) {
                                            die('ERROR: The specified Store is not formatted correctly: ' . $this->_storeId);
                                        }

                                        try {
                                            // Get the store object
                                            $store = Mage::app()->getStore($this->_storeId);
                                            // Load the store information
                                            $this->_websiteId = $store->getWebsiteId();
                                            $this->_webBaseUrl = $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB);
                                            $this->_mediaBaseUrl = $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA);
                                        } catch (Exception $e) {
                                            die('ERROR: Error getting store information for Store=' . $this->_storeId . ". The store probably does not exist. " . get_class($e) . " " . $e->getMessage());
                                        }
                                    }

                                    // Die if password is invalid
                                    private function _checkPassword($Password) {
                                        // Check if a password is defined
                                        if (self::PASSWORD == '') {
                                            die('ERROR: A blank password is not allowed.  Edit this script and set a password.');
                                        }
                                        // Check the password
                                        if ($Password != self::PASSWORD) {
                                            die('ERROR: The specified password is invalid.');
                                        }
                                    }

                                    // Returns true if running CLI mode
                                    private function _isCLI() {
                                        $sapi_type = php_sapi_name();
                                        return (substr($sapi_type, 0, 3) == 'cli');
                                    }

                                    // Print the results of a select query to output for debugging purposes and exit
                                    private function _debugPrintQuery($query) {
                                        $query = "SELECT 1";
                                        print '<pre>';
                                        print_r($this->_dbi->fetchAll($query));
                                        print '</pre>';
                                        exit();
                                    }

                                }
                                ?>