<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class ExportProducts2 extends Module
{
    public function __construct()
    {
        $this->name = 'exportproducts';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'TuNombre';
        $this->bootstrap = true;
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Export Products to Shopify CSV');
        $this->description = $this->l('Export products from selected categories to a Shopify-compatible CSV file.');
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('displayAdminProductsExtra') &&
            $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/admin.js');
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        }
    }

    public function getContent()
    {
        $output = '';

        // Procesar la descarga si se solicita
        if (Tools::isSubmit('downloadCsv')) {
            $filePath = $this->generateCsv();
            if ($filePath) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="shopify_products_' . date('Y-m-d') . '.csv"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filePath));
                readfile($filePath);
                unlink($filePath);
                exit;
            }
        }

        // Actualizar configuración
        if (Tools::isSubmit('submitExportProducts')) {
            $selectedCategories = Tools::getValue('EXPORT_CATEGORIES');
            $delimiter = Tools::getValue('EXPORT_DELIMITER');
            $options = ["semicolon", "comma"];
            if (!empty($selectedCategories) && in_array($delimiter, $options)) {
                Configuration::updateValue('LUISML_EXPORT_CATEGORIES', implode(',', $selectedCategories));
                Configuration::updateValue('LUISML_EXPORT_DELIMITER', $delimiter);
                $output .= $this->displayConfirmation($this->l('Settings updated.'));
            } else {
                $output .= $this->displayError($this->l('Please select at least one category.'));
            }
        }

        return $output . $this->renderForm();
    }

    public function renderForm()
    {
        $categories = Category::getCategories((int)Context::getContext()->language->id, true, false);
        $options = [];
        foreach ($categories as $category) {
            $options[] = [
                'id_option' => $category['id_category'],
                'name' => str_repeat('&nbsp;', $category['level_depth'] * 5) . $category['name'],
            ];
        }

        $selectedCategories = Configuration::get('LUISML_EXPORT_CATEGORIES')
            ? explode(',', Configuration::get('LUISML_EXPORT_CATEGORIES'))
            : [];


        $optionsDelimiter = array(
            array(
                'id' => 'comma', // Valor único para identificar la opción
                'name' => 'Comma (,)'
            ),
            array(
                'id' => 'semicolon', // Valor único para identificar la opción
                'name' => 'Semicolon (;)'
            )
        );

// Crea el campo select
        $select = array(
            'type' => 'select',
            'label' => 'Elija delimitador',
            'name' => 'EXPORT_DELIMITER',  // El nombre del campo
            'options' => array(
                'query' => $optionsDelimiter,  // Las opciones que definiste
                'id' => 'id',         // El valor que se enviará en el formulario
                'name' => 'name'      // Lo que se verá en el select
            ),
            'desc' => 'Choose a delimiter (comma or semicolon)',  // Descripción opcional
        );

        $form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Export Settings'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Categorías para exportar'),
                        'name' => 'EXPORT_CATEGORIES[]',
                        'multiple' => true,
                        'class' => 'chosen',
                        'options' => [
                            'query' => $options,
                            'id' => 'id_option',
                            'name' => 'name'
                        ],
                        'desc' => $this->l('Select the categories you want to export products from.'),
                        'required' => true
                    ],
                    $select
                ],
                'submit' => [
                    'title' => $this->l('Save Settings'),
                    'class' => 'btn btn-default pull-left'
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'title' => $this->l('Download CSV'),
                        'icon' => 'process-icon-download',
                        'name' => 'downloadCsv',
                        'class' => 'btn btn-default pull-right'
                    ]
                ]
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int)Context::getContext()->language->id;
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submitExportProducts';
        $helper->fields_value = [
            'EXPORT_CATEGORIES[]' => $selectedCategories,
            'EXPORT_DELIMITER' => Configuration::get('LUISML_EXPORT_DELIMITER'),
        ];

        return $helper->generateForm([$form]);
    }

    public function generateCsv()
    {
        $selectedCategories = explode(',', Configuration::get('EXPORT_CATEGORIES'));
        $delimiter = Configuration::get('LUISML_EXPORT_DELIMITER');

        $optionsDelimiter = [
            'comma' => ",",
            'semicolon' => ";",
        ];
        $delimiter = ($optionsDelimiter[$delimiter] ?: ',');

        if (empty($selectedCategories)) {
            return false;
        }

        $products = $this->getProductsFromCategories($selectedCategories);
        if (empty($products)) {
            return false;
        }

        $filePath = _PS_DOWNLOAD_DIR_ . 'shopify_products_' . date('Y-m-d') . '.csv';
        $file = fopen($filePath, 'w');

        // Usar punto y coma como separador para coincidir con el template
        if($delimiter==";") {
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        }
        // Headers exactamente como en el template
        $headers = [
            'Handle', 'Title', 'Body (HTML)', 'Vendor', 'Product Category', 'Type',
            'Tags', 'Published', 'Option1 Name', 'Option1 Value', 'Option2 Name',
            'Option2 Value', 'Option3 Name', 'Option3 Value', 'Variant SKU',
            'Variant Grams', 'Variant Inventory Tracker', 'Variant Inventory Qty',
            'Variant Inventory Policy', 'Variant Fulfillment Service', 'Variant Price',
            'Variant Compare At Price', 'Variant Requires Shipping', 'Variant Taxable',
            'Variant Barcode', 'Image Src', 'Image Position', 'Image Alt Text',
            'Gift Card', 'SEO Title', 'SEO Description',
            'Google Shopping / Google Product Category', 'Google Shopping / Gender',
            'Google Shopping / Age Group', 'Google Shopping / MPN',
            'Google Shopping / AdWords Grouping', 'Google Shopping / AdWords Labels',
            'Google Shopping / Condition', 'Google Shopping / Custom Product',
            'Google Shopping / Custom Label 0', 'Google Shopping / Custom Label 1',
            'Google Shopping / Custom Label 2', 'Google Shopping / Custom Label 3',
            'Google Shopping / Custom Label 4', 'Variant Image', 'Variant Weight Unit',
            'Variant Tax Code', 'Cost per item', 'Price / International',
            'Compare At Price / International', 'Status'
        ];

        fputcsv($file, $headers, $delimiter);

        foreach ($products as $product) {
            // Obtener combinaciones del producto
            $combinations = $this->getProductCombinations($product['id_product']);

            if (empty($combinations)) {
                // Producto sin combinaciones
                $this->writeProductRow($file, $product,$delimiter);
            } else {
                // Producto con combinaciones
                $this->writeProductRowWithCombinations($file, $product, $combinations,$delimiter);
            }
        }

        fclose($file);
        return $filePath;
    }

    private function writeProductRow($file, $product)
    {
        $handle = $this->formatHandle($product['name']);
        $imageUrl = $this->getProductImageUrl($product['id_product'], $product['id_image']);
        $category = $this->getCategoryPath($product['id_category_default']);

        $row = [
            $handle, // Handle
            $product['name'], // Title
            $product['description'], // Body (HTML)
            $product['manufacturer_name'], // Vendor
            $category, // Product Category
            $product['category_name'], // Type
            $product['tags'], // Tags
            $product['active'] ? 'TRUE' : 'FALSE', // Published
            'Title', // Option1 Name - Por defecto usamos 'Title' como en el ejemplo
            $product['name'], // Option1 Value
            '', // Option2 Name
            '', // Option2 Value
            '', // Option3 Name
            '', // Option3 Value
            $product['reference'], // Variant SKU
            $product['weight'] * 1000, // Variant Grams
            'shopify', // Variant Inventory Tracker
            $product['quantity'], // Variant Inventory Qty
            'deny', // Variant Inventory Policy
            'manual', // Variant Fulfillment Service
            $product['price'], // Variant Price
            $product['price_without_reduction'] != $product['price'] ? $product['price_without_reduction'] : '', // Variant Compare At Price
            'TRUE', // Variant Requires Shipping
            'TRUE', // Variant Taxable
            $product['ean13'], // Variant Barcode
            $imageUrl, // Image Src
            '1', // Image Position
            $product['name'], // Image Alt Text
            'FALSE', // Gift Card
            $product['meta_title'], // SEO Title
            $product['meta_description'], // SEO Description
            $category, // Google Shopping / Google Product Category
            'Unisex', // Google Shopping / Gender
            'Adult', // Google Shopping / Age Group
            $product['reference'], // Google Shopping / MPN
            $product['category_name'], // Google Shopping / AdWords Grouping
            $product['tags'], // Google Shopping / AdWords Labels
            'new', // Google Shopping / Condition
            'FALSE', // Google Shopping / Custom Product
            '', // Custom Label 0
            '', // Custom Label 1
            '', // Custom Label 2
            '', // Custom Label 3
            '', // Custom Label 4
            '', // Variant Image
            'g', // Variant Weight Unit
            '', // Variant Tax Code
            $product['wholesale_price'], // Cost per item
            '', // Price / International
            '', // Compare At Price / International
            $product['active'] ? 'active' : 'draft' // Status
        ];

        fputcsv($file, $row, ';');
    }

    private function writeProductRowWithCombinations($file, $product, $combinations)
    {
        // Primera fila con datos del producto principal
        $handle = $this->formatHandle($product['name']);
        $imageUrl = $this->getProductImageUrl($product['id_product'], $product['id_image']);
        $category = $this->getCategoryPath($product['id_category_default']);

        // Escribir fila principal
        $mainRow = [
            $handle,
            $product['name'],
            $product['description'],
            $product['manufacturer_name'],
            $category,
            $product['category_name'],
            $product['tags'],
            $product['active'] ? 'TRUE' : 'FALSE',
            '', // Las opciones se llenarán en las filas de combinaciones
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            'deny',
            'manual',
            '',
            '',
            'TRUE',
            'TRUE',
            '',
            $imageUrl,
            '1',
            $product['name'],
            'FALSE',
            $product['meta_title'],
            $product['meta_description'],
            $category,
            'Unisex',
            'Adult',
            $product['reference'],
            $product['category_name'],
            $product['tags'],
            'new',
            'FALSE',
            '',
            '',
            '',
            '',
            '',
            '',
            'g',
            '',
            $product['wholesale_price'],
            '',
            '',
            $product['active'] ? 'active' : 'draft'
        ];

        fputcsv($file, $mainRow, ';');

        // Escribir filas de combinaciones
        foreach ($combinations as $combination) {
            $options = $this->formatCombinationOptions($combination['attributes']);
            $combinationRow = array_fill(0, count($mainRow), ''); // Inicializar array vacío

            // Llenar solo los campos necesarios para la combinación
            $combinationRow[0] = $handle; // Handle
            $combinationRow[8] = isset($options[0]) ? $options[0]['name'] : ''; // Option1 Name
            $combinationRow[9] = isset($options[0]) ? $options[0]['value'] : ''; // Option1 Value
            $combinationRow[10] = isset($options[1]) ? $options[1]['name'] : ''; // Option2 Name
            $combinationRow[11] = isset($options[1]) ? $options[1]['value'] : ''; // Option2 Value
            $combinationRow[12] = isset($options[2]) ? $options[2]['name'] : ''; // Option3 Name
            $combinationRow[13] = isset($options[2]) ? $options[2]['value'] : ''; // Option3 Value
            $combinationRow[14] = $combination['reference']; // Variant SKU
            $combinationRow[15] = $product['weight'] * 1000; // Variant Grams
            $combinationRow[16] = 'shopify'; // Variant Inventory Tracker
            $combinationRow[17] = $combination['quantity']; // Variant Inventory Qty
            $combinationRow[18] = 'deny'; // Variant Inventory Policy
            $combinationRow[19] = 'manual'; // Variant Fulfillment Service
            $combinationRow[20] = $product['price'] + $combination['price']; // Variant Price
            $combinationRow[23] = 'TRUE'; // Variant Taxable
            $combinationRow[24] = $combination['ean13']; // Variant Barcode
            $combinationRow[45] = 'g'; // Variant Weight Unit

            fputcsv($file, $combinationRow, ';');
        }
    }

    private function getCategoryPath($idCategory)
    {
        $category = new Category($idCategory);
        $path = $category->getName();
        $parent = new Category($category->id_parent);

        while ($parent->id_parent) {
            $path = $parent->getName() . ' > ' . $path;
            $parent = new Category($parent->id_parent);
        }

        return 'Apparel & Accessories > ' . $path;
    }

    // Los demás métodos auxiliares se mantienen igual...
































    public function generateCsv()
    {
        $selectedCategories = explode(',', Configuration::get('LUISML_EXPORT_CATEGORIES'));

        if (empty($selectedCategories)) {
            return false;
        }

        $products = $this->getProductsFromCategories($selectedCategories);
        if (empty($products)) {
            return false;
        }

        $filePath = _PS_DOWNLOAD_DIR_ . 'shopify_products_' . date('Y-m-d') . '.csv';
        $file = fopen($filePath, 'w');

        // CSV Headers para Shopify
        $headers = [
            'Handle', 'Title', 'Body (HTML)', 'Vendor', 'Type', 'Tags', 'Published',
            'Option1 Name', 'Option1 Value', 'Option2 Name', 'Option2 Value',
            'Option3 Name', 'Option3 Value', 'Variant SKU', 'Variant Grams',
            'Variant Inventory Tracker', 'Variant Inventory Qty',
            'Variant Inventory Policy', 'Variant Fulfillment Service',
            'Variant Price', 'Variant Compare At Price', 'Variant Requires Shipping',
            'Variant Taxable', 'Variant Barcode', 'Image Src', 'Image Position',
            'Image Alt Text', 'Gift Card', 'SEO Title', 'SEO Description',
            'Google Shopping / Google Product Category',
            'Google Shopping / Gender',
            'Google Shopping / Age Group',
            'Google Shopping / MPN',
            'Google Shopping / AdWords Grouping',
            'Google Shopping / AdWords Labels',
            'Google Shopping / Condition',
            'Google Shopping / Custom Product',
            'Google Shopping / Custom Label 0',
            'Google Shopping / Custom Label 1',
            'Google Shopping / Custom Label 2',
            'Google Shopping / Custom Label 3',
            'Google Shopping / Custom Label 4',
            'Variant Image',
            'Variant Weight Unit',
            'Variant Tax Code',
            'Cost per item'
        ];

        fputcsv($file, $headers, $delimiter);

        foreach ($products as $product) {
            // Obtener combinaciones del producto
            $combinations = $this->getProductCombinations($product['id_product']);

            if (empty($combinations)) {
                // Producto sin combinaciones
                $this->writeProductRow($file, $product, $delimiter);
            } else {
                // Producto con combinaciones
                foreach ($combinations as $combination) {
                    $this->writeProductRowWithCombination($file, $product, $combination, $delimiter);
                }
            }
        }

        fclose($file);
        return $filePath;
    }

    private function getProductsFromCategories($categoryIds)
    {
        $context = Context::getContext();
        $idLang = (int)$context->language->id;
        $idShop = (int)$context->shop->id;

        $sql = 'SELECT DISTINCT 
                    p.id_product, 
                    pl.name, 
                    pl.description, 
                    m.name as manufacturer_name, 
                    cl.name as category_name,
                    p.reference, 
                    sa.quantity, 
                    p.price,
                    p.wholesale_price as cost_price,
                    p.weight,
                    p.ean13,
                    p.isbn,
                    p.upc,
                    pl.meta_title as seo_title, 
                    pl.meta_description as seo_description,
                    p.active as published,
                    GROUP_CONCAT(DISTINCT tl.name) as tags,
                    im.id_image
                FROM ' . _DB_PREFIX_ . 'product p
                LEFT JOIN ' . _DB_PREFIX_ . 'product_lang pl ON (p.id_product = pl.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product
                LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON (cp.id_category = cl.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
                LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (p.id_product = sa.id_product AND sa.id_shop = ' . $idShop . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'product_tag pt ON p.id_product = pt.id_product
                LEFT JOIN ' . _DB_PREFIX_ . 'tag tl ON (pt.id_tag = tl.id_tag AND tl.id_lang = ' . $idLang . ')
                LEFT JOIN ' . _DB_PREFIX_ . 'image im ON (p.id_product = im.id_product AND im.cover = 1)
                WHERE cp.id_category IN (' . implode(',', array_map('intval', $categoryIds)) . ')
                GROUP BY p.id_product';

        return Db::getInstance()->executeS($sql);
    }

    private function getProductCombinations($idProduct)
    {
        $combinations = [];
        $attrs = Product::getProductAttributesIds($idProduct);

        foreach ($attrs as $attr) {
            $id_product_attribute = $attr['id_product_attribute'];
            $combination = new Combination($id_product_attribute);

            // Obtener los atributos de la combinación
            $attributes = $combination->getAttributesName((int)Context::getContext()->language->id);

            // Obtener el stock de la combinación
            $quantity = StockAvailable::getQuantityAvailableByProduct($idProduct, $id_product_attribute);

            // Obtener el precio específico de la combinación
            $price = Combination::getPrice($id_product_attribute);

            $combinations[] = [
                'id_product_attribute' => $id_product_attribute,
                'attributes' => $attributes,
                'quantity' => $quantity,
                'price' => $price,
                'reference' => $combination->reference,
                'ean13' => $combination->ean13,
                'upc' => $combination->upc
            ];
        }

        return $combinations;
    }

    private function writeProductRow($file, $product, $delimiter = ",")
    {
        $imageUrl = $this->getProductImageUrl($product['id_product'], $product['id_image']);

        $row = [
            $this->formatHandle($product['name']), // Handle
            $product['name'], // Title
            $product['description'], // Body (HTML)
            $product['manufacturer_name'], // Vendor
            $product['category_name'], // Type
            $product['tags'], // Tags
            $product['published'] ? 'true' : 'false', // Published
            '', // Option1 Name
            '', // Option1 Value
            '', // Option2 Name
            '', // Option2 Value
            '', // Option3 Name
            '', // Option3 Value
            $product['reference'], // Variant SKU
            $product['weight'] * 1000, // Variant Grams
            'shopify', // Variant Inventory Tracker
            $product['quantity'], // Variant Inventory Qty
            'deny', // Variant Inventory Policy
            'manual', // Variant Fulfillment Service
            $product['price'], // Variant Price
            '', // Variant Compare At Price
            'true', // Variant Requires Shipping
            'true', // Variant Taxable
            $product['ean13'], // Variant Barcode
            $imageUrl, // Image Src
            '1', // Image Position
            $product['name'], // Image Alt Text
            'false', // Gift Card
            $product['seo_title'], // SEO Title
            $product['seo_description'], // SEO Description
            '', // Google Shopping / Google Product Category
            '', // Google Shopping / Gender
            '', // Google Shopping / Age Group
            '', // Google Shopping / MPN
            '', // Google Shopping / AdWords Grouping
            '', // Google Shopping / AdWords Labels
            'new', // Google Shopping / Condition
            '', // Google Shopping / Custom Product
            '', // Custom Label 0
            '', // Custom Label 1
            '', // Custom Label 2
            '', // Custom Label 3
            '', // Custom Label 4
            '', // Variant Image
            'g', // Variant Weight Unit
            '', // Variant Tax Code
            $product['cost_price'] // Cost per item
        ];

        fputcsv($file, $row, $delimiter);
    }

    private function writeProductRowWithCombination($file, $product, $combination, $delimiter = ",")
    {
        $options = $this->formatCombinationOptions($combination['attributes']);
        $imageUrl = $this->getProductImageUrl($product['id_product'], $product['id_image']);

        $row = [
            $this->formatHandle($product['name']), // Handle
            $product['name'], // Title
            $product['description'], // Body (HTML)
            $product['manufacturer_name'], // Vendor
            $product['category_name'], // Type
            $product['tags'], // Tags
            $product['published'] ? 'true' : 'false', // Published
            isset($options[0]) ? $options[0]['name'] : '', // Option1 Name
            isset($options[0]) ? $options[0]['value'] : '', // Option1 Value
            isset($options[1]) ? $options[1]['name'] : '', // Option2 Name
            isset($options[1]) ? $options[1]['value'] : '', // Option2 Value
            isset($options[2]) ? $options[2]['name'] : '', // Option3 Name
            isset($options[2]) ? $options[2]['value'] : '', // Option3 Value
            $combination['reference'] ?: $product['reference'], // Variant SKU
            $product['weight'] * 1000, // Variant Grams
            'shopify', // Variant Inventory Tracker
            $combination['quantity'], // Variant Inventory Qty
            'deny', // Variant Inventory Policy
            'manual', // Variant Fulfillment Service
            $product['price'] + $combination['price'], // Variant Price
            '', // Variant Compare At Price
            'true', // Variant Requires Shipping
            'true', // Variant Taxable
            $combination['ean13'] ?: $product['ean13'], // Variant Barcode
            $imageUrl, // Image Src
            '1', // Image Position
            $product['name'], // Image Alt Text
            'false', // Gift Card
            $product['seo_title'], // SEO Title
            $product['seo_description'], // SEO Description
            '', // Google Shopping / Google Product Category
            '', // Google Shopping / Gender
            '', // Google Shopping / Age Group
            '', // Google Shopping / MPN
            '', // Google Shopping / AdWords Grouping
            '', // Google Shopping / AdWords Labels
            'new', // Google Shopping / Condition
            '', // Google Shopping / Custom Product
            '', // Custom Label 0
            '', // Custom Label 1
            '', // Custom Label 2
            '', // Custom Label 3
            '', // Custom Label 4
            '', // Variant Image
            'g', // Variant Weight Unit
            '', // Variant Tax Code
            $product['cost_price'] // Cost per item
        ];

        fputcsv($file, $row, $delimiter);
    }

    private function formatCombinationOptions($attributes)
    {
        $options = [];
        $i = 0;
        foreach ($attributes as $attribute) {
            $options[] = [
                'name' => $attribute['group_name'],
                'value' => $attribute['name']
            ];
            $i++;
            if ($i >= 3) break; // Shopify solo permite 3 opciones
        }
        return $options;
    }

    private function formatHandle($name)
    {
        // Convertir a minúsculas y reemplazar espacios por guiones
        $handle = strtolower(str_replace(' ', '-', $name));
        // Eliminar caracteres especiales
        $handle = preg_replace('/[^a-z0-9\-]/', '', $handle);
        // Eliminar guiones múltiples
        $handle = preg_replace('/-+/', '-', $handle);
        return trim($handle, '-');
    }

    private function getProductImageUrl($idProduct, $idImage)
    {
        if (!$idImage) {
            return '';
        }

        $link = new Link();
        return $link->getImageLink('product', $idImage, 'large_default');
    }

    public function getProductFeatures($idProduct)
    {
        $features = Product::getFeaturesStatic($idProduct);
        $result = [];

        foreach ($features as $feature) {
            $featureValue = new FeatureValue($feature['id_feature_value']);
            $result[] = [
                'name' => Feature::getNameStatic($feature['id_feature']),
                'value' => $featureValue->value
            ];
        }

        return $result;
    }
}