<?php
class ControllerApiErp extends Controller
{
    private $moduleSettings = null;

    private function getModuleSettings()
    {
        if ($this->moduleSettings === null) {
            $result = $this->db->query(
                "SELECT `key`, `value` FROM " . DB_PREFIX . "setting WHERE `code` = 'module_erp_sync'"
            );

            $settings = [];
            foreach ($result->rows as $row) {
                $settings[$row['key']] = $row['value'];
            }
            $this->moduleSettings = $settings;
        }
        return $this->moduleSettings;
    }

    private function authenticate()
    {
        $settings = $this->getModuleSettings();

        $status = isset($settings['module_erp_sync_status']) ? (int)$settings['module_erp_sync_status'] : 0;
        if (!$status) {
            $this->sendJson(403, ['success' => false, 'error' => 'ERP Sync API module is disabled']);
            return false;
        }

        $whitelist = isset($settings['module_erp_sync_ip_whitelist']) ? trim($settings['module_erp_sync_ip_whitelist']) : '';
        if ($whitelist !== '') {
            $allowed = array_map('trim', explode(',', $whitelist));
            $clientIp = $this->getClientIp();
            if (!in_array($clientIp, $allowed)) {
                $this->debugLog('Blocked IP: ' . $clientIp);
                $this->sendJson(403, ['success' => false, 'error' => 'IP not allowed']);
                return false;
            }
        }

        $header = '';
        if (isset($this->request->server['HTTP_X_ERP_API_KEY'])) {
            $header = $this->request->server['HTTP_X_ERP_API_KEY'];
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $k => $v) {
                if (strtolower($k) === 'x-erp-api-key') {
                    $header = $v;
                    break;
                }
            }
        }

        $stored = isset($settings['module_erp_sync_api_key']) ? trim($settings['module_erp_sync_api_key']) : '';
        if ($stored === '') {
            $legacy = $this->db->query(
                "SELECT `value` FROM " . DB_PREFIX . "setting WHERE `code` = 'erp_api' AND `key` = 'erp_api_key' LIMIT 1"
            );
            if ($legacy->num_rows) {
                $stored = trim($legacy->row['value']);
            }
        }

        if ($stored === '' || !hash_equals($stored, trim($header))) {
            $this->debugLog('Auth failed from IP: ' . $this->getClientIp());
            $this->sendJson(401, ['success' => false, 'error' => 'Unauthorized']);
            return false;
        }

        return true;
    }

    private function getClientIp()
    {
        if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $this->request->server['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $this->request->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function debugLog($message)
    {
        $settings = $this->getModuleSettings();
        $debug = isset($settings['module_erp_sync_debug_log']) ? (int)$settings['module_erp_sync_debug_log'] : 0;
        if ($debug) {
            $this->log->write('[ERP Sync API] ' . $message);
        }
    }

    private function sendJson($code, $data)
    {
        if ($code === 200) {
            $this->response->addHeader('HTTP/1.1 200 OK');
        } elseif ($code === 400) {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
        } elseif ($code === 401) {
            $this->response->addHeader('HTTP/1.1 401 Unauthorized');
        } elseif ($code === 404) {
            $this->response->addHeader('HTTP/1.1 404 Not Found');
        } else {
            $this->response->addHeader('HTTP/1.1 500 Internal Server Error');
        }

        $this->response->addHeader('Content-Type: application/json; charset=UTF-8');
        $this->response->setOutput(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function param($key, $default = null)
    {
        if (isset($this->request->get[$key])) {
            return $this->request->get[$key];
        }
        if (isset($this->request->post[$key])) {
            return $this->request->post[$key];
        }
        return $default;
    }

    public function ping()
    {
        if (!$this->authenticate()) return;

        $this->sendJson(200, [
            'success' => true,
            'data'    => [
                'version'   => VERSION,
                'store'     => $this->config->get('config_name'),
                'timestamp' => date('c'),
            ],
        ]);
    }

    public function products()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $filter = [
            'page'           => max(1, (int)$this->param('page', 1)),
            'limit'          => min(500, max(1, (int)$this->param('limit', 100))),
            'modified_since' => $this->param('modified_since'),
            'product_id'     => $this->param('product_id'),
        ];

        $total = $this->model_api_erp->getProductCount($filter);
        $rows  = $this->model_api_erp->getProducts($filter);

        $data = [];
        foreach ($rows as $row) {
            $pid = (int)$row['product_id'];
            $row['categories']    = $this->model_api_erp->getProductCategories($pid);
            $row['options']       = $this->model_api_erp->getProductOptions($pid);
            $row['option_values'] = $this->model_api_erp->getProductOptionValues($pid);
            $row['specials']      = $this->model_api_erp->getProductSpecials($pid);
            $row['images']        = $this->model_api_erp->getProductImages($pid);
            $data[] = $row;
        }

        $this->sendJson(200, [
            'success'    => true,
            'data'       => $data,
            'pagination' => [
                'page'        => $filter['page'],
                'limit'       => $filter['limit'],
                'total'       => $total,
                'total_pages' => (int)ceil($total / $filter['limit']),
            ],
        ]);
    }

    public function orders()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $filter = [
            'page'            => max(1, (int)$this->param('page', 1)),
            'limit'           => min(500, max(1, (int)$this->param('limit', 100))),
            'modified_since'  => $this->param('modified_since'),
            'modified_before' => $this->param('modified_before'),
        ];

        $total = $this->model_api_erp->getOrderCount($filter);
        $rows  = $this->model_api_erp->getOrders($filter);

        $data = [];
        foreach ($rows as $row) {
            $oid = (int)$row['order_id'];
            $row['products'] = $this->model_api_erp->getOrderProducts($oid);
            $row['totals']   = $this->model_api_erp->getOrderTotals($oid);
            $row['history']  = $this->model_api_erp->getOrderHistory($oid);
            $data[] = $row;
        }

        $this->sendJson(200, [
            'success'    => true,
            'data'       => $data,
            'pagination' => [
                'page'        => $filter['page'],
                'limit'       => $filter['limit'],
                'total'       => $total,
                'total_pages' => (int)ceil($total / $filter['limit']),
            ],
        ]);
    }

    public function categories()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $filter = [
            'modified_since' => $this->param('modified_since'),
        ];

        $data = $this->model_api_erp->getCategories($filter);

        $this->sendJson(200, [
            'success' => true,
            'data'    => $data,
            'total'   => count($data),
        ]);
    }

    public function manufacturers()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $data = $this->model_api_erp->getManufacturers();

        $this->sendJson(200, [
            'success' => true,
            'data'    => $data,
            'total'   => count($data),
        ]);
    }

    public function options()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $data = $this->model_api_erp->getOptions();

        $this->sendJson(200, [
            'success' => true,
            'data'    => $data,
            'total'   => count($data),
        ]);
    }

    public function order_statuses()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $data = $this->model_api_erp->getOrderStatuses();

        $this->sendJson(200, [
            'success' => true,
            'data'    => $data,
            'total'   => count($data),
        ]);
    }

    public function product_create()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $this->request->post;
        }

        if (empty($input)) {
            $this->sendJson(400, ['success' => false, 'error' => 'Request body is required']);
            return;
        }

        try {
            $productId = $this->model_api_erp->createProduct($input);

            $this->sendJson(200, ['success' => true, 'data' => ['product_id' => $productId]]);
        } catch (Exception $e) {
            $this->debugLog('product_create FAILED sku=' . ($input['sku'] ?? '') . ': ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function product_update()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $this->request->post;
        }

        $productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
        if ($productId <= 0) {
            $this->sendJson(400, ['success' => false, 'error' => 'product_id is required']);
            return;
        }

        $result = $this->model_api_erp->updateProduct($productId, $input);

        if ($result) {
            $this->sendJson(200, ['success' => true, 'data' => ['product_id' => $productId]]);
        } else {
            $this->debugLog('product_update id=' . $productId . ' NOT FOUND');
            $this->sendJson(404, ['success' => false, 'error' => 'Product not found']);
        }
    }

    public function product_bulk_update()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $this->request->post;
        }

        $products = isset($input['products']) ? $input['products'] : [];
        if (empty($products) || !is_array($products)) {
            $this->sendJson(400, ['success' => false, 'error' => 'products array is required']);
            return;
        }

        $results = [];
        foreach ($products as $item) {
            $pid = isset($item['product_id']) ? (int)$item['product_id'] : 0;
            if ($pid <= 0) {
                $results[] = ['product_id' => 0, 'success' => false, 'error' => 'Missing product_id'];
                continue;
            }

            $ok = $this->model_api_erp->updateProduct($pid, $item);
            $results[] = ['product_id' => $pid, 'success' => $ok];
        }

        $failed = array_filter($results, function($r) { return !$r['success']; });
        if (!empty($failed)) {
            $failedIds = array_column($failed, 'product_id');
            $this->debugLog('product_bulk_update ' . count($failed) . ' failed: ' . implode(',', $failedIds));
        }

        $this->sendJson(200, [
            'success' => true,
            'data'    => $results,
            'total'   => count($results),
        ]);
    }

    public function review_create()
    {
        if (!$this->authenticate()) return;

        $this->load->model('api/erp');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            $input = $this->request->post;
        }

        if (empty($input['product_id']) || empty($input['rating'])) {
            $this->sendJson(400, ['success' => false, 'error' => 'product_id and rating are required']);
            return;
        }

        try {
            $reviewId = $this->model_api_erp->createReview($input);
            $this->sendJson(200, ['success' => true, 'data' => ['review_id' => $reviewId]]);
        } catch (Exception $e) {
            $this->debugLog('review_create FAILED product_id=' . ($input['product_id'] ?? '') . ': ' . $e->getMessage());
            $this->sendJson(500, ['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
