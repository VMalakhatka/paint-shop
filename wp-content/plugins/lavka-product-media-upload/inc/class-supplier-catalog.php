<?php
namespace Lavka\ProductMediaUpload;

if (!defined('ABSPATH')) { exit; }

/** Supplier snapshots are staging data. This class never assigns product media. */
final class SupplierCatalog
{
    public const OPTION = 'lpmu_supplier_sources';
    private string $hook = '';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('lpmu_supplier_daily', [$this, 'daily']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_lpmu_supplier', [$this, 'ajax']);
        add_action('admin_post_lpmu_supplier_image', [$this, 'image']);
    }

    public static function capability(): string
    {
        return (string) apply_filters('lavka_product_media_upload_capability', 'manage_woocommerce');
    }

    public function menu(): void
    {
        $this->hook = (string) add_submenu_page('upload.php', __('Supplier catalogues', 'lavka-product-media-upload'),
            __('Supplier catalogues', 'lavka-product-media-upload'), self::capability(), 'lavka-supplier-catalogs', [$this, 'render']);
    }

    public function assets(string $hook): void
    {
        if ($hook !== $this->hook) { return; }
        Plugin::instance()->enqueue_assets($hook, true);
        wp_enqueue_style('lpmu-suppliers', LPMU_URL . 'assets/suppliers.css', [], LPMU_VERSION);
        wp_enqueue_script('lpmu-suppliers', LPMU_URL . 'assets/suppliers.js', ['lpmu-admin'], LPMU_VERSION, true);
        wp_localize_script('lpmu-suppliers', 'LPMU_SUPPLIERS', [
            'ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lpmu_supplier'),
            'canConfigure' => current_user_can('manage_options'), 'mapping' => SupplierFeed::defaults(),
            'strings' => require __DIR__ . '/supplier-strings.php',
        ]);
    }

    public function render(): void
    {
        if (!current_user_can(self::capability()) || !current_user_can('upload_files')) { wp_die(esc_html__('Access denied.', 'lavka-product-media-upload')); }
        require __DIR__ . '/supplier-page.php';
    }

    private static function table(): string { global $wpdb; return $wpdb->prefix . 'lpmu_supplier_items'; }

    public static function install(): void
    {
        if (get_option('lpmu_supplier_schema') === '1') { return; }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            source varchar(32) NOT NULL,
            generation varchar(32) NOT NULL,
            external_key char(64) NOT NULL,
            sku varchar(190) NOT NULL DEFAULT '',
            barcode varchar(190) NOT NULL DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            brand varchar(190) NOT NULL DEFAULT '',
            category varchar(190) NOT NULL DEFAULT '',
            product_id bigint unsigned NOT NULL DEFAULT 0,
            match_state varchar(24) NOT NULL DEFAULT '',
            manual_sku varchar(190) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY snapshot_item (source,generation,external_key),
            KEY snapshot (source,generation),
            KEY product (product_id)
        ) " . $wpdb->get_charset_collate() . ';');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) { throw new \RuntimeException('CATALOGUE_SCHEMA'); }
        update_option('lpmu_supplier_schema', '1', false);
    }

    private static function sources(): array { return (array) get_option(self::OPTION, []); }
    private static function source(string $id): array
    {
        $all = self::sources();
        if (!isset($all[$id])) { throw new \RuntimeException(__('Select a saved supplier.', 'lavka-product-media-upload')); }
        return $all[$id];
    }
    private static function active(string $id): array { return (array) get_option('lpmu_supplier_active_' . $id, []); }

    public function ajax(): void
    {
        if (!current_user_can(self::capability()) || !current_user_can('upload_files')) { wp_send_json_error(['message' => __('Access denied.', 'lavka-product-media-upload')], 403); }
        check_ajax_referer('lpmu_supplier', 'nonce');
        try {
            self::install();
            $op = sanitize_key(wp_unslash($_POST['op'] ?? ''));
            $id = sanitize_key(wp_unslash($_POST['source'] ?? ''));
            if ($op === 'sources') {
                $out = [];
                foreach (self::sources() as $key => $source) {
                    $out[] = ['id' => $key, 'name' => $source['name'], 'type' => $source['type'],
                        'active' => self::active($key), 'status' => get_option('lpmu_supplier_status_' . $key, []),
                        'url' => $source['type'] === 'drive' || current_user_can('manage_options') ? $source['url'] : '',
                        'mapping' => $source['mapping'], 'daily' => !empty($source['daily']), 'match_sku' => $source['match_sku']];
                }
                $result = $out;
            } elseif ($op === 'save') {
                if (!current_user_can('manage_options')) { throw new \RuntimeException(__('Only an administrator can configure supplier sources.', 'lavka-product-media-upload')); }
                $all = self::sources();
                if ($id && get_option('lpmu_supplier_import_' . $id)) { throw new \RuntimeException(__('This catalogue is already being refreshed. Check its status before retrying.', 'lavka-product-media-upload')); }
                if (!$id) { $id = str_replace('-', '', wp_generate_uuid4()); }
                if (count($all) >= 30 && !isset($all[$id])) { throw new \RuntimeException('SOURCE_LIMIT'); }
                $type = sanitize_key(wp_unslash($_POST['type'] ?? 'xml'));
                $url = trim(wp_unslash($_POST['url'] ?? ''));
                if (!in_array($type, ['xml', 'drive'], true)) { throw new \RuntimeException('SOURCE_TYPE'); }
                if ($url !== '') { SupplierFeed::url($url); }
                if ($type === 'drive' && !self::folder_id($url)) { throw new \RuntimeException(__('Use a Google Drive folder link.', 'lavka-product-media-upload')); }
                $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
                if ($name === '') { throw new \RuntimeException(__('Enter the supplier name.', 'lavka-product-media-upload')); }
                $mapping = json_decode(wp_unslash($_POST['mapping'] ?? '{}'), true);
                $all[$id] = ['name' => mb_substr($name, 0, 100), 'url' => $url, 'type' => $type,
                    'daily' => !empty($_POST['daily']), 'mapping' => SupplierFeed::mapping(is_array($mapping) ? $mapping : []), 'match_sku' => !empty($_POST['match_sku'])];
                update_option(self::OPTION, $all, false);
                wp_clear_scheduled_hook('lpmu_supplier_daily', [$id]);
                if ($all[$id]['daily'] && $all[$id]['url']) { wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'lpmu_supplier_daily', [$id]); }
                $result = ['id' => $id];
            } elseif ($op === 'refresh') {
                $result = $this->refresh($id);
            } elseif ($op === 'status') {
                self::source($id);
                $result = ['status' => get_option('lpmu_supplier_status_' . $id, []), 'active' => self::active($id)];
            } elseif ($op === 'list') {
                $result = $this->listing($id);
            } elseif ($op === 'map') {
                $item = $this->item((int) ($_POST['item'] ?? 0));
                $sku = sanitize_text_field(wp_unslash($_POST['sku'] ?? ''));
                if (get_option('lpmu_supplier_import_' . $item['source'])) { throw new \RuntimeException(__('This catalogue is already being refreshed. Check its status before retrying.', 'lavka-product-media-upload')); }
                $match = (new ProductResolver())->resolve($sku, '');
                if ($sku === '' || empty($match['ok'])) { throw new \RuntimeException(__('Enter an exact SKU of an existing product on our site.', 'lavka-product-media-upload')); }
                global $wpdb;
                $wpdb->update(self::table(), ['manual_sku' => $sku, 'product_id' => $match['product_id'], 'match_state' => 'manual'], ['id' => $item['id']]);
                $result = ['ok' => true];
            } elseif ($op === 'registry') {
                $result = $this->registry();
            } else { throw new \RuntimeException('UNKNOWN_OPERATION'); }
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public function daily(string $id): void
    {
        try {
            if (empty(self::source($id)['daily'])) { return; }
            self::install();
            $this->refresh($id);
        } catch (\Throwable $e) {
            // Durable source status is shown to managers. Never publish media from cron.
        }
    }

    private function refresh(string $id): array
    {
        $source = self::source($id);
        $lock = 'lpmu_supplier_import_' . $id;
        $started = (int) get_option($lock, 0);
        if ($started && $started < time() - 900) { delete_option($lock); }
        if (!add_option($lock, time(), '', false)) { throw new \RuntimeException(__('This catalogue is already being refreshed. Check its status before retrying.', 'lavka-product-media-upload')); }
        ignore_user_abort(true);
        @set_time_limit(600);
        $generation = str_replace('-', '', wp_generate_uuid4());
        $count = 0; $path = '';
        global $wpdb;
        $table = self::table();
        $old = self::active($id);
        update_option('lpmu_supplier_status_' . $id, ['state' => 'running', 'count' => 0, 'started' => time()], false);
        try {
            $manual = [];
            if (!empty($old['generation'])) {
                $previous = $wpdb->get_results($wpdb->prepare("SELECT external_key,manual_sku FROM $table WHERE source=%s AND generation=%s AND manual_sku<>''", $id, $old['generation']), ARRAY_A);
                if (!is_array($previous)) { throw new \RuntimeException('CATALOGUE_READ_FAILED'); }
                foreach ($previous as $r) { $manual[$r['external_key']] = $r['manual_sku']; }
            }
            if ($source['type'] === 'drive') { $rows = $this->drive_rows($source['url']); }
            else {
                if (isset($_FILES['xml']) && (int) $_FILES['xml']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $upload = $_FILES['xml'];
                    if ((int) $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']) || (int) $upload['size'] > SupplierFeed::MAX_BYTES) { throw new \RuntimeException(__('The XML upload failed or exceeds 60 MiB.', 'lavka-product-media-upload')); }
                    $path = wp_tempnam('supplier-xml');
                    if (!$path || !move_uploaded_file($upload['tmp_name'], $path)) { throw new \RuntimeException('TEMP_FILE'); }
                } else { $path = SupplierFeed::download($source['url'], SupplierFeed::MAX_BYTES); }
                $rows = SupplierFeed::read($path, $source['mapping']);
            }
            // One read builds the lookup; no query per supplier product.
            $lookup = $this->lookup();
            foreach ($rows as $row) {
                $row['images'] = array_map(static fn($url) => \WP_Http::make_absolute_url($url, $source['url']), $row['images']);
                $key = hash('sha256', (string) $row['id']);
                $mapped = $manual[$key] ?? '';
                $match = self::match($row, $lookup, (bool) $source['match_sku'], $mapped);
                $ok = $wpdb->insert($table, ['source' => $id, 'generation' => $generation, 'external_key' => $key,
                    'sku' => mb_substr($row['sku'], 0, 190), 'barcode' => mb_substr($row['barcode'], 0, 190),
                    'name' => mb_substr(wp_strip_all_tags($row['name']), 0, 255), 'brand' => mb_substr($row['brand'], 0, 190),
                    'category' => mb_substr($row['category'], 0, 190), 'product_id' => $match['id'], 'match_state' => $match['state'],
                    'manual_sku' => $mapped, 'payload' => wp_json_encode($row, JSON_UNESCAPED_UNICODE)]);
                if (!$ok) { throw new \RuntimeException(__('The catalogue could not be saved. The previous version has been retained.', 'lavka-product-media-upload')); }
                if (++$count % 250 === 0) { update_option('lpmu_supplier_status_' . $id, ['state' => 'running', 'count' => $count, 'started' => time()], false); }
            }
            if (!$count) { throw new \RuntimeException(__('No products found. Check the product element and field mapping.', 'lavka-product-media-upload')); }
            $active = ['generation' => $generation, 'count' => $count, 'updated' => current_time('mysql')];
            if (!update_option('lpmu_supplier_active_' . $id, $active, false)) {
                throw new \RuntimeException(__('The catalogue could not be saved. The previous version has been retained.', 'lavka-product-media-upload'));
            }
            update_option('lpmu_supplier_status_' . $id, ['state' => 'complete', 'count' => $count], false);
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE source=%s AND generation<>%s", $id, $generation));
            return $active;
        } catch (\Throwable $e) {
            $wpdb->delete($table, ['source' => $id, 'generation' => $generation]);
            update_option('lpmu_supplier_status_' . $id, ['state' => 'failed', 'count' => $count, 'message' => $e->getMessage()], false);
            throw $e;
        } finally { if ($path) { @unlink($path); } delete_option($lock); }
    }

    private function lookup(): array
    {
        global $wpdb;
        $keys = array_values(array_filter(array_map('sanitize_key', (array) apply_filters('lavka_product_media_upload_barcode_meta_keys', ['_wc_gtin_code','_global_unique_id','_wpm_gtin_code','_alg_ean','_ean','_sku_gtin','_gtin']))));
        $keys[] = '_sku';
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $data = $wpdb->get_results($wpdb->prepare("SELECT p.ID,m.meta_key,m.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type IN ('product','product_variation') AND p.post_status<>'trash' AND m.meta_key IN ($placeholders) AND m.meta_value<>''", $keys), ARRAY_A);
        if (!is_array($data)) { throw new \RuntimeException('PRODUCT_LOOKUP_FAILED'); }
        $out = ['sku' => [], 'barcode' => []];
        foreach ($data as $r) { $kind = $r['meta_key'] === '_sku' ? 'sku' : 'barcode'; $out[$kind][(string) $r['meta_value']][(int) $r['ID']] = true; }
        return $out;
    }

    public static function match(array $row, array $lookup, bool $use_sku, string $manual = ''): array
    {
        $s = array_keys($lookup['sku'][$manual ?: ($use_sku ? $row['sku'] : '')] ?? []);
        $b = $manual ? [] : array_keys($lookup['barcode'][$row['barcode']] ?? []);
        if (count($s) > 1 || count($b) > 1 || ($s && $b && $s[0] !== $b[0])) { return ['id' => 0, 'state' => 'ambiguous']; }
        $id = $s[0] ?? $b[0] ?? 0;
        return ['id' => $id, 'state' => $id ? ($manual ? 'manual' : ($b ? 'barcode' : 'sku')) : 'unmatched'];
    }

    private function item(int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d', $id), ARRAY_A);
        if (!$row || (self::active($row['source'])['generation'] ?? '') !== $row['generation']) {
            throw new \RuntimeException(__('The catalogue changed. Reload the list and select the images again.', 'lavka-product-media-upload'));
        }
        $row['data'] = json_decode($row['payload'], true);
        return $row;
    }

    private function listing(string $id): array
    {
        self::source($id);
        $active = self::active($id);
        if (!$active) { return ['items' => [], 'total' => 0, 'brands' => [], 'categories' => []]; }
        global $wpdb;
        $table = self::table();
        $where = 'i.source=%s AND i.generation=%s';
        $args = [$id, $active['generation']];
        foreach (['brand', 'category'] as $field) {
            $value = sanitize_text_field(wp_unslash($_POST[$field] ?? ''));
            if ($value !== '') { $where .= " AND i.$field=%s"; $args[] = $value; }
        }
        $q = trim(sanitize_textarea_field(wp_unslash($_POST['q'] ?? '')));
        if ($q !== '') {
            $terms = preg_split('/[\r\n,;]+/', $q, -1, PREG_SPLIT_NO_EMPTY);
            if (count($terms) > 1) {
                $terms = array_slice(array_map('trim', $terms), 0, 200);
                $marks = implode(',', array_fill(0, count($terms), '%s'));
                $where .= " AND (i.sku IN ($marks) OR i.barcode IN ($marks) OR i.manual_sku IN ($marks))";
                $args = array_merge($args, $terms, $terms, $terms);
            } else {
                $like = '%' . $wpdb->esc_like($q) . '%';
                $where .= ' AND (i.name LIKE %s OR i.sku LIKE %s OR i.barcode LIKE %s OR i.manual_sku LIKE %s)';
                array_push($args, $like, $like, $like, $like);
            }
        }
        $filter = sanitize_key($_POST['filter'] ?? 'all');
        if ($filter === 'matched') { $where .= ' AND i.product_id>0'; }
        if ($filter === 'unmatched') { $where .= ' AND i.product_id=0'; }
        if ($filter === 'missing') { $where .= " AND i.product_id>0 AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=i.product_id AND m.meta_key='_thumbnail_id' AND CAST(m.meta_value AS UNSIGNED)>0)"; }
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table i WHERE $where", $args));
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT i.* FROM $table i WHERE $where ORDER BY i.id LIMIT 12 OFFSET %d", array_merge($args, [($page - 1) * 12])), ARRAY_A);
        $items = [];
        foreach ($rows as $row) {
            $data = json_decode($row['payload'], true);
            $product = $row['product_id'] ? wc_get_product((int) $row['product_id']) : false;
            $current = [];
            if ($product) {
                foreach (array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids())) as $aid) {
                    $url = wp_get_attachment_image_url($aid, 'thumbnail'); if ($url) { $current[] = $url; }
                }
            }
            $items[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'sku' => $row['sku'], 'barcode' => $row['barcode'],
                'brand' => $row['brand'], 'category' => $row['category'], 'description' => wp_strip_all_tags($data['description']), 'price' => $data['price'] ?? '',
                'attributes' => $data['attributes'] ?? [], 'state' => $row['match_state'], 'kind' => $data['kind'] ?? 'image', 'link' => $data['link'] ?? '',
                'product' => $product ? ['id' => $product->get_id(), 'sku' => $product->get_sku(), 'name' => $product->get_name(), 'type' => $product->get_type(), 'current' => $current] : null,
                'images' => array_map(function ($url, $n) use ($row) {
                    return ['index' => $n, 'url' => $this->image_url((int) $row['id'], $n)];
                }, $data['images'] ?? [], array_keys($data['images'] ?? []))];
        }
        $facets = [];
        foreach (['brand', 'category'] as $field) {
            $facets[$field] = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT $field FROM $table WHERE source=%s AND generation=%s AND $field<>'' ORDER BY $field LIMIT 500", $id, $active['generation']));
        }
        return ['items' => $items, 'total' => $total, 'brands' => $facets['brand'], 'categories' => $facets['category']];
    }

    private function image_url(int $id, int $index): string
    {
        return add_query_arg(['action' => 'lpmu_supplier_image', 'item' => $id, 'index' => $index,
            '_wpnonce' => wp_create_nonce('lpmu_supplier_image')], admin_url('admin-post.php'));
    }

    public function image(): void
    {
        if (!current_user_can(self::capability()) || !current_user_can('upload_files')) { wp_die('Forbidden', '', ['response' => 403]); }
        check_admin_referer('lpmu_supplier_image');
        $path = '';
        try {
            $row = $this->item((int) ($_GET['item'] ?? 0));
            $index = (int) ($_GET['index'] ?? -1);
            $url = $row['data']['images'][$index] ?? '';
            if (!$url || ($row['data']['kind'] ?? '') !== 'image') { throw new \RuntimeException('IMAGE_NOT_FOUND'); }
            $headers = !empty($row['data']['drive']) ? $this->drive_headers() : [];
            $path = SupplierFeed::download($url, min(10 * MB_IN_BYTES, (int) ImageValidator::thresholds()['max_file_bytes']), $headers);
            $mime = wp_get_image_mime($path);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { throw new \RuntimeException('UNSUPPORTED_IMAGE'); }
            while (ob_get_level()) { ob_end_clean(); }
            nocache_headers();
            header('Content-Type: ' . $mime);
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($path));
            readfile($path);
        } catch (\Throwable $e) { status_header(400); echo esc_html__('The image is unavailable or unsupported. Open the supplier source to check it.', 'lavka-product-media-upload'); }
        finally { if ($path) { @unlink($path); } }
        exit;
    }

    private function registry(): array
    {
        $selection = json_decode(wp_unslash($_POST['selection'] ?? '[]'), true);
        $limit = min(20, max(1, (int) ini_get('max_file_uploads') - 1));
        if (!is_array($selection) || !$selection || count($selection) > $limit) { throw new \RuntimeException(__('Select a small batch of up to 20 images (or the server file limit).', 'lavka-product-media-upload')); }
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) { throw new \RuntimeException('PHPSPREADSHEET_UNAVAILABLE'); }
        $sheetbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $sheetbook->getActiveSheet();
        $sheet->fromArray([['sku','barcode','source_file','role','position']]);
        $files = []; $roles = []; $lookup = $this->lookup();
        foreach ($selection as $n => $s) {
            $row = $this->item((int) ($s['item'] ?? 0));
            $index = (int) ($s['index'] ?? -1);
            if (!isset($row['data']['images'][$index]) || ($row['data']['kind'] ?? '') !== 'image') { throw new \RuntimeException('IMAGE_NOT_FOUND'); }
            $source = self::source($row['source']);
            $check = self::match($row['data'], $lookup, (bool) $source['match_sku'], $row['manual_sku']);
            if (!$check['id'] || $check['id'] !== (int) $row['product_id']) { throw new \RuntimeException(__('The product match changed. Refresh the catalogue or confirm the exact SKU again.', 'lavka-product-media-upload')); }
            $p = wc_get_product($check['id']);
            if (!$p || !$p->get_sku() || !in_array($p->get_type(), ['simple','variable','variation'], true)) { throw new \RuntimeException(__('The matched product needs an exact SKU.', 'lavka-product-media-upload')); }
            $role = $s['role'] ?? '';
            $pos = (int) ($s['position'] ?? 0);
            if (!in_array($role, ['main','gallery'], true) || ($role === 'gallery' && ($pos < 1 || $p->is_type('variation')))) { throw new \RuntimeException(__('Choose a valid image role and gallery position. Variations support a main image only.', 'lavka-product-media-upload')); }
            $key = $p->get_id() . ':' . $role . ':' . ($role === 'main' ? 0 : $pos);
            if (isset($roles[$key])) { throw new \RuntimeException(__('Use one main image and unique gallery positions for each product.', 'lavka-product-media-upload')); }
            $roles[$key] = true;
            // Browser supplies actual decoded MIME extension after downloading; validate that extension separately.
            $ext = sanitize_key($s['extension'] ?? '');
            if (!in_array($ext, ['jpg','png','webp'], true)) { throw new \RuntimeException('IMAGE_EXTENSION'); }
            $filename = 'supplier-' . $row['id'] . '-' . $index . '.' . $ext;
            $values = [$p->get_sku(), '', $filename, $role, $role === 'main' ? '' : (string) $pos];
            foreach ($values as $col => $value) { $sheet->setCellValueExplicit([$col + 1, $n + 2], $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING); }
            $files[] = $filename;
        }
        $tmp = wp_tempnam('supplier-registry');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheetbook))->save($tmp);
            return ['xlsx' => base64_encode(file_get_contents($tmp)), 'files' => $files];
        } finally { @unlink($tmp); $sheetbook->disconnectWorksheets(); }
    }

    private static function folder_id(string $url): string
    {
        $p = wp_parse_url($url);
        return ($p['host'] ?? '') === 'drive.google.com' && preg_match('~/folders/([A-Za-z0-9_-]+)~', $p['path'] ?? '', $m) ? $m[1] : '';
    }

    private function drive_headers(): array
    {
        $key = defined('LPMU_GOOGLE_DRIVE_API_KEY') ? (string) LPMU_GOOGLE_DRIVE_API_KEY : '';
        if (!$key) { throw new \RuntimeException(__('Automatic Drive reading needs a server Google Drive API key. Open the folder and use the image uploader for downloaded photos meanwhile.', 'lavka-product-media-upload')); }
        return ['X-Goog-Api-Key' => $key];
    }

    private function drive_rows(string $url): \Generator
    {
        $headers = $this->drive_headers();
        $queue = [[self::folder_id($url), '']]; $seen = []; $count = 0;
        while ($queue) {
            [$folder, $category] = array_shift($queue);
            if (isset($seen[$folder])) { continue; }
            $seen[$folder] = true;
            if (count($seen) > 200) { throw new \RuntimeException('DRIVE_FOLDER_LIMIT'); }
            $token = '';
            do {
                $api = add_query_arg(['q' => "'$folder' in parents and trashed = false", 'pageSize' => 100,
                    'fields' => 'nextPageToken,incompleteSearch,files(id,name,mimeType,webViewLink)', 'pageToken' => $token], 'https://www.googleapis.com/drive/v3/files');
                $path = SupplierFeed::download($api, 2 * MB_IN_BYTES, $headers);
                try { $response = json_decode(file_get_contents($path), true); } finally { @unlink($path); }
                if (!is_array($response) || !isset($response['files']) || !empty($response['incompleteSearch'])) { throw new \RuntimeException('DRIVE_INCOMPLETE'); }
                foreach ($response['files'] as $file) {
                    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $file['id'] ?? '')) { continue; }
                    if ($file['mimeType'] === 'application/vnd.google-apps.folder') { $queue[] = [$file['id'], $category ? $category . ' / ' . $file['name'] : $file['name']]; continue; }
                    $image = in_array($file['mimeType'], ['image/jpeg','image/png','image/webp'], true);
                    $video = str_starts_with($file['mimeType'], 'video/');
                    if (!$image && !$video) { continue; }
                    if (++$count > 10000) { throw new \RuntimeException('DRIVE_FILE_LIMIT'); }
                    yield ['id' => $file['id'], 'sku' => '', 'barcode' => '', 'name' => $file['name'], 'brand' => '',
                        'category' => $category, 'description' => '', 'price' => '', 'kind' => $image ? 'image' : 'video',
                        'drive' => true, 'link' => 'https://drive.google.com/file/d/' . $file['id'] . '/view',
                        'images' => $image ? ['https://www.googleapis.com/drive/v3/files/' . $file['id'] . '?alt=media'] : []];
                }
                $token = $response['nextPageToken'] ?? '';
            } while ($token);
        }
    }
}
