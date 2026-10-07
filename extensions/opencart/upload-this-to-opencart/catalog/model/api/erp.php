<?php
class ModelApiErp extends Model
{
    public function getProductCount($filter = [])
    {
        $sql = "SELECT COUNT(*) AS total FROM " . DB_PREFIX . "product p";

        $where = [];
        if (!empty($filter['product_id'])) {
            $where[] = "p.product_id = '" . (int)$filter['product_id'] . "'";
        }
        if (!empty($filter['modified_since'])) {
            $where[] = "p.date_modified >= '" . $this->db->escape($filter['modified_since']) . "'";
        }

        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->query($sql);
        return (int)$result->row['total'];
    }

    public function getProducts($filter = [])
    {
        $sql = "SELECT p.product_id, p.model, p.sku, p.upc, p.ean, p.jan, p.isbn, p.mpn,
                       p.location, p.quantity, p.stock_status_id, p.image, p.manufacturer_id,
                       p.shipping, p.price, p.points, p.tax_class_id, p.date_available,
                       p.weight, p.weight_class_id,
                       p.length, p.width, p.height, p.length_class_id,
                       p.status, p.subtract, p.minimum, p.sort_order, p.viewed,
                       p.date_added, p.date_modified,
                       pd.name, pd.description, pd.meta_title, pd.meta_description, pd.tag
                FROM " . DB_PREFIX . "product p
                LEFT JOIN " . DB_PREFIX . "product_description pd
                    ON p.product_id = pd.product_id AND pd.language_id = '" . (int)$this->config->get('config_language_id') . "'";

        $where = [];
        if (!empty($filter['product_id'])) {
            $where[] = "p.product_id = '" . (int)$filter['product_id'] . "'";
        }
        if (!empty($filter['modified_since'])) {
            $where[] = "p.date_modified >= '" . $this->db->escape($filter['modified_since']) . "'";
        }

        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY p.date_modified ASC";

        $page  = max(1, (int)($filter['page'] ?? 1));
        $limit = min(500, max(1, (int)($filter['limit'] ?? 100)));
        $offset = ($page - 1) * $limit;
        $sql .= " LIMIT " . (int)$offset . ", " . (int)$limit;

        $result = $this->db->query($sql);
        return $result->rows;
    }

    public function getProductCategories($product_id)
    {
        $result = $this->db->query(
            "SELECT category_id FROM " . DB_PREFIX . "product_to_category
             WHERE product_id = '" . (int)$product_id . "'"
        );

        $ids = [];
        foreach ($result->rows as $row) {
            $ids[] = (int)$row['category_id'];
        }
        return $ids;
    }

    public function getProductOptions($product_id)
    {
        $sql = "SELECT po.product_option_id, po.product_id, po.option_id,
                       po.value, po.required
                FROM " . DB_PREFIX . "product_option po
                WHERE po.product_id = '" . (int)$product_id . "'
                ORDER BY po.product_option_id ASC";

        $result = $this->db->query($sql);
        return $result->rows;
    }

    public function getProductOptionValues($product_id)
    {
        $sql = "SELECT pov.product_option_value_id, pov.product_option_id, pov.product_id,
                       pov.option_id, pov.option_value_id,
                       pov.sku, pov.quantity, pov.subtract,
                       pov.price, pov.price_prefix,
                       pov.points, pov.points_prefix,
                       pov.weight, pov.weight_prefix,
                       od.name AS option_name,
                       ovd.name AS option_value_name
                FROM " . DB_PREFIX . "product_option_value pov
                LEFT JOIN " . DB_PREFIX . "option_description od
                    ON pov.option_id = od.option_id AND od.language_id = '" . (int)$this->config->get('config_language_id') . "'
                LEFT JOIN " . DB_PREFIX . "option_value_description ovd
                    ON pov.option_value_id = ovd.option_value_id AND ovd.language_id = '" . (int)$this->config->get('config_language_id') . "'
                WHERE pov.product_id = '" . (int)$product_id . "'
                ORDER BY pov.product_option_value_id ASC";

        $result = $this->db->query($sql);
        return $result->rows;
    }

    public function getProductImages($product_id)
    {
        $result = $this->db->query(
            "SELECT product_image_id, image, sort_order
             FROM " . DB_PREFIX . "product_image
             WHERE product_id = '" . (int)$product_id . "'
             ORDER BY sort_order ASC"
        );
        return $result->rows;
    }

    public function getProductSpecials($product_id)
    {
        $result = $this->db->query(
            "SELECT product_special_id, customer_group_id, priority, price, date_start, date_end
             FROM " . DB_PREFIX . "product_special
             WHERE product_id = '" . (int)$product_id . "'
             ORDER BY priority ASC, price ASC"
        );
        return $result->rows;
    }

    public function getOrderCount($filter = [])
    {
        $sql = "SELECT COUNT(*) AS total FROM " . DB_PREFIX . "order o
                WHERE o.order_status_id > 0";

        if (!empty($filter['modified_since'])) {
            $sql .= " AND o.date_modified >= '" . $this->db->escape($filter['modified_since']) . "'";
        }
        if (!empty($filter['modified_before'])) {
            $sql .= " AND o.date_modified <= '" . $this->db->escape($filter['modified_before']) . "'";
        }

        $result = $this->db->query($sql);
        return (int)$result->row['total'];
    }

    public function getOrders($filter = [])
    {
        $sql = "SELECT o.order_id, o.invoice_no, o.invoice_prefix,
                       o.store_id, o.store_name, o.store_url,
                       o.customer_id, o.customer_group_id,
                       o.firstname, o.lastname, o.email, o.telephone, o.fax,
                       o.custom_field,
                       o.payment_firstname, o.payment_lastname, o.payment_company,
                       o.payment_address_1, o.payment_address_2,
                       o.payment_city, o.payment_postcode,
                       o.payment_country, o.payment_country_id,
                       o.payment_zone, o.payment_zone_id,
                       o.payment_address_format, o.payment_custom_field,
                       o.payment_method, o.payment_code,
                       o.shipping_firstname, o.shipping_lastname, o.shipping_company,
                       o.shipping_address_1, o.shipping_address_2,
                       o.shipping_city, o.shipping_postcode,
                       o.shipping_country, o.shipping_country_id,
                       o.shipping_zone, o.shipping_zone_id,
                       o.shipping_address_format, o.shipping_custom_field,
                       o.shipping_method, o.shipping_code,
                       o.comment, o.total, o.order_status_id,
                       o.affiliate_id, o.commission, o.marketing_id, o.tracking,
                       o.language_id, o.currency_id, o.currency_code, o.currency_value,
                       o.ip, o.forwarded_ip, o.user_agent, o.accept_language,
                       o.date_added, o.date_modified,
                       COALESCE(o.shipping_cost, 0) AS shipping_cost,
                       COALESCE(o.payment_cost, 0) AS payment_cost,
                       COALESCE(o.extra_cost, 0) AS extra_cost,
                       os.name AS order_status_name
                FROM " . DB_PREFIX . "order o
                LEFT JOIN " . DB_PREFIX . "order_status os
                    ON o.order_status_id = os.order_status_id
                    AND os.language_id = '" . (int)$this->config->get('config_language_id') . "'
                WHERE o.order_status_id > 0";

        if (!empty($filter['modified_since'])) {
            $sql .= " AND o.date_modified >= '" . $this->db->escape($filter['modified_since']) . "'";
        }
        if (!empty($filter['modified_before'])) {
            $sql .= " AND o.date_modified <= '" . $this->db->escape($filter['modified_before']) . "'";
        }

        $sql .= " ORDER BY o.date_modified ASC";

        $page  = max(1, (int)($filter['page'] ?? 1));
        $limit = min(500, max(1, (int)($filter['limit'] ?? 100)));
        $offset = ($page - 1) * $limit;
        $sql .= " LIMIT " . (int)$offset . ", " . (int)$limit;

        $result = $this->db->query($sql);
        return $result->rows;
    }

    public function getOrderProducts($order_id)
    {
        $sql = "SELECT op.order_product_id, op.product_id, op.name, op.model,
                       op.quantity, op.price, op.total, op.tax, op.reward
                FROM " . DB_PREFIX . "order_product op
                WHERE op.order_id = '" . (int)$order_id . "'";

        $result = $this->db->query($sql);
        $products = $result->rows;

        foreach ($products as &$p) {
            $optResult = $this->db->query(
                "SELECT oo.order_option_id, oo.product_option_id, oo.product_option_value_id,
                        oo.name, oo.value, oo.type
                 FROM " . DB_PREFIX . "order_option oo
                 WHERE oo.order_id = '" . (int)$order_id . "'
                   AND oo.order_product_id = '" . (int)$p['order_product_id'] . "'"
            );
            $p['options'] = $optResult->rows;
        }

        return $products;
    }

    public function getOrderTotals($order_id)
    {
        $result = $this->db->query(
            "SELECT order_total_id, code, title, value, sort_order
             FROM " . DB_PREFIX . "order_total
             WHERE order_id = '" . (int)$order_id . "'
             ORDER BY sort_order ASC"
        );
        return $result->rows;
    }

    public function getOrderHistory($order_id)
    {
        $result = $this->db->query(
            "SELECT order_history_id, order_status_id, notify, comment, date_added
             FROM " . DB_PREFIX . "order_history
             WHERE order_id = '" . (int)$order_id . "'
             ORDER BY date_added ASC"
        );
        return $result->rows;
    }

    public function getCategories($filter = [])
    {
        $sql = "SELECT c.category_id, c.parent_id, c.top, c.column,
                       c.sort_order, c.status, c.image,
                       c.date_added, c.date_modified,
                       cd.name, cd.description, cd.meta_title, cd.meta_description, cd.meta_keyword
                FROM " . DB_PREFIX . "category c
                LEFT JOIN " . DB_PREFIX . "category_description cd
                    ON c.category_id = cd.category_id
                    AND cd.language_id = '" . (int)$this->config->get('config_language_id') . "'";

        if (!empty($filter['modified_since'])) {
            $sql .= " WHERE c.date_modified >= '" . $this->db->escape($filter['modified_since']) . "'";
        }

        $sql .= " ORDER BY c.sort_order ASC, cd.name ASC";

        $result = $this->db->query($sql);
        $categories = $result->rows;

        foreach ($categories as &$cat) {
            $pathResult = $this->db->query(
                "SELECT path_id, level
                 FROM " . DB_PREFIX . "category_path
                 WHERE category_id = '" . (int)$cat['category_id'] . "'
                 ORDER BY level ASC"
            );
            $cat['path'] = $pathResult->rows;
        }

        return $categories;
    }

    public function getManufacturers()
    {
        $result = $this->db->query(
            "SELECT m.manufacturer_id, m.name, m.image, m.sort_order
             FROM " . DB_PREFIX . "manufacturer m
             ORDER BY m.sort_order ASC, m.name ASC"
        );
        return $result->rows;
    }

    public function getOrderStatuses()
    {
        $langId = (int)$this->config->get('config_language_id');

        $result = $this->db->query(
            "SELECT os.order_status_id, os.name
             FROM " . DB_PREFIX . "order_status os
             WHERE os.language_id = '" . $langId . "'
             ORDER BY os.order_status_id ASC"
        );
        return $result->rows;
    }

    public function getOptions()
    {
        $langId = (int)$this->config->get('config_language_id');

        $sql = "SELECT o.option_id, od.name, o.type, o.sort_order
                FROM " . DB_PREFIX . "option o
                LEFT JOIN " . DB_PREFIX . "option_description od
                    ON o.option_id = od.option_id AND od.language_id = '" . $langId . "'
                ORDER BY o.sort_order ASC, od.name ASC";

        $result = $this->db->query($sql);
        $options = $result->rows;

        foreach ($options as &$opt) {
            $valSql = "SELECT ov.option_value_id, ovd.name, ov.image, ov.sort_order
                       FROM " . DB_PREFIX . "option_value ov
                       LEFT JOIN " . DB_PREFIX . "option_value_description ovd
                           ON ov.option_value_id = ovd.option_value_id AND ovd.language_id = '" . $langId . "'
                       WHERE ov.option_id = '" . (int)$opt['option_id'] . "'
                       ORDER BY ov.sort_order ASC, ovd.name ASC";

            $valResult = $this->db->query($valSql);
            $opt['values'] = $valResult->rows;
        }

        return $options;
    }

    public function createProduct($data)
    {
        $langId = (int)$this->config->get('config_language_id');

        $manufacturerId = 0;
        if (!empty($data['manufacturer_name'])) {
            $manufacturerId = $this->resolveManufacturer(trim($data['manufacturer_name']));
        }

        $mainImage = isset($data['image']) ? $data['image'] : '';
        if (!empty($data['image_data']) && !empty($data['image_filename'])) {
            $saved = $this->saveBase64Image($data['image_data'], $data['image_filename'], $data['manufacturer_name'] ?? '');
            if ($saved) {
                $mainImage = $saved;
            }
        }

        $this->db->query("START TRANSACTION");

        try {

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "product SET
                model = '" . $this->db->escape($data['model'] ?? '') . "',
                sku = '" . $this->db->escape($data['sku'] ?? '') . "',
                upc = '" . $this->db->escape($data['upc'] ?? '') . "',
                ean = '" . $this->db->escape($data['ean'] ?? '') . "',
                jan = '" . $this->db->escape($data['jan'] ?? '') . "',
                isbn = '" . $this->db->escape($data['isbn'] ?? '') . "',
                mpn = '" . $this->db->escape($data['mpn'] ?? '') . "',
                location = '" . $this->db->escape($data['location'] ?? '') . "',
                quantity = '" . (int)($data['quantity'] ?? 0) . "',
                stock_status_id = '" . (int)($data['stock_status_id'] ?? 5) . "',
                image = '" . $this->db->escape($mainImage) . "',
                manufacturer_id = '" . (int)$manufacturerId . "',
                shipping = '" . (int)($data['shipping'] ?? 1) . "',
                price = '" . (float)($data['price'] ?? 0) . "',
                cost = '" . (float)($data['cost'] ?? 0) . "',
                points = '" . (int)($data['points'] ?? 0) . "',
                tax_class_id = '" . (int)($data['tax_class_id'] ?? 0) . "',
                date_available = '" . $this->db->escape($data['date_available'] ?? date('Y-m-d')) . "',
                weight = '" . (float)($data['weight'] ?? 0) . "',
                weight_class_id = '" . (int)($data['weight_class_id'] ?? 1) . "',
                length = '" . (float)($data['length'] ?? 0) . "',
                width = '" . (float)($data['width'] ?? 0) . "',
                height = '" . (float)($data['height'] ?? 0) . "',
                length_class_id = '" . (int)($data['length_class_id'] ?? 1) . "',
                status = '" . (int)($data['status'] ?? 0) . "',
                subtract = '" . (int)($data['subtract'] ?? 1) . "',
                minimum = '" . (int)($data['minimum'] ?? 1) . "',
                sort_order = '" . (int)($data['sort_order'] ?? 0) . "',
                viewed = '0',
                date_added = NOW(),
                date_modified = NOW()"
        );

        $productId = (int)$this->db->getLastId();

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "product_description SET
                product_id = '" . $productId . "',
                language_id = '" . $langId . "',
                name = '" . $this->db->escape($data['name'] ?? '') . "',
                description = '" . $this->db->escape($data['description'] ?? '') . "',
                meta_title = '" . $this->db->escape($data['meta_title'] ?? '') . "',
                meta_description = '" . $this->db->escape($data['meta_description'] ?? '') . "',
                meta_keyword = '',
                tag = '" . $this->db->escape($data['tag'] ?? '') . "'"
        );

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "product_to_store SET
                product_id = '" . $productId . "',
                store_id = '0'"
        );

        if (!empty($data['category_names']) && is_array($data['category_names'])) {
            foreach ($data['category_names'] as $catPath) {
                $categoryId = $this->resolveCategoryPath(trim($catPath), $langId);
                if ($categoryId > 0) {
                    $this->db->query(
                        "INSERT INTO " . DB_PREFIX . "product_to_category SET
                            product_id = '" . $productId . "',
                            category_id = '" . (int)$categoryId . "'"
                    );
                }
            }
        }

        if (!empty($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $img) {
                $imgPath = isset($img['image']) ? $img['image'] : '';
                if (!empty($img['image_data']) && !empty($img['image_filename'])) {
                    $saved = $this->saveBase64Image($img['image_data'], $img['image_filename'], $data['manufacturer_name'] ?? '');
                    if ($saved) {
                        $imgPath = $saved;
                    }
                }
                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "product_image SET
                        product_id = '" . $productId . "',
                        image = '" . $this->db->escape($imgPath) . "',
                        sort_order = '" . (int)($img['sort_order'] ?? 0) . "'"
                );
            }
        }

        if (!empty($data['specials']) && is_array($data['specials'])) {
            foreach ($data['specials'] as $sp) {
                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "product_special SET
                        product_id = '" . $productId . "',
                        customer_group_id = '" . (int)($sp['customer_group_id'] ?? 1) . "',
                        priority = '" . (int)($sp['priority'] ?? 0) . "',
                        price = '" . (float)($sp['price'] ?? 0) . "',
                        date_start = '" . $this->db->escape($sp['date_start'] ?? '0000-00-00') . "',
                        date_end = '" . $this->db->escape($sp['date_end'] ?? '0000-00-00') . "'"
                );
            }
        }

        if (!empty($data['options']) && is_array($data['options'])) {
            foreach ($data['options'] as $opt) {
                $optionName = trim($opt['option_name'] ?? '');
                if ($optionName === '') continue;

                $optionId = $this->resolveOption($optionName, $opt['type'] ?? 'select', $langId);

                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "product_option SET
                        product_id = '" . $productId . "',
                        option_id = '" . (int)$optionId . "',
                        value = '',
                        required = '" . (int)($opt['required'] ?? 1) . "'"
                );
                $productOptionId = (int)$this->db->getLastId();

                if (!empty($opt['values']) && is_array($opt['values'])) {
                    foreach ($opt['values'] as $val) {
                        $valName = trim($val['name'] ?? '');
                        if ($valName === '') continue;

                        $optionValueId = $this->resolveOptionValue($optionId, $valName, $langId);

                        $this->db->query(
                            "INSERT INTO " . DB_PREFIX . "product_option_value SET
                                product_option_id = '" . $productOptionId . "',
                                product_id = '" . $productId . "',
                                option_id = '" . (int)$optionId . "',
                                option_value_id = '" . (int)$optionValueId . "',
                                sku = '" . $this->db->escape($val['sku'] ?? '') . "',
                                quantity = '" . (int)($val['quantity'] ?? 0) . "',
                                subtract = '" . (int)($val['subtract'] ?? 1) . "',
                                price = '" . (float)($val['price'] ?? 0) . "',
                                price_prefix = '" . $this->db->escape($val['price_prefix'] ?? '+') . "',
                                points = '" . (int)($val['points'] ?? 0) . "',
                                points_prefix = '" . $this->db->escape($val['points_prefix'] ?? '+') . "',
                                weight = '" . (float)($val['weight'] ?? 0) . "',
                                weight_prefix = '" . $this->db->escape($val['weight_prefix'] ?? '+') . "'"
                        );

                        $povId = (int)$this->db->getLastId();
                        if (isset($val['cost_amount']) || isset($val['cost_percentage']) || isset($val['cost_additional'])) {
                            $this->db->query(
                                "INSERT INTO " . DB_PREFIX . "product_option_cost SET
                                    product_option_value_id = '" . $povId . "',
                                    product_id = '" . $productId . "',
                                    cost = '" . (float)($val['cost'] ?? 0) . "',
                                    cost_amount = '" . (float)($val['cost_amount'] ?? 0) . "',
                                    cost_prefix = '" . $this->db->escape($val['cost_prefix'] ?? '+') . "',
                                    costing_method = '0',
                                    sku = '" . $this->db->escape($val['sku'] ?? '') . "'
                                 ON DUPLICATE KEY UPDATE
                                    cost = VALUES(cost),
                                    cost_amount = VALUES(cost_amount),
                                    cost_prefix = VALUES(cost_prefix),
                                    sku = VALUES(sku)"
                            );
                        }
                    }
                }
            }
        }

        $this->db->query("COMMIT");

        return $productId;

        } catch (\Throwable $e) {
            $this->db->query("ROLLBACK");
            throw $e;
        }
    }

    private function saveBase64Image($base64, $filename, $manufacturerName = '')
    {
        $decoded = base64_decode($base64, true);
        if ($decoded === false || strlen($decoded) < 100) {
            return false;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'erp_img_');
        file_put_contents($tmp, $decoded);
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP))) {
            @unlink($tmp);
            return false;
        }
        @unlink($tmp);

        // Strip the filename to safe characters so an uploaded name cannot traverse paths.
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename));
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'))) {
            if ($info[2] == IMAGETYPE_PNG) $ext = 'png';
            elseif ($info[2] == IMAGETYPE_WEBP) $ext = 'webp';
            else $ext = 'jpg';
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.' . $ext;
        }

        $slug = '';
        if ($manufacturerName !== '') {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($manufacturerName)), '-');
        }
        if ($slug === '') {
            $slug = '_uploads';
        }

        $dir = DIR_IMAGE . 'catalog/' . $slug;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $target = $dir . '/' . $filename;
        if (file_exists($target)) {
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $i = 1;
            while (file_exists($dir . '/' . $base . '_' . $i . '.' . $ext)) {
                $i++;
                if ($i > 9999) return false;
            }
            $filename = $base . '_' . $i . '.' . $ext;
            $target = $dir . '/' . $filename;
        }

        if (file_put_contents($target, $decoded) === false) {
            return false;
        }

        return 'catalog/' . $slug . '/' . $filename;
    }

    private function resolveManufacturer($name)
    {
        $result = $this->db->query(
            "SELECT manufacturer_id FROM " . DB_PREFIX . "manufacturer
             WHERE name = '" . $this->db->escape($name) . "' LIMIT 1"
        );

        if ($result->num_rows) {
            return (int)$result->row['manufacturer_id'];
        }

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "manufacturer SET
                name = '" . $this->db->escape($name) . "',
                image = '',
                sort_order = '0'"
        );

        $mfgId = (int)$this->db->getLastId();

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "manufacturer_to_store SET
                manufacturer_id = '" . $mfgId . "',
                store_id = '0'"
        );

        return $mfgId;
    }

    private function resolveCategoryPath($path, $langId)
    {
        $parts = array_map('trim', explode('>', $path));
        $parentId = 0;

        foreach ($parts as $level => $name) {
            if ($name === '') continue;

            $result = $this->db->query(
                "SELECT c.category_id FROM " . DB_PREFIX . "category c
                 INNER JOIN " . DB_PREFIX . "category_description cd
                    ON c.category_id = cd.category_id AND cd.language_id = '" . (int)$langId . "'
                 WHERE cd.name = '" . $this->db->escape($name) . "'
                   AND c.parent_id = '" . (int)$parentId . "'
                 LIMIT 1"
            );

            if ($result->num_rows) {
                $parentId = (int)$result->row['category_id'];
            } else {
                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "category SET
                        parent_id = '" . (int)$parentId . "',
                        `top` = '" . ($parentId == 0 ? 1 : 0) . "',
                        `column` = '1',
                        sort_order = '0',
                        status = '1',
                        image = '',
                        date_added = NOW(),
                        date_modified = NOW()"
                );

                $catId = (int)$this->db->getLastId();

                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "category_description SET
                        category_id = '" . $catId . "',
                        language_id = '" . (int)$langId . "',
                        name = '" . $this->db->escape($name) . "',
                        description = '',
                        meta_title = '" . $this->db->escape($name) . "',
                        meta_description = '',
                        meta_keyword = ''"
                );

                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "category_to_store SET
                        category_id = '" . $catId . "',
                        store_id = '0'"
                );

                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "category_path (category_id, path_id, level)
                     SELECT '" . $catId . "', path_id, level
                     FROM " . DB_PREFIX . "category_path
                     WHERE category_id = '" . (int)$parentId . "'"
                );

                $levelResult = $this->db->query(
                    "SELECT MAX(level) AS max_level FROM " . DB_PREFIX . "category_path
                     WHERE category_id = '" . $catId . "'"
                );
                $nextLevel = ($levelResult->row['max_level'] !== null)
                    ? ((int)$levelResult->row['max_level'] + 1)
                    : 0;

                $this->db->query(
                    "INSERT INTO " . DB_PREFIX . "category_path SET
                        category_id = '" . $catId . "',
                        path_id = '" . $catId . "',
                        level = '" . $nextLevel . "'"
                );

                $parentId = $catId;
            }
        }

        return $parentId;
    }

    private function resolveOption($name, $type, $langId)
    {
        $result = $this->db->query(
            "SELECT o.option_id FROM " . DB_PREFIX . "option o
             INNER JOIN " . DB_PREFIX . "option_description od
                ON o.option_id = od.option_id AND od.language_id = '" . (int)$langId . "'
             WHERE od.name = '" . $this->db->escape($name) . "'
             LIMIT 1"
        );

        if ($result->num_rows) {
            return (int)$result->row['option_id'];
        }

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "option SET
                type = '" . $this->db->escape($type) . "',
                sort_order = '0'"
        );
        $optionId = (int)$this->db->getLastId();

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "option_description SET
                option_id = '" . $optionId . "',
                language_id = '" . (int)$langId . "',
                name = '" . $this->db->escape($name) . "'"
        );

        return $optionId;
    }

    private function resolveOptionValue($optionId, $name, $langId)
    {
        $result = $this->db->query(
            "SELECT ov.option_value_id FROM " . DB_PREFIX . "option_value ov
             INNER JOIN " . DB_PREFIX . "option_value_description ovd
                ON ov.option_value_id = ovd.option_value_id AND ovd.language_id = '" . (int)$langId . "'
             WHERE ov.option_id = '" . (int)$optionId . "'
               AND ovd.name = '" . $this->db->escape($name) . "'
             LIMIT 1"
        );

        if ($result->num_rows) {
            return (int)$result->row['option_value_id'];
        }

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "option_value SET
                option_id = '" . (int)$optionId . "',
                image = '',
                sort_order = '0'"
        );
        $valueId = (int)$this->db->getLastId();

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "option_value_description SET
                option_value_id = '" . $valueId . "',
                language_id = '" . (int)$langId . "',
                option_id = '" . (int)$optionId . "',
                name = '" . $this->db->escape($name) . "'"
        );

        return $valueId;
    }

    public function updateProduct($product_id, $data)
    {
        $check = $this->db->query(
            "SELECT product_id FROM " . DB_PREFIX . "product
             WHERE product_id = '" . (int)$product_id . "'"
        );

        if (!$check->num_rows) {
            return false;
        }

        $sets = [];

        if (array_key_exists('quantity', $data)) {
            $sets[] = "quantity = '" . (int)$data['quantity'] . "'";
        }
        if (array_key_exists('price', $data)) {
            $sets[] = "price = '" . (float)$data['price'] . "'";
        }
        if (array_key_exists('cost', $data)) {
            $sets[] = "cost = '" . (float)$data['cost'] . "'";
        }
        if (array_key_exists('status', $data)) {
            $sets[] = "status = '" . (int)$data['status'] . "'";
        }
        if (array_key_exists('model', $data)) {
            $sets[] = "model = '" . $this->db->escape($data['model']) . "'";
        }
        if (array_key_exists('sku', $data)) {
            $sets[] = "sku = '" . $this->db->escape($data['sku']) . "'";
        }
        if (array_key_exists('weight', $data)) {
            $sets[] = "weight = '" . (float)$data['weight'] . "'";
        }
        if (!empty($data['image_data']) && !empty($data['image_filename'])) {
            $saved = $this->saveBase64Image($data['image_data'], $data['image_filename'], $data['manufacturer_name'] ?? '');
            if ($saved) {
                $sets[] = "image = '" . $this->db->escape($saved) . "'";
            }
        } elseif (array_key_exists('image', $data)) {
            $sets[] = "image = '" . $this->db->escape($data['image']) . "'";
        }

        if (!empty($sets)) {
            $sets[] = "date_modified = NOW()";
            $this->db->query(
                "UPDATE " . DB_PREFIX . "product SET " . implode(', ', $sets) .
                " WHERE product_id = '" . (int)$product_id . "'"
            );
        }

        $descSets = [];
        if (array_key_exists('name', $data)) {
            $descSets[] = "name = '" . $this->db->escape($data['name']) . "'";
        }
        if (array_key_exists('description', $data)) {
            $descSets[] = "description = '" . $this->db->escape($data['description']) . "'";
        }
        if (array_key_exists('meta_title', $data)) {
            $descSets[] = "meta_title = '" . $this->db->escape($data['meta_title']) . "'";
        }
        if (array_key_exists('meta_description', $data)) {
            $descSets[] = "meta_description = '" . $this->db->escape($data['meta_description']) . "'";
        }
        if (array_key_exists('tag', $data)) {
            $descSets[] = "tag = '" . $this->db->escape($data['tag']) . "'";
        }

        if (!empty($descSets)) {
            $langId = (int)$this->config->get('config_language_id');
            $this->db->query(
                "UPDATE " . DB_PREFIX . "product_description SET " . implode(', ', $descSets) .
                " WHERE product_id = '" . (int)$product_id . "' AND language_id = '" . $langId . "'"
            );
        }

        if (isset($data['option_values']) && is_array($data['option_values'])) {
            $langId = (int)$this->config->get('config_language_id');

            foreach ($data['option_values'] as $ov) {
                $povId = isset($ov['product_option_value_id']) ? (int)$ov['product_option_value_id'] : 0;

                if ($povId <= 0 && !empty($ov['sku'])) {
                    $skuLookup = $this->db->query(
                        "SELECT product_option_value_id FROM " . DB_PREFIX . "product_option_value
                         WHERE product_id = '" . (int)$product_id . "'
                           AND sku = '" . $this->db->escape($ov['sku']) . "'
                         LIMIT 1"
                    );
                    if ($skuLookup->num_rows) {
                        $povId = (int)$skuLookup->row['product_option_value_id'];
                    }
                }

                if ($povId <= 0 && !empty($ov['name'])) {
                    $nameLookup = $this->db->query(
                        "SELECT pov.product_option_value_id
                         FROM " . DB_PREFIX . "product_option_value pov
                         INNER JOIN " . DB_PREFIX . "option_value_description ovd
                            ON pov.option_value_id = ovd.option_value_id
                            AND ovd.language_id = '" . $langId . "'
                         WHERE pov.product_id = '" . (int)$product_id . "'
                           AND ovd.name = '" . $this->db->escape($ov['name']) . "'
                         LIMIT 1"
                    );
                    if ($nameLookup->num_rows) {
                        $povId = (int)$nameLookup->row['product_option_value_id'];
                    }
                }

                if ($povId <= 0) continue;

                $ovSets = [];
                if (array_key_exists('quantity', $ov)) {
                    $ovSets[] = "quantity = '" . (int)$ov['quantity'] . "'";
                }
                if (array_key_exists('price', $ov)) {
                    $ovSets[] = "price = '" . (float)$ov['price'] . "'";
                }

                if (!empty($ovSets)) {
                    $this->db->query(
                        "UPDATE " . DB_PREFIX . "product_option_value SET " . implode(', ', $ovSets) .
                        " WHERE product_option_value_id = '" . $povId . "'
                          AND product_id = '" . (int)$product_id . "'"
                    );
                }
            }
        }

        if (isset($data['options']) && is_array($data['options'])) {
            $langId = (int)$this->config->get('config_language_id');

            $existingPo = $this->db->query(
                "SELECT po.product_option_id, po.option_id, od.name AS option_name
                 FROM " . DB_PREFIX . "product_option po
                 INNER JOIN " . DB_PREFIX . "option_description od
                    ON po.option_id = od.option_id AND od.language_id = '" . $langId . "'
                 WHERE po.product_id = '" . (int)$product_id . "'"
            );
            $poByOptionId = array();
            foreach ($existingPo->rows as $row) {
                $poByOptionId[(int)$row['option_id']] = $row;
            }

            $existingPov = $this->db->query(
                "SELECT pov.product_option_value_id, pov.option_id, pov.option_value_id, pov.sku,
                        ovd.name AS value_name
                 FROM " . DB_PREFIX . "product_option_value pov
                 INNER JOIN " . DB_PREFIX . "option_value_description ovd
                    ON pov.option_value_id = ovd.option_value_id AND ovd.language_id = '" . $langId . "'
                 WHERE pov.product_id = '" . (int)$product_id . "'"
            );
            $povBySku = array();
            $povByName = array();
            foreach ($existingPov->rows as $row) {
                $oid = (int)$row['option_id'];
                if (!empty($row['sku'])) {
                    $povBySku[$oid . ':' . $row['sku']] = $row;
                }
                $povByName[$oid . ':' . $row['value_name']] = $row;
            }

            $seenOptionIds = array();
            $seenPovIds = array();

            foreach ($data['options'] as $opt) {
                $optionName = trim($opt['option_name']);
                if ($optionName === '') continue;

                $optionId = $this->resolveOption($optionName, $opt['type'], $langId);
                $seenOptionIds[] = $optionId;

                if (isset($poByOptionId[$optionId])) {
                    $productOptionId = (int)$poByOptionId[$optionId]['product_option_id'];
                    $this->db->query(
                        "UPDATE " . DB_PREFIX . "product_option SET
                            required = '" . (int)($opt['required'] ?? 1) . "'
                         WHERE product_option_id = '" . $productOptionId . "'"
                    );
                } else {
                    $this->db->query(
                        "INSERT INTO " . DB_PREFIX . "product_option SET
                            product_id = '" . (int)$product_id . "',
                            option_id = '" . (int)$optionId . "',
                            value = '',
                            required = '" . (int)($opt['required'] ?? 1) . "'"
                    );
                    $productOptionId = (int)$this->db->getLastId();
                }

                if (!empty($opt['values']) && is_array($opt['values'])) {
                    foreach ($opt['values'] as $val) {
                        $valName = trim($val['name'] ?? '');
                        if ($valName === '') continue;

                        $valSku = isset($val['sku']) ? trim($val['sku']) : '';
                        $optionValueId = $this->resolveOptionValue($optionId, $valName, $langId);

                        $matchedRow = null;
                        if ($valSku !== '' && isset($povBySku[$optionId . ':' . $valSku])) {
                            $matchedRow = $povBySku[$optionId . ':' . $valSku];
                        } elseif (isset($povByName[$optionId . ':' . $valName])) {
                            $matchedRow = $povByName[$optionId . ':' . $valName];
                        }

                        if ($matchedRow) {
                            $povId = (int)$matchedRow['product_option_value_id'];
                            $seenPovIds[] = $povId;
                            $this->db->query(
                                "UPDATE " . DB_PREFIX . "product_option_value SET
                                    product_option_id = '" . $productOptionId . "',
                                    option_value_id = '" . (int)$optionValueId . "',
                                    sku = '" . $this->db->escape($valSku) . "',
                                    quantity = '" . (int)($val['quantity'] ?? 0) . "',
                                    subtract = '" . (int)($val['subtract'] ?? 1) . "',
                                    price = '" . (float)($val['price'] ?? 0) . "',
                                    price_prefix = '" . $this->db->escape($val['price_prefix'] ?? '+') . "',
                                    points = '" . (int)($val['points'] ?? 0) . "',
                                    points_prefix = '" . $this->db->escape($val['points_prefix'] ?? '+') . "',
                                    weight = '" . (float)($val['weight'] ?? 0) . "',
                                    weight_prefix = '" . $this->db->escape($val['weight_prefix'] ?? '+') . "'
                                 WHERE product_option_value_id = '" . $povId . "'"
                            );

                            if (isset($val['cost_amount']) || isset($val['cost_percentage']) || isset($val['cost_additional'])) {
                                $this->db->query(
                                    "INSERT INTO " . DB_PREFIX . "product_option_cost SET
                                        product_option_value_id = '" . $povId . "',
                                        product_id = '" . (int)$product_id . "',
                                        cost = '" . (float)($val['cost'] ?? 0) . "',
                                        cost_amount = '" . (float)($val['cost_amount'] ?? 0) . "',
                                        cost_prefix = '" . $this->db->escape($val['cost_prefix'] ?? '+') . "',
                                        costing_method = '0',
                                        sku = '" . $this->db->escape($valSku) . "'
                                     ON DUPLICATE KEY UPDATE
                                        cost = VALUES(cost),
                                        cost_amount = VALUES(cost_amount),
                                        cost_prefix = VALUES(cost_prefix),
                                        sku = VALUES(sku)"
                                );
                            }
                        } else {
                            $this->db->query(
                                "INSERT INTO " . DB_PREFIX . "product_option_value SET
                                    product_option_id = '" . $productOptionId . "',
                                    product_id = '" . (int)$product_id . "',
                                    option_id = '" . (int)$optionId . "',
                                    option_value_id = '" . (int)$optionValueId . "',
                                    sku = '" . $this->db->escape($valSku) . "',
                                    quantity = '" . (int)($val['quantity'] ?? 0) . "',
                                    subtract = '" . (int)($val['subtract'] ?? 1) . "',
                                    price = '" . (float)($val['price'] ?? 0) . "',
                                    price_prefix = '" . $this->db->escape($val['price_prefix'] ?? '+') . "',
                                    points = '" . (int)($val['points'] ?? 0) . "',
                                    points_prefix = '" . $this->db->escape($val['points_prefix'] ?? '+') . "',
                                    weight = '" . (float)($val['weight'] ?? 0) . "',
                                    weight_prefix = '" . $this->db->escape($val['weight_prefix'] ?? '+') . "'"
                            );
                            $newPovId = (int)$this->db->getLastId();
                            $seenPovIds[] = $newPovId;

                            if (isset($val['cost_amount']) || isset($val['cost_percentage']) || isset($val['cost_additional'])) {
                                $this->db->query(
                                    "INSERT INTO " . DB_PREFIX . "product_option_cost SET
                                        product_option_value_id = '" . $newPovId . "',
                                        product_id = '" . (int)$product_id . "',
                                        cost = '" . (float)($val['cost'] ?? 0) . "',
                                        cost_amount = '" . (float)($val['cost_amount'] ?? 0) . "',
                                        cost_prefix = '" . $this->db->escape($val['cost_prefix'] ?? '+') . "',
                                        costing_method = '0',
                                        sku = '" . $this->db->escape($valSku) . "'
                                     ON DUPLICATE KEY UPDATE
                                        cost = VALUES(cost),
                                        cost_amount = VALUES(cost_amount),
                                        cost_prefix = VALUES(cost_prefix),
                                        sku = VALUES(sku)"
                                );
                            }
                        }
                    }
                }
            }

            foreach ($existingPov->rows as $row) {
                $povId = (int)$row['product_option_value_id'];
                if (!in_array($povId, $seenPovIds)) {
                    $this->db->query(
                        "DELETE FROM " . DB_PREFIX . "product_option_cost
                         WHERE product_option_value_id = '" . $povId . "'"
                    );
                    $this->db->query(
                        "DELETE FROM " . DB_PREFIX . "product_option_value
                         WHERE product_option_value_id = '" . $povId . "'"
                    );
                }
            }

            foreach ($poByOptionId as $oid => $row) {
                if (!in_array((int)$oid, $seenOptionIds)) {
                    $this->db->query(
                        "DELETE poc FROM " . DB_PREFIX . "product_option_cost poc
                         INNER JOIN " . DB_PREFIX . "product_option_value pov
                            ON poc.product_option_value_id = pov.product_option_value_id
                         WHERE pov.product_option_id = '" . (int)$row['product_option_id'] . "'"
                    );
                    $this->db->query(
                        "DELETE FROM " . DB_PREFIX . "product_option_value
                         WHERE product_option_id = '" . (int)$row['product_option_id'] . "'"
                    );
                    $this->db->query(
                        "DELETE FROM " . DB_PREFIX . "product_option
                         WHERE product_option_id = '" . (int)$row['product_option_id'] . "'"
                    );
                }
            }
        }

        if (isset($data['images']) && is_array($data['images'])) {
            $this->db->query(
                "DELETE FROM " . DB_PREFIX . "product_image
                 WHERE product_id = '" . (int)$product_id . "'"
            );

            foreach ($data['images'] as $img) {
                $imgPath = isset($img['image']) ? $img['image'] : '';
                if (!empty($img['image_data']) && !empty($img['image_filename'])) {
                    $saved = $this->saveBase64Image($img['image_data'], $img['image_filename'], $data['manufacturer_name'] ?? '');
                    if ($saved) {
                        $imgPath = $saved;
                    }
                }
                if (!empty($imgPath)) {
                    $this->db->query(
                        "INSERT INTO " . DB_PREFIX . "product_image SET
                            product_id = '" . (int)$product_id . "',
                            image = '" . $this->db->escape($imgPath) . "',
                            sort_order = '" . (int)($img['sort_order'] ?? 0) . "'"
                    );
                }
            }
        }

        return true;
    }

    public function findReviewByPlatformId($platform, $platformReviewId)
    {
        $marker = '<!--[' . $platform . ':' . $platformReviewId . ']-->';
        $result = $this->db->query(
            "SELECT review_id FROM " . DB_PREFIX . "review WHERE text LIKE '%" . $this->db->escape($marker) . "%' LIMIT 1"
        );
        return $result->num_rows ? (int)$result->row['review_id'] : null;
    }

    public function createReview($data)
    {
        $productId = (int)$data['product_id'];
        $author    = isset($data['author']) && $data['author'] !== '' ? $data['author'] : 'Marketplace Buyer';
        $rating    = max(1, min(5, (int)$data['rating']));
        $status    = isset($data['status']) ? (int)$data['status'] : 1;
        $dateAdded = !empty($data['date_added']) ? $data['date_added'] : date('Y-m-d H:i:s');

        $platform  = isset($data['platform']) ? $data['platform'] : '';
        $platId    = isset($data['platform_review_id']) ? $data['platform_review_id'] : '';

        if ($platform !== '' && $platId !== '') {
            $existing = $this->findReviewByPlatformId($platform, $platId);
            if ($existing) {
                return $existing;
            }
        }

        $check = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "product WHERE product_id = '" . $productId . "'");
        if (!$check->num_rows) {
            throw new \Exception('Product #' . $productId . ' not found in OpenCart');
        }

        $text = isset($data['text']) ? $data['text'] : '';
        $marker = ($platform !== '' && $platId !== '') ? '<!--[' . $platform . ':' . $platId . ']-->' : '';
        $fullText = $marker !== '' ? $marker . ' ' . $text : $text;

        $this->db->query(
            "INSERT INTO " . DB_PREFIX . "review SET
                product_id    = '" . $productId . "',
                customer_id   = '0',
                author        = '" . $this->db->escape($author) . "',
                text          = '" . $this->db->escape($fullText) . "',
                rating        = '" . $rating . "',
                status        = '" . $status . "',
                date_added    = '" . $this->db->escape($dateAdded) . "',
                date_modified = NOW()"
        );

        return (int)$this->db->getLastId();
    }
}
