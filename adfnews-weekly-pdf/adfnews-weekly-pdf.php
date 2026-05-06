<?php
/**
 * Plugin Name: ADFNews Weekly PDF
 * Description: Genera PDF articoli con cron e configurazione completa da pannello admin.
 * Version: 1.0.0
 * Author: ADFNews
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: adfnews-weekly-pdf
 */

if (!defined('ABSPATH')) {
    exit;
}

class ADFNews_Weekly_PDF_Plugin
{
    private const OPTION_KEY = 'adfnews_pdf_options';
    private const CRON_HOOK = 'adfnews_pdf_hourly_event';
    private const NONCE_ACTION = 'adfnews_pdf_admin_action';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_adfnews_pdf_manual_regen', [self::class, 'handleManualRegeneration']);
        add_action('admin_post_adfnews_pdf_test_generation', [self::class, 'handleTestGeneration']);
        add_action(self::CRON_HOOK, [self::class, 'maybeRunCronGeneration']);
        add_action('admin_notices', [self::class, 'renderAdminNotice']);
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public static function registerAdminMenu(): void
    {
        add_options_page(
            'ADF News PDF',
            'ADF News PDF',
            'manage_options',
            'adfnews-pdf-settings',
            [self::class, 'renderSettingsPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitizeOptions'],
            'default' => self::defaultOptions(),
        ]);
    }

    public static function sanitizeOptions(array $input): array
    {
        $defaults = self::defaultOptions();
        $tz_list = timezone_identifiers_list();

        $output = [];
        $output['regen_weekday'] = in_array(($input['regen_weekday'] ?? ''), array_keys(self::weekdayOptions()), true)
            ? $input['regen_weekday']
            : $defaults['regen_weekday'];
        $output['regen_hour'] = max(0, min(23, intval($input['regen_hour'] ?? $defaults['regen_hour'])));
        $output['timezone'] = in_array(($input['timezone'] ?? ''), $tz_list, true)
            ? $input['timezone']
            : $defaults['timezone'];
        $output['include_categories'] = sanitize_text_field($input['include_categories'] ?? '');
        $output['exclude_categories'] = sanitize_text_field($input['exclude_categories'] ?? '');
        $output['max_articles'] = max(1, min(200, intval($input['max_articles'] ?? $defaults['max_articles'])));
        $output['include_images'] = !empty($input['include_images']) ? 1 : 0;
        $output['date_from'] = self::sanitizeDateTime($input['date_from'] ?? '');
        $output['date_to'] = self::sanitizeDateTime($input['date_to'] ?? '');

        return wp_parse_args($output, $defaults);
    }

    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = wp_parse_args(get_option(self::OPTION_KEY, []), self::defaultOptions());
        ?>
        <div class="wrap">
            <h1>ADF News PDF - Configurazione</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="regen_weekday">Giorno rigenerazione</label></th>
                        <td>
                            <select id="regen_weekday" name="<?php echo esc_attr(self::OPTION_KEY); ?>[regen_weekday]">
                                <?php foreach (self::weekdayOptions() as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($options['regen_weekday'], $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Il cron interno controlla ogni ora e genera solo quando combacia giorno/ora.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="regen_hour">Ora rigenerazione (0-23)</label></th>
                        <td>
                            <input id="regen_hour" type="number" min="0" max="23" name="<?php echo esc_attr(self::OPTION_KEY); ?>[regen_hour]" value="<?php echo esc_attr((string) $options['regen_hour']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="timezone">Timezone</label></th>
                        <td>
                            <select id="timezone" name="<?php echo esc_attr(self::OPTION_KEY); ?>[timezone]">
                                <?php foreach (timezone_identifiers_list() as $tz) : ?>
                                    <option value="<?php echo esc_attr($tz); ?>" <?php selected($options['timezone'], $tz); ?>>
                                        <?php echo esc_html($tz); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="include_categories">Categorie incluse (ID separati da virgola)</label></th>
                        <td>
                            <input id="include_categories" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_categories]" value="<?php echo esc_attr($options['include_categories']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="exclude_categories">Categorie escluse (ID separati da virgola)</label></th>
                        <td>
                            <input id="exclude_categories" type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[exclude_categories]" value="<?php echo esc_attr($options['exclude_categories']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_articles">Massimo articoli</label></th>
                        <td>
                            <input id="max_articles" type="number" min="1" max="200" name="<?php echo esc_attr(self::OPTION_KEY); ?>[max_articles]" value="<?php echo esc_attr((string) $options['max_articles']); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Includi immagini nel PDF</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_images]" value="1" <?php checked(1, intval($options['include_images'])); ?> />
                                Abilita immagini in output (se disponibili)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Intervallo date per PDF test</th>
                        <td>
                            <label for="date_from">Da</label><br />
                            <input id="date_from" type="datetime-local" name="<?php echo esc_attr(self::OPTION_KEY); ?>[date_from]" value="<?php echo esc_attr($options['date_from']); ?>" /><br /><br />
                            <label for="date_to">A</label><br />
                            <input id="date_to" type="datetime-local" name="<?php echo esc_attr(self::OPTION_KEY); ?>[date_to]" value="<?php echo esc_attr($options['date_to']); ?>" />
                        </td>
                    </tr>
                </table>
                <?php submit_button('Salva impostazioni'); ?>
            </form>

            <hr />

            <h2>Azioni manuali</h2>
            <p>Usa questi pulsanti per verificare subito che la generazione funzioni correttamente.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:12px;">
                <?php wp_nonce_field(self::NONCE_ACTION, '_wpnonce_adfnews_pdf'); ?>
                <input type="hidden" name="action" value="adfnews_pdf_manual_regen" />
                <?php submit_button('Rigenera ora', 'secondary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php wp_nonce_field(self::NONCE_ACTION, '_wpnonce_adfnews_pdf'); ?>
                <input type="hidden" name="action" value="adfnews_pdf_test_generation" />
                <?php submit_button('Crea PDF di test', 'primary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    public static function handleManualRegeneration(): void
    {
        self::authorizeAdminAction();
        $result = self::generatePdfFromSettings(false);
        self::redirectWithMessage($result);
    }

    public static function handleTestGeneration(): void
    {
        self::authorizeAdminAction();
        $result = self::generatePdfFromSettings(true);
        self::redirectWithMessage($result);
    }

    public static function maybeRunCronGeneration(): void
    {
        $options = wp_parse_args(get_option(self::OPTION_KEY, []), self::defaultOptions());
        $timezone = new DateTimeZone($options['timezone']);
        $now = new DateTimeImmutable('now', $timezone);

        $weekday = strtolower($now->format('l'));
        $hour = intval($now->format('G'));

        if ($weekday !== $options['regen_weekday'] || $hour !== intval($options['regen_hour'])) {
            return;
        }

        self::generatePdfFromSettings(false);
    }

    private static function generatePdfFromSettings(bool $isTest): array
    {
        $options = wp_parse_args(get_option(self::OPTION_KEY, []), self::defaultOptions());

        $query_args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => intval($options['max_articles']),
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
        ];

        $include_ids = self::csvToIntArray($options['include_categories']);
        $exclude_ids = self::csvToIntArray($options['exclude_categories']);

        if (!empty($include_ids)) {
            $query_args['category__in'] = $include_ids;
        }
        if (!empty($exclude_ids)) {
            $query_args['category__not_in'] = $exclude_ids;
        }

        if ($isTest) {
            if (!empty($options['date_from'])) {
                $query_args['date_query'][] = [
                    'after' => str_replace('T', ' ', $options['date_from']),
                    'inclusive' => true,
                ];
            }
            if (!empty($options['date_to'])) {
                $query_args['date_query'][] = [
                    'before' => str_replace('T', ' ', $options['date_to']),
                    'inclusive' => true,
                ];
            }
        }

        $posts = get_posts($query_args);
        if (empty($posts)) {
            return ['ok' => false, 'message' => 'Nessun articolo trovato con i filtri selezionati.'];
        }

        $pdf = self::createDompdfDocument($posts, $options, $isTest);
        if ($pdf === '') {
            return ['ok' => false, 'message' => 'Dompdf non disponibile. Carica il plugin con la cartella vendor/.'];
        }
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return ['ok' => false, 'message' => 'Errore upload directory: ' . $upload['error']];
        }

        $filename = 'adfnews-export-' . ($isTest ? 'test-' : '') . gmdate('Ymd-His') . '.pdf';
        $target_path = trailingslashit($upload['basedir']) . $filename;
        $target_url = trailingslashit($upload['baseurl']) . $filename;

        $written = file_put_contents($target_path, $pdf);
        if ($written === false) {
            return ['ok' => false, 'message' => 'Errore durante il salvataggio del PDF.'];
        }

        return [
            'ok' => true,
            'message' => 'PDF creato correttamente.',
            'url' => esc_url_raw($target_url),
        ];
    }

    private static function createDompdfDocument(array $posts, array $options, bool $isTest): string
    {
        self::loadDompdfAutoloader();

        if (!class_exists('\Dompdf\Dompdf')) {
            return '';
        }

        $html = self::buildPdfHtml($posts, $options, $isTest);
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'defaultPaperSize' => 'a4',
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    private static function loadDompdfAutoloader(): void
    {
        $candidates = [
            __DIR__ . '/vendor/autoload.php',
            dirname(__DIR__) . '/vendor/autoload.php',
        ];

        foreach ($candidates as $autoload) {
            if (file_exists($autoload)) {
                require_once $autoload;
                return;
            }
        }
    }

    private static function buildPdfHtml(array $posts, array $options, bool $isTest): string
    {
        ob_start();
        ?>
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8" />
            <style>
                @page { margin: 28px 24px; }
                body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; line-height: 1.55; }
                h1, h2, h3 { margin: 0 0 8px 0; }
                .cover { border-bottom: 1px solid #ddd; margin-bottom: 14px; padding-bottom: 12px; }
                .meta { color: #666; font-size: 11px; margin-bottom: 12px; }
                .article { page-break-before: always; }
                .article:first-of-type { page-break-before: auto; }
                .content, .content * {
                    word-wrap: break-word;
                    overflow-wrap: break-word;
                    white-space: normal !important;
                    max-width: 100% !important;
                }
                img { max-width: 100% !important; height: auto !important; display: block; margin: 8px 0 12px; }
                .source { margin-top: 10px; font-size: 10px; color: #666; }
            </style>
        </head>
        <body>
        <div class="cover">
            <h1>ADF News - Export PDF</h1>
            <div class="meta">
                Generato: <?php echo esc_html(current_time('mysql')); ?><br />
                Modalita: <?php echo esc_html($isTest ? 'TEST' : 'CRON/MANUALE'); ?><br />
                Articoli: <?php echo esc_html((string) count($posts)); ?>
            </div>
        </div>
        <?php foreach ($posts as $index => $post) : ?>
            <?php
            $title = get_the_title($post);
            $date = get_the_date('Y-m-d H:i', $post);
            $author = get_the_author_meta('display_name', (int) $post->post_author);
            $content = apply_filters('the_content', $post->post_content);
            $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', (string) $content);
            $content = preg_replace('#<iframe(.*?)>(.*?)</iframe>#is', '', (string) $content);
            ?>
            <article class="article">
                <h2><?php echo esc_html(($index + 1) . '. ' . $title); ?></h2>
                <div class="meta"><?php echo esc_html($date . ' - ' . $author); ?></div>
                <?php if (!empty($options['include_images'])) : ?>
                    <?php
                    $thumb_url = get_the_post_thumbnail_url($post, 'medium');
                    $image_src = $thumb_url ? self::imageUrlToDataUri($thumb_url) : '';
                    ?>
                    <?php if ($image_src !== '') : ?>
                        <img src="<?php echo esc_attr($image_src); ?>" alt="" />
                    <?php endif; ?>
                <?php endif; ?>
                <div class="content"><?php echo wp_kses_post($content); ?></div>
                <div class="source"><?php echo esc_html(get_permalink($post)); ?></div>
            </article>
        <?php endforeach; ?>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    private static function imageUrlToDataUri(string $url): string
    {
        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) {
            return '';
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return '';
        }

        $mime = wp_remote_retrieve_header($response, 'content-type');
        if (!is_string($mime) || $mime === '') {
            $image_info = @getimagesizefromstring($body);
            $mime = is_array($image_info) && !empty($image_info['mime']) ? $image_info['mime'] : 'image/jpeg';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($body);
    }

    private static function authorizeAdminAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permesso negato.');
        }
        check_admin_referer(self::NONCE_ACTION, '_wpnonce_adfnews_pdf');
    }

    private static function redirectWithMessage(array $result): void
    {
        $query = [
            'page' => 'adfnews-pdf-settings',
            'adfnews_pdf_status' => !empty($result['ok']) ? 'ok' : 'error',
            'adfnews_pdf_message' => rawurlencode((string) ($result['message'] ?? 'Operazione completata.')),
        ];
        if (!empty($result['url'])) {
            $query['adfnews_pdf_url'] = rawurlencode((string) $result['url']);
        }

        $redirect = add_query_arg($query, admin_url('options-general.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public static function renderAdminNotice(): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== 'adfnews-pdf-settings') {
            return;
        }
        if (!isset($_GET['adfnews_pdf_status'], $_GET['adfnews_pdf_message'])) {
            return;
        }

        $status = sanitize_key((string) $_GET['adfnews_pdf_status']);
        $message = sanitize_text_field(wp_unslash((string) $_GET['adfnews_pdf_message']));
        $class = $status === 'ok' ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html(rawurldecode($message)) . '</p>';
        if (!empty($_GET['adfnews_pdf_url'])) {
            $url = esc_url_raw(rawurldecode(wp_unslash((string) $_GET['adfnews_pdf_url'])));
            echo '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Apri PDF generato</a></p>';
        }
        echo '</div>';
    }

    private static function defaultOptions(): array
    {
        return [
            'regen_weekday' => 'monday',
            'regen_hour' => 6,
            'timezone' => wp_timezone_string() ?: 'Europe/Rome',
            'include_categories' => '',
            'exclude_categories' => '',
            'max_articles' => 20,
            'include_images' => 1,
            'date_from' => '',
            'date_to' => '',
        ];
    }

    private static function weekdayOptions(): array
    {
        return [
            'monday' => 'Lunedi',
            'tuesday' => 'Martedi',
            'wednesday' => 'Mercoledi',
            'thursday' => 'Giovedi',
            'friday' => 'Venerdi',
            'saturday' => 'Sabato',
            'sunday' => 'Domenica',
        ];
    }

    private static function csvToIntArray(string $csv): array
    {
        if (trim($csv) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $csv));
        $parts = array_filter($parts, static function ($value) {
            return $value !== '' && is_numeric($value);
        });

        return array_values(array_unique(array_map('intval', $parts)));
    }

    private static function sanitizeDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if (!$dt) {
            return '';
        }

        return $dt->format('Y-m-d\TH:i');
    }
}

ADFNews_Weekly_PDF_Plugin::boot();
register_activation_hook(__FILE__, ['ADFNews_Weekly_PDF_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['ADFNews_Weekly_PDF_Plugin', 'deactivate']);
