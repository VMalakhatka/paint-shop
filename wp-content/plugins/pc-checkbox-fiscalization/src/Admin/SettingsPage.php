<?php

namespace Paint\CheckboxFiscalization\Admin;

use Paint\CheckboxFiscalization\Config;
use Paint\CheckboxFiscalization\Domain\FiscalizationService;
use Paint\CheckboxFiscalization\Domain\ShiftManager;
use Paint\CheckboxFiscalization\Infrastructure\OperationRepository;

defined('ABSPATH') || exit;

final class SettingsPage
{
    private const PAGE = 'pc-checkbox-fiscalization';

    public function __construct(
        private Config $config,
        private FiscalizationService $service,
        private OperationRepository $operations,
        private ShiftManager $shifts
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('wp_ajax_pccf_connection_test', [$this, 'connectionTest']);
        add_action('wp_ajax_pccf_shift_preview', [$this, 'shiftPreview']);
        add_action('wp_ajax_pccf_shift_open', [$this, 'shiftOpen']);
    }

    public function menu(): void
    {
        $parent = function_exists('paint_core_lavka_admin_parent_slug')
            ? paint_core_lavka_admin_parent_slug()
            : (class_exists('WooCommerce') ? 'woocommerce' : 'tools.php');
        add_submenu_page(
            $parent,
            __('Checkbox fiscalization', 'pc-checkbox-fiscalization'),
            __('Checkbox fiscalization', 'pc-checkbox-fiscalization'),
            'manage_pc_checkbox_fiscalization',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function settings(): void
    {
        register_setting('pccf', Config::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
        ]);
    }

    /** @param mixed $input @return array<string,mixed> */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $shift = ($input['shift_policy'] ?? '') === 'open_if_missing' ? 'open_if_missing' : 'require_open';
        return [
            'api_base_url' => 'https://api.checkbox.ua',
            'client_name' => sanitize_text_field((string) ($input['client_name'] ?? 'PC Checkbox Fiscalization')),
            'client_version' => sanitize_text_field((string) ($input['client_version'] ?? PCCF_VERSION)),
            'device_id' => sanitize_text_field((string) ($input['device_id'] ?? '')),
            'request_timeout' => max(5, min(120, (int) ($input['request_timeout'] ?? 30))),
            'shift_policy' => $shift,
            'java_base_url' => esc_url_raw(trim((string) ($input['java_base_url'] ?? ''))),
            'java_command_path' => sanitize_text_field((string) ($input['java_command_path'] ?? '/admin/folio/fiscalization/commands/{source_id}')),
            'java_timeout' => max(5, min(120, (int) ($input['java_timeout'] ?? 30))),
        ];
    }

    public function render(): void
    {
        if (!current_user_can('manage_pc_checkbox_fiscalization')) {
            wp_die(esc_html__('You do not have permission to manage fiscalization.', 'pc-checkbox-fiscalization'));
        }
        $settings = $this->config->settings();
        $recent = $this->operations->recent(25);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Checkbox fiscalization', 'pc-checkbox-fiscalization'); ?></h1>
            <p><?php echo esc_html__('A caller-agnostic executor. It fiscalizes only an explicit command supplied by REST, PHP, or the configured Java command endpoint.', 'pc-checkbox-fiscalization'); ?></p>

            <div class="notice notice-warning inline"><p>
                <?php
                echo esc_html(sprintf(
                    'Fiscalization: %s. Live registers: %s. Secrets are read only from constants or environment variables.',
                    $this->config->fiscalizationEnabled() ? 'ENABLED' : 'LOCKED',
                    $this->config->liveEnabled() ? 'ENABLED' : 'LOCKED'
                ));
                ?>
            </p></div>

            <h2><?php echo esc_html__('Connection status', 'pc-checkbox-fiscalization'); ?></h2>
            <table class="widefat striped" style="max-width:900px"><tbody>
                <?php $this->statusRow('PC_CHECKBOX_LICENSE_KEY', $this->config->licenseKey() !== ''); ?>
                <?php $this->statusRow('PC_CHECKBOX_CASHIER_PIN', $this->config->cashierPin() !== ''); ?>
                <?php $this->statusRow('PC_CHECKBOX_ACCESS_KEY', $this->config->accessKey() !== '', true); ?>
                <?php $this->statusRow('PC_CHECKBOX_INBOUND_TOKEN', $this->config->inboundConfigured()); ?>
                <?php $this->statusRow('PC_CHECKBOX_JAVA_TOKEN', $this->config->javaToken() !== '', true); ?>
            </tbody></table>
            <p>
                <button type="button" class="button" id="pccf-test"><?php echo esc_html__('Test read-only connection', 'pc-checkbox-fiscalization'); ?></button>
                <span class="spinner" id="pccf-spinner"></span>
            </p>
            <pre id="pccf-test-result" style="display:none;max-width:900px;background:#fff;padding:12px;border:1px solid #ccd0d4"></pre>

            <h2><?php echo esc_html__('Open shift', 'pc-checkbox-fiscalization'); ?></h2>
            <p><?php echo esc_html__('Preview the configured register and cashier first. Opening a live shift is a fiscal action, not a connection test. This does not create a receipt or close a shift.', 'pc-checkbox-fiscalization'); ?></p>
            <p><button type="button" class="button" id="pccf-shift-preview"><?php echo esc_html__('Preview shift opening', 'pc-checkbox-fiscalization'); ?></button>
            <button type="button" class="button button-primary" id="pccf-shift-open" disabled><?php echo esc_html__('Confirm opening / check saved attempt', 'pc-checkbox-fiscalization'); ?></button></p>
            <pre id="pccf-shift-result" style="white-space:pre-wrap;max-width:900px"></pre>

            <form method="post" action="options.php">
                <?php settings_fields('pccf'); ?>
                <h2><?php echo esc_html__('Checkbox API client', 'pc-checkbox-fiscalization'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $this->textField('api_base_url', 'API base URL', $settings['api_base_url'], true); ?>
                    <?php $this->textField('client_name', 'Client name', $settings['client_name']); ?>
                    <?php $this->textField('client_version', 'Client version', $settings['client_version']); ?>
                    <?php $this->textField('device_id', 'Device ID', $settings['device_id']); ?>
                    <?php $this->numberField('request_timeout', 'Request timeout, seconds', (int) $settings['request_timeout']); ?>
                    <tr><th scope="row"><label for="pccf-shift-policy">Shift policy</label></th><td>
                        <select id="pccf-shift-policy" name="<?php echo esc_attr(Config::OPTION); ?>[shift_policy]">
                            <option value="require_open" <?php selected($settings['shift_policy'], 'require_open'); ?>>Require an already-opened shift</option>
                            <option value="open_if_missing" <?php selected($settings['shift_policy'], 'open_if_missing'); ?>>Open a shift if missing</option>
                        </select>
                        <p class="description">The plugin never closes a shift.</p>
                    </td></tr>
                </table>

                <h2><?php echo esc_html__('Java command endpoint', 'pc-checkbox-fiscalization'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php $this->textField('java_base_url', 'Java base URL', $settings['java_base_url'], false, 'https://java.internal.example'); ?>
                    <?php $this->textField('java_command_path', 'Command path', $settings['java_command_path']); ?>
                    <?php $this->numberField('java_timeout', 'Java timeout, seconds', (int) $settings['java_timeout']); ?>
                </table>
                <p class="description">The command path must start with / and contain {source_id}. The caller still chooses source_type, source_id, and mode.</p>
                <?php submit_button(); ?>
            </form>

            <h2><?php echo esc_html__('Inbound endpoints', 'pc-checkbox-fiscalization'); ?></h2>
            <p><code>POST <?php echo esc_html(rest_url('pc-checkbox/v1/fiscalize')); ?></code></p>
            <p><code>POST <?php echo esc_html(rest_url('pc-checkbox/v1/fiscalize-source')); ?></code></p>
            <p><code>POST <?php echo esc_html(rest_url('pc-checkbox/v1/reconcile')); ?></code></p>
            <p><code>GET <?php echo esc_html(rest_url('pc-checkbox/v1/operation')); ?>?operation_key=...</code></p>
            <p class="description">External requests must send X-PC-Checkbox-Token. Administrators may use their authenticated WordPress session.</p>

            <h2><?php echo esc_html__('Recent operations', 'pc-checkbox-fiscalization'); ?></h2>
            <table class="widefat striped"><thead><tr>
                <th>Updated (UTC)</th><th>Operation key</th><th>Source</th><th>Type</th><th>Mode</th><th>Status</th><th>Total</th><th>Attempts</th><th>Error code</th>
            </tr></thead><tbody>
            <?php if ($recent === []) : ?>
                <tr><td colspan="9">No operations yet.</td></tr>
            <?php else : foreach ($recent as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row['updated_at']); ?></td>
                    <td><code><?php echo esc_html((string) $row['operation_key']); ?></code></td>
                    <td><?php echo esc_html($row['source_system'] . ':' . $row['source_type'] . ':' . $row['source_id']); ?></td>
                    <td><?php echo esc_html((string) $row['operation_type']); ?></td>
                    <td><?php echo esc_html((string) $row['mode']); ?></td>
                    <td><?php echo esc_html((string) $row['status']); ?></td>
                    <td><?php echo esc_html(number_format_i18n(((int) $row['total_cents']) / 100, 2)); ?> UAH</td>
                    <td><?php echo esc_html((string) $row['attempts']); ?></td>
                    <td><?php echo esc_html((string) $row['error_code']); ?>
                        <?php if (!empty($row['error_message'])) : ?>
                            <br><?php echo esc_html('HTTP ' . (int) $row['http_code'] . ' / ' . (string) $row['error_message']); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody></table>
        </div>
        <script>
        document.getElementById('pccf-test').addEventListener('click', function () {
            const button = this;
            const spinner = document.getElementById('pccf-spinner');
            const output = document.getElementById('pccf-test-result');
            button.disabled = true; spinner.classList.add('is-active'); output.style.display = 'block'; output.textContent = 'Checking…';
            const body = new URLSearchParams({action: 'pccf_connection_test', nonce: <?php echo wp_json_encode(wp_create_nonce('pccf_connection_test')); ?>});
            fetch(ajaxurl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
                .then(response => response.json())
                .then(data => { output.textContent = JSON.stringify(data, null, 2); })
                .catch(() => { output.textContent = 'Connection test failed.'; })
                .finally(() => { button.disabled = false; spinner.classList.remove('is-active'); });
        });
        </script>
        <script>
        (() => {
            const preview = document.getElementById('pccf-shift-preview');
            const open = document.getElementById('pccf-shift-open');
            const output = document.getElementById('pccf-shift-result');
            let ticket = '';
            async function request(action) {
                preview.disabled = true; open.disabled = true;
                try {
                    const body = new URLSearchParams({action, ticket, nonce: <?php echo wp_json_encode(wp_create_nonce('pccf_shift')); ?>});
                    const response = await fetch(ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body});
                    const result = await response.json();
                    const data = result.data || {};
                    ticket = action === 'pccf_shift_preview' && result.success ? data.ticket : '';
                    delete data.ticket; delete data.fingerprint;
                    output.textContent = JSON.stringify(data, null, 2);
                } catch (_) {
                    ticket = '';
                    output.textContent = <?php echo wp_json_encode(__('Result unknown. Preview again to check the saved attempt; do not open a shift elsewhere until it is reconciled.', 'pc-checkbox-fiscalization')); ?>;
                } finally { preview.disabled = false; open.disabled = !ticket; }
            }
            preview.addEventListener('click', () => request('pccf_shift_preview'));
            open.addEventListener('click', () => {
                if (ticket && window.confirm(<?php echo wp_json_encode(__('Open a shift for the cashier and register shown above? LIVE mode creates a real fiscal shift. An unresolved attempt will only be checked.', 'pc-checkbox-fiscalization')); ?>)) request('pccf_shift_open');
            });
        })();
        </script>
        <?php
    }

    public function connectionTest(): void
    {
        check_ajax_referer('pccf_connection_test', 'nonce');
        if (!current_user_can('manage_pc_checkbox_fiscalization')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }
        $result = $this->service->connectionSummary();
        if (is_wp_error($result)) {
            wp_send_json_error(['code' => $result->get_error_code(), 'message' => $result->get_error_message()], 502);
        }
        wp_send_json_success($result);
    }

    public function shiftPreview(): void
    {
        $this->shiftPermission();
        $result = $this->shifts->preview();
        if (is_wp_error($result)) $this->shiftError($result);
        $ticket = wp_generate_uuid4();
        set_transient('pccf_shift_preview_' . get_current_user_id(), ['ticket'=>$ticket,'fingerprint'=>$result['fingerprint']], 300);
        unset($result['fingerprint']);
        wp_send_json_success($result + ['ticket'=>$ticket]);
    }

    public function shiftOpen(): void
    {
        $this->shiftPermission();
        $saved = get_transient('pccf_shift_preview_' . get_current_user_id());
        $ticket = isset($_POST['ticket']) && is_string($_POST['ticket']) ? wp_unslash($_POST['ticket']) : '';
        if (!is_array($saved) || !hash_equals($saved['ticket'], $ticket)) wp_send_json_error(['message'=>__('Preview expired. Preview again before confirming.', 'pc-checkbox-fiscalization')], 409);
        delete_transient('pccf_shift_preview_' . get_current_user_id());
        $result = $this->shifts->openConfirmed($saved['fingerprint']);
        if (is_wp_error($result)) $this->shiftError($result);
        wp_send_json_success($result);
    }

    private function shiftPermission(): void
    {
        check_ajax_referer('pccf_shift', 'nonce');
        if (!current_user_can('manage_pc_checkbox_fiscalization')) wp_send_json_error(['message'=>'Forbidden.'], 403);
    }

    private function shiftError(\WP_Error $error): void
    {
        wp_send_json_error(['code'=>$error->get_error_code(), 'message'=>$error->get_error_message(), 'details'=>$error->get_error_data()], 502);
    }

    private function statusRow(string $name, bool $configured, bool $optional = false): void
    {
        echo '<tr><th scope="row"><code>' . esc_html($name) . '</code></th><td>'
            . esc_html($configured ? 'Configured' : ($optional ? 'Not configured (optional)' : 'Not configured'))
            . '</td></tr>';
    }

    private function textField(string $key, string $label, mixed $value, bool $readonly = false, string $placeholder = ''): void
    {
        printf(
            '<tr><th scope="row"><label for="pccf-%1$s">%2$s</label></th><td><input class="regular-text" id="pccf-%1$s" name="%3$s[%1$s]" type="text" value="%4$s" placeholder="%5$s" %6$s></td></tr>',
            esc_attr($key),
            esc_html($label),
            esc_attr(Config::OPTION),
            esc_attr((string) $value),
            esc_attr($placeholder),
            $readonly ? 'readonly' : ''
        );
    }

    private function numberField(string $key, string $label, int $value): void
    {
        printf(
            '<tr><th scope="row"><label for="pccf-%1$s">%2$s</label></th><td><input id="pccf-%1$s" name="%3$s[%1$s]" type="number" min="5" max="120" value="%4$d"></td></tr>',
            esc_attr($key),
            esc_html($label),
            esc_attr(Config::OPTION),
            $value
        );
    }
}
