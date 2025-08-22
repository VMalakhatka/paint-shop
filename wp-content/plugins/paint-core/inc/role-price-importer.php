<?php
/*
Plugin Name: Role Price Importer
Description: Импорт цен по ролям из CSV в таблицу wp_role_prices_import.
Author: Volodymyr
Version: 1.0.0
*/
if (!defined('ABSPATH')) exit;

class VP_Role_Price_Importer {
    const TABLE = 'wp_role_prices_import'; // если у тебя другой префикс — можно заменить на $wpdb->prefix ниже
    const CAP   = 'manage_options';
    const SLUG  = 'vp-role-price-importer';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_vp_rpi_import', [$this, 'handle_import']);
    }

    /** Создание (или обновление) таблицы */
    public function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'role_prices_import';
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "
            CREATE TABLE {$table} (
                sku       VARCHAR(191) NOT NULL,
                partner   DECIMAL(18,6) NULL,
                opt       DECIMAL(18,6) NULL,
                opt_osn   DECIMAL(18,6) NULL,
                schule    DECIMAL(18,6) NULL,
                PRIMARY KEY (sku)
            ) {$charset};
        ";
        dbDelta($sql);
    }

    /** Пункт меню в Админке */
    public function menu() {
        add_submenu_page(
            'tools.php',
            'Импорт цен по ролям',
            'Импорт цен (CSV)',
            self::CAP,
            self::SLUG,
            [$this, 'page']
        );
    }

    /** Страница импорта */
    public function page() {
        if (!current_user_can(self::CAP)) wp_die('Недостаточно прав.');
        $ok   = isset($_GET['ok'])   ? intval($_GET['ok'])   : 0;
        $skip = isset($_GET['skip']) ? intval($_GET['skip']) : 0;
        ?>
        <div class="wrap">
            <h1>Импорт цен по ролям (CSV → wp_role_prices_import)</h1>
            <p>Ожидаемые колонки в CSV (разделитель <code>;</code>): 
               <code>sku;partner;opt;opt_osn;schule</code>.
               Заголовок обязателен. Кодировка — UTF‑8 (BOM допускается).</p>

            <?php if ($ok): ?>
                <div class="notice notice-success"><p>
                    Импорт завершён. Записей добавлено/обновлено: <strong><?php echo esc_html($ok); ?></strong>.
                    Пропущено: <strong><?php echo esc_html($skip); ?></strong>.
                </p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('vp_rpi_import'); ?>
                <input type="hidden" name="action" value="vp_rpi_import">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="csv_file">CSV файл</label></th>
                        <td><input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required></td>
                    </tr>
                    <tr>
                        <th scope="row">Режим</th>
                        <td>
                            <label>
                                <input type="checkbox" name="truncate" value="1">
                                Перед импортом очистить таблицу (TRUNCATE)
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Импортировать'); ?>
            </form>

            <hr>
            <h2>Пример CSV</h2>
<pre>sku;partner;opt;opt_osn;schule
CR-CE0900056476;84.97;88.28;91.58;
CR-CE0900056730;84.97;88.28;91.58;77.00
</pre>
        </div>
        <?php
    }

    /** Обработка загрузки и импорта */
    public function handle_import() {
        if (!current_user_can(self::CAP)) wp_die('Недостаточно прав.');
        check_admin_referer('vp_rpi_import');

        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            wp_redirect(add_query_arg(['page'=>self::SLUG], admin_url('tools.php')));
            exit;
        }

        // Безопасная загрузка во временную папку UPLOADS
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = ['test_form'=>false, 'mimes'=>['csv'=>'text/csv','txt'=>'text/plain']];
        $file = wp_handle_upload($_FILES['csv_file'], $overrides);

        if (!empty($file['error'])) {
            wp_die('Ошибка загрузки файла: ' . esc_html($file['error']));
        }

        $path = $file['file'];
        [$ok, $skip] = $this->import_csv($path, !empty($_POST['truncate']));
        // Удалить файл после чтения
        @unlink($path);

        wp_redirect(add_query_arg(['page'=>self::SLUG, 'ok'=>$ok, 'skip'=>$skip], admin_url('tools.php')));
        exit;
    }

    /** Импорт содержимого CSV в таблицу */
    private function import_csv(string $path, bool $truncate): array {
        global $wpdb;
        $table = $wpdb->prefix . 'role_prices_import';

        if ($truncate) {
            $wpdb->query("TRUNCATE TABLE {$table}");
        }

        $fh = fopen($path, 'r');
        if (!$fh) return [0,0];

        // Чтение первой строки — шапка
        $header = $this->read_csv_row($fh);
        if (!$header) return [0,0];

        // Приведём имена колонок к нижнему регистру и уберём пробелы
        $map = [];
        foreach ($header as $i => $name) {
            $k = strtolower(trim($name));
            $map[$k] = $i;
        }

        $required = ['sku'];
        foreach ($required as $req) {
            if (!isset($map[$req])) {
                fclose($fh);
                wp_die('В CSV отсутствует обязательная колонка: ' . esc_html($req));
            }
        }

        $ok = 0; $skip = 0;

        // Подготовленный REPLACE (обновит по PK sku)
        $sql = "REPLACE INTO {$table} (sku, partner, opt, opt_osn, schule) VALUES (%s,%f,%f,%f,%f)";

        while (($row = $this->read_csv_row($fh)) !== null) {
            if ($row === []) continue;

            $sku = $this->val($row, $map, 'sku');
            if ($sku === '') { $skip++; continue; }

            $partner = $this->num($row, $map, 'partner');
            $opt     = $this->num($row, $map, 'opt');
            $opt_osn = $this->num($row, $map, 'opt_osn');
            $schule  = $this->num($row, $map, 'schule');

            $wpdb->query( $wpdb->prepare($sql, $sku, $partner, $opt, $opt_osn, $schule) );
            $ok++;
        }
        fclose($fh);
        return [$ok, $skip];
    }

    /** Читает строку CSV с поддержкой ; или , и BOM; возвращает array|null */
    private function read_csv_row($fh) {
        // fgetcsv delimiter autodetect (try ; then ,)
        $pos = ftell($fh);
        $line = fgets($fh);
        if ($line === false) return null;
        // вернём каретку чтобы прочитать правильно fgetcsv
        fseek($fh, $pos, SEEK_SET);

        // Уберём BOM у первой строки
        $bom = "\xEF\xBB\xBF";
        if (str_starts_with($line, $bom)) {
            $line = substr($line, 3);
            // подменим буфер: читаем вручную первую строку
            $row = str_getcsv($line, ';');
            if (count($row) === 1) $row = str_getcsv($line, ',');
            return array_map([$this,'clean'], $row);
        }

        $row = fgetcsv($fh, 0, ';');
        if ($row && count($row) === 1) { // возможно запятая
            fseek($fh, $pos, SEEK_SET);
            $row = fgetcsv($fh, 0, ',');
        }
        if ($row === false) return null;
        return array_map([$this,'clean'], $row);
    }

    private function clean($v) {
        $v = trim((string)$v);
        // возможные запятые как десятичный разделитель → точка
        $v = preg_replace('/\s+/', ' ', $v);
        return $v;
    }

    private function val(array $row, array $map, string $key): string {
        return isset($map[$key], $row[$map[$key]]) ? trim((string)$row[$map[$key]]) : '';
    }
    private function num(array $row, array $map, string $key) {
        if (!isset($map[$key]) || !isset($row[$map[$key]])) return null;
        $raw = str_replace(',', '.', trim((string)$row[$map[$key]]));
        return ($raw === '' ? null : (float)$raw);
    }
}

new VP_Role_Price_Importer();