<?php
class ControllerExtensionModuleErpSync extends Controller
{
    private $error = [];

    public function install()
    {
        $this->load->model('setting/setting');

        $key = bin2hex(random_bytes(32));

        $this->model_setting_setting->editSetting('module_erp_sync', [
            'module_erp_sync_status'       => 0,
            'module_erp_sync_api_key'      => $key,
            'module_erp_sync_ip_whitelist' => '',
            'module_erp_sync_debug_log'    => 0,
        ]);

        $this->model_setting_setting->editSetting('erp_api', [
            'erp_api_key' => $key,
        ]);

        $this->session->data['success'] = $this->language->get('text_installed') ?: 'ERP Sync API module installed.';
    }

    public function uninstall()
    {
        $this->load->model('setting/setting');

        $this->model_setting_setting->deleteSetting('module_erp_sync');
        $this->model_setting_setting->deleteSetting('erp_api');
    }

    public function index()
    {
        $this->load->language('extension/module/erp_sync');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
            $data = [
                'module_erp_sync_status'       => (int)($this->request->post['module_erp_sync_status'] ?? 0),
                'module_erp_sync_api_key'      => trim($this->request->post['module_erp_sync_api_key'] ?? ''),
                'module_erp_sync_ip_whitelist' => trim($this->request->post['module_erp_sync_ip_whitelist'] ?? ''),
                'module_erp_sync_debug_log'    => (int)($this->request->post['module_erp_sync_debug_log'] ?? 0),
            ];

            $this->model_setting_setting->editSetting('module_erp_sync', $data);

            $this->model_setting_setting->editSetting('erp_api', [
                'erp_api_key' => $data['module_erp_sync_api_key'],
            ]);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=module', true));
        }

        $settings = $this->model_setting_setting->getSetting('module_erp_sync');

        $data['breadcrumbs'] = [];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home') ?: 'Home',
            'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], true),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=module', true),
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/erp_sync', 'token=' . $this->session->data['token'], true),
        ];

        $data['action'] = $this->url->link('extension/module/erp_sync', 'token=' . $this->session->data['token'], true);
        $data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=module', true);

        $data['module_erp_sync_status'] = isset($this->request->post['module_erp_sync_status'])
            ? $this->request->post['module_erp_sync_status']
            : ($settings['module_erp_sync_status'] ?? 0);

        $data['module_erp_sync_api_key'] = isset($this->request->post['module_erp_sync_api_key'])
            ? $this->request->post['module_erp_sync_api_key']
            : ($settings['module_erp_sync_api_key'] ?? '');

        $data['module_erp_sync_ip_whitelist'] = isset($this->request->post['module_erp_sync_ip_whitelist'])
            ? $this->request->post['module_erp_sync_ip_whitelist']
            : ($settings['module_erp_sync_ip_whitelist'] ?? '');

        $data['module_erp_sync_debug_log'] = isset($this->request->post['module_erp_sync_debug_log'])
            ? $this->request->post['module_erp_sync_debug_log']
            : ($settings['module_erp_sync_debug_log'] ?? 0);

        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['error_api_key'] = isset($this->error['api_key']) ? $this->error['api_key'] : '';

        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        $data['heading_title']     = $this->language->get('heading_title');
        $data['text_edit']         = $this->language->get('text_edit');
        $data['text_enabled']      = $this->language->get('text_enabled');
        $data['text_disabled']     = $this->language->get('text_disabled');
        $data['text_key_hint']     = $this->language->get('text_key_hint');
        $data['text_status_hint']  = $this->language->get('text_status_hint');
        $data['text_ip_hint']      = $this->language->get('text_ip_hint');
        $data['text_log_hint']     = $this->language->get('text_log_hint');
        $data['entry_status']      = $this->language->get('entry_status');
        $data['entry_api_key']     = $this->language->get('entry_api_key');
        $data['entry_ip_whitelist'] = $this->language->get('entry_ip_whitelist');
        $data['entry_debug_log']   = $this->language->get('entry_debug_log');
        $data['button_save']       = $this->language->get('button_save');
        $data['button_cancel']     = $this->language->get('button_cancel');
        $data['button_generate']   = $this->language->get('button_generate');

        $data['token'] = $this->session->data['token'];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/erp_sync', $data));
    }

    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/erp_sync')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        $apiKey = trim($this->request->post['module_erp_sync_api_key'] ?? '');
        if (utf8_strlen($apiKey) < 16) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }

        return !$this->error;
    }
}
