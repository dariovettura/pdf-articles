<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ADFNews_Weekly_PDF_Plugin {
	const OPTION_KEY              = 'adfnews_weekly_pdf_settings';
	const CRON_HOOK               = 'adfnews_generate_weekly_pdf';
	const CRON_SCHEDULE           = 'adfnews_weekly';
	const LOCK_TRANSIENT          = 'adfnews_weekly_pdf_lock';
	const ADMIN_NOTICE_TRANSIENT  = 'adfnews_weekly_pdf_admin_notice';
	const LOG_DIR                 = 'adfnews-weekly/logs';
	const PDF_DIR                 = 'adfnews-weekly';
	const DEFAULT_RETENTION_FILES = 12;

	/**
	 * Boot plugin.
	 */
	public static function boot() {
		$instance = new self();
		$instance->hooks();
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		$instance = new self();
		$instance->schedule_event();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Register runtime hooks.
	 */
	private function hooks() {
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
		add_action( 'init', array( $this, 'schedule_event' ) );
		add_action( self::CRON_HOOK, array( $this, 'generate_weekly_pdf' ) );

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		add_action( 'admin_post_adfnews_weekly_pdf_generate_now', array( $this, 'handle_generate_now' ) );

		add_shortcode( 'adfnews_weekly_pdf_link', array( $this, 'shortcode_weekly_pdf_link' ) );
		add_action( 'wp_body_open', array( $this, 'maybe_render_header_link' ) );
	}

	/**
	 * Add custom weekly cron cadence.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly (ADFNews PDF)', 'adfnews-weekly-pdf' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule recurring weekly event.
	 */
	public function schedule_event() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$settings = $this->get_settings();
		$hour     = (int) $settings['cron_hour'];
		$minute   = (int) $settings['cron_minute'];
		$weekday  = 1; // Monday.

		$timezone = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $timezone );
		$next     = $now->setTime( $hour, $minute );

		while ( (int) $next->format( 'N' ) !== $weekday || $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}

		wp_schedule_event( $next->getTimestamp(), self::CRON_SCHEDULE, self::CRON_HOOK );
	}

	/**
	 * Settings defaults.
	 *
	 * @return array
	 */
	public function get_settings() {
		$defaults = array(
			'cron_hour'        => 0,
			'cron_minute'      => 30,
			'order'            => 'DESC',
			'include_images'   => 1,
			'max_posts'        => 100,
			'retention_files'  => self::DEFAULT_RETENTION_FILES,
			'header_link_text' => 'PDF Settimanale',
			'auto_inject_link' => 0,
		);

		$settings = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );

		$settings['cron_hour']        = max( 0, min( 23, (int) $settings['cron_hour'] ) );
		$settings['cron_minute']      = max( 0, min( 59, (int) $settings['cron_minute'] ) );
		$settings['order']            = ( 'ASC' === strtoupper( (string) $settings['order'] ) ) ? 'ASC' : 'DESC';
		$settings['include_images']   = ! empty( $settings['include_images'] ) ? 1 : 0;
		$settings['max_posts']        = max( 1, min( 300, (int) $settings['max_posts'] ) );
		$settings['retention_files']  = max( 2, min( 104, (int) $settings['retention_files'] ) );
		$settings['header_link_text'] = sanitize_text_field( (string) $settings['header_link_text'] );
		$settings['auto_inject_link'] = ! empty( $settings['auto_inject_link'] ) ? 1 : 0;

		return $settings;
	}

	/**
	 * Register settings page.
	 */
	public function register_settings_page() {
		add_options_page(
			__( 'ADFNews Weekly PDF', 'adfnews-weekly-pdf' ),
			__( 'ADFNews Weekly PDF', 'adfnews-weekly-pdf' ),
			'manage_options',
			'adfnews-weekly-pdf',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings fields.
	 */
	public function register_settings() {
		register_setting(
			'adfnews_weekly_pdf_group',
			self::OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input array.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$settings = $this->get_settings();

		$settings['cron_hour']        = isset( $input['cron_hour'] ) ? max( 0, min( 23, (int) $input['cron_hour'] ) ) : $settings['cron_hour'];
		$settings['cron_minute']      = isset( $input['cron_minute'] ) ? max( 0, min( 59, (int) $input['cron_minute'] ) ) : $settings['cron_minute'];
		$settings['order']            = ( isset( $input['order'] ) && 'ASC' === strtoupper( (string) $input['order'] ) ) ? 'ASC' : 'DESC';
		$settings['include_images']   = ! empty( $input['include_images'] ) ? 1 : 0;
		$settings['max_posts']        = isset( $input['max_posts'] ) ? max( 1, min( 300, (int) $input['max_posts'] ) ) : $settings['max_posts'];
		$settings['retention_files']  = isset( $input['retention_files'] ) ? max( 2, min( 104, (int) $input['retention_files'] ) ) : $settings['retention_files'];
		$settings['header_link_text'] = isset( $input['header_link_text'] ) ? sanitize_text_field( (string) $input['header_link_text'] ) : $settings['header_link_text'];
		$settings['auto_inject_link'] = ! empty( $input['auto_inject_link'] ) ? 1 : 0;

		$this->reschedule_event();
		return $settings;
	}

	/**
	 * Force rescheduling with updated settings.
	 */
	private function reschedule_event() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		$this->schedule_event();
	}

	/**
	 * Render settings UI.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->get_settings();
		$pdf_url  = $this->get_current_pdf_url();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ADFNews Weekly PDF', 'adfnews-weekly-pdf' ); ?></h1>
			<p><?php esc_html_e( 'Generate a weekly PDF from all published posts in the previous Monday-to-Monday window.', 'adfnews-weekly-pdf' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'adfnews_weekly_pdf_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Cron hour (site timezone)', 'adfnews-weekly-pdf' ); ?></th>
						<td><input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cron_hour]" value="<?php echo esc_attr( $settings['cron_hour'] ); ?>" min="0" max="23" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cron minute', 'adfnews-weekly-pdf' ); ?></th>
						<td><input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cron_minute]" value="<?php echo esc_attr( $settings['cron_minute'] ); ?>" min="0" max="59" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Order', 'adfnews-weekly-pdf' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[order]">
								<option value="DESC" <?php selected( $settings['order'], 'DESC' ); ?>>DESC</option>
								<option value="ASC" <?php selected( $settings['order'], 'ASC' ); ?>>ASC</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Include featured images', 'adfnews-weekly-pdf' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[include_images]" value="1" <?php checked( (int) $settings['include_images'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'adfnews-weekly-pdf' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Maximum posts per week', 'adfnews-weekly-pdf' ); ?></th>
						<td><input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_posts]" value="<?php echo esc_attr( $settings['max_posts'] ); ?>" min="1" max="300" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Retention (generated PDFs)', 'adfnews-weekly-pdf' ); ?></th>
						<td><input type="number" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[retention_files]" value="<?php echo esc_attr( $settings['retention_files'] ); ?>" min="2" max="104" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Header link text', 'adfnews-weekly-pdf' ); ?></th>
						<td><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[header_link_text]" value="<?php echo esc_attr( $settings['header_link_text'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto inject in header hook', 'adfnews-weekly-pdf' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_inject_link]" value="1" <?php checked( (int) $settings['auto_inject_link'], 1 ); ?> /> <?php esc_html_e( 'Render link via wp_body_open hook', 'adfnews-weekly-pdf' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Manual generation', 'adfnews-weekly-pdf' ); ?></h2>
			<p>
				<?php if ( $pdf_url ) : ?>
					<a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open current PDF', 'adfnews-weekly-pdf' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'No PDF generated yet.', 'adfnews-weekly-pdf' ); ?>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adfnews_weekly_pdf_generate_now" />
				<?php wp_nonce_field( 'adfnews_weekly_pdf_generate_now' ); ?>
				<?php submit_button( __( 'Generate now', 'adfnews-weekly-pdf' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle manual generation.
	 */
	public function handle_generate_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'adfnews-weekly-pdf' ) );
		}
		check_admin_referer( 'adfnews_weekly_pdf_generate_now' );

		$result = $this->generate_weekly_pdf( true );
		if ( is_wp_error( $result ) ) {
			$this->set_admin_notice( 'error', $result->get_error_message() );
		} else {
			$this->set_admin_notice( 'success', __( 'Weekly PDF generated successfully.', 'adfnews-weekly-pdf' ) );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=adfnews-weekly-pdf' ) );
		exit;
	}

	/**
	 * Store admin notice for redirect-safe display.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 */
	private function set_admin_notice( $type, $message ) {
		set_transient(
			self::ADMIN_NOTICE_TRANSIENT,
			array(
				'type'    => $type,
				'message' => (string) $message,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Render admin notices.
	 */
	public function render_admin_notices() {
		$notice = get_transient( self::ADMIN_NOTICE_TRANSIENT );
		if ( empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( self::ADMIN_NOTICE_TRANSIENT );
		$class = ( 'error' === $notice['type'] ) ? 'notice notice-error' : 'notice notice-success';
		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Cron callback and manual entrypoint.
	 *
	 * @param bool $manual True when manually triggered.
	 * @return true|WP_Error
	 */
	public function generate_weekly_pdf( $manual = false ) {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return new WP_Error( 'adfnews_weekly_locked', __( 'PDF generation already running.', 'adfnews-weekly-pdf' ) );
		}

		set_transient( self::LOCK_TRANSIENT, 1, MINUTE_IN_SECONDS * 20 );

		try {
			$settings = $this->get_settings();
			$range    = $this->get_previous_week_range();
			$posts    = $this->collect_posts_for_range( $range['start'], $range['end'], $settings );
			$html     = $this->build_pdf_html( $posts, $range, $settings );
			$file     = $this->render_pdf_and_store( $html, $range );

			$this->cleanup_old_files( (int) $settings['retention_files'] );
			$this->log_event(
				'info',
				sprintf(
					'PDF generated (%s). posts=%d manual=%s',
					$file['filename'],
					count( $posts ),
					$manual ? 'yes' : 'no'
				)
			);
		} catch ( Exception $e ) {
			$this->log_event( 'error', 'Generation failed: ' . $e->getMessage() );
			delete_transient( self::LOCK_TRANSIENT );
			return new WP_Error( 'adfnews_weekly_generation_failed', $e->getMessage() );
		}

		delete_transient( self::LOCK_TRANSIENT );
		return true;
	}

	/**
	 * Previous Monday-to-Monday date window.
	 *
	 * @return array
	 */
	private function get_previous_week_range() {
		$tz         = wp_timezone();
		$now        = new DateTimeImmutable( 'now', $tz );
		$thisMonday = $now->modify( 'monday this week' )->setTime( 0, 0, 0 );
		$start      = $thisMonday->modify( '-7 days' );
		$end        = $thisMonday;

		return array(
			'start'     => $start,
			'end'       => $end,
			'label'     => sprintf( '%s - %s', $start->format( 'd/m/Y' ), $end->modify( '-1 day' )->format( 'd/m/Y' ) ),
			'year_week' => $start->format( 'o-\WW' ),
		);
	}

	/**
	 * Query published posts in date range.
	 *
	 * @param DateTimeImmutable $start Start date.
	 * @param DateTimeImmutable $end End date.
	 * @param array             $settings Plugin settings.
	 * @return array
	 */
	private function collect_posts_for_range( DateTimeImmutable $start, DateTimeImmutable $end, $settings ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => (int) $settings['max_posts'],
				'orderby'                => 'date',
				'order'                  => $settings['order'],
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'date_query'             => array(
					array(
						'inclusive' => false,
						'after'     => $start->format( 'Y-m-d H:i:s' ),
						'before'    => $end->format( 'Y-m-d H:i:s' ),
						'column'    => 'post_date',
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Build HTML used by PDF renderer.
	 *
	 * @param array $posts Post objects.
	 * @param array $range Week range metadata.
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function build_pdf_html( $posts, $range, $settings ) {
		ob_start();
		?>
		<!doctype html>
		<html>
		<head>
			<meta charset="utf-8">
			<style>
				body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; line-height: 1.5; }
				h1, h2, h3 { margin: 0 0 8px; }
				.cover { text-align: center; border-bottom: 1px solid #ddd; padding-bottom: 20px; margin-bottom: 20px; }
				.meta { font-size: 11px; color: #666; margin-bottom: 10px; }
				.index { margin: 20px 0 30px; }
				.post { page-break-before: always; }
				.post img { max-width: 100%; height: auto; margin: 8px 0 12px; }
				.source { font-size: 11px; color: #666; margin-top: 10px; }
			</style>
		</head>
		<body>
			<div class="cover">
				<h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
				<h2><?php esc_html_e( 'Raccolta settimanale articoli', 'adfnews-weekly-pdf' ); ?></h2>
				<p><?php echo esc_html( $range['label'] ); ?></p>
				<p><?php echo esc_html( sprintf( __( 'Totale articoli: %d', 'adfnews-weekly-pdf' ), count( $posts ) ) ); ?></p>
			</div>

			<div class="index">
				<h3><?php esc_html_e( 'Indice', 'adfnews-weekly-pdf' ); ?></h3>
				<ol>
					<?php foreach ( $posts as $post ) : ?>
						<li><?php echo esc_html( get_the_title( $post ) ); ?></li>
					<?php endforeach; ?>
				</ol>
			</div>

			<?php if ( empty( $posts ) ) : ?>
				<p><?php esc_html_e( 'Nessun articolo pubblicato nel periodo selezionato.', 'adfnews-weekly-pdf' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $posts as $post ) : ?>
				<?php
				$content = apply_filters( 'the_content', $post->post_content );
				$content = preg_replace( '#<script(.*?)>(.*?)</script>#is', '', (string) $content );
				$content = preg_replace( '#<iframe(.*?)>(.*?)</iframe>#is', '', (string) $content );
				?>
				<article class="post">
					<h2><?php echo esc_html( get_the_title( $post ) ); ?></h2>
					<div class="meta">
						<?php
						echo esc_html(
							sprintf(
								'%s - %s',
								get_the_author_meta( 'display_name', (int) $post->post_author ),
								wp_date( 'd/m/Y H:i', strtotime( $post->post_date ) )
							)
						);
						?>
					</div>
					<?php if ( ! empty( $settings['include_images'] ) && has_post_thumbnail( $post ) ) : ?>
						<?php $image = get_the_post_thumbnail_url( $post, 'large' ); ?>
						<?php if ( $image ) : ?>
							<img src="<?php echo esc_url( $image ); ?>" alt="" />
						<?php endif; ?>
					<?php endif; ?>
					<div class="content"><?php echo wp_kses_post( $content ); ?></div>
					<p class="source"><?php echo esc_html( get_permalink( $post ) ); ?></p>
				</article>
			<?php endforeach; ?>
		</body>
		</html>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Convert HTML to PDF and save both versioned and stable files.
	 *
	 * @param string $html HTML document.
	 * @param array  $range Week metadata.
	 * @return array
	 * @throws Exception When renderer is unavailable or writing fails.
	 */
	private function render_pdf_and_store( $html, $range ) {
		if ( file_exists( ADFNEWS_WEEKLY_PDF_DIR . 'vendor/autoload.php' ) ) {
			require_once ADFNEWS_WEEKLY_PDF_DIR . 'vendor/autoload.php';
		}

		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			throw new Exception( __( 'Dompdf library not found. Install it in plugin vendor directory.', 'adfnews-weekly-pdf' ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			throw new Exception( $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::PDF_DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new Exception( __( 'Unable to create PDF destination directory.', 'adfnews-weekly-pdf' ) );
		}

		$filename = sprintf( 'adfnews-weekly-%s.pdf', str_replace( '\\', '', $range['year_week'] ) );
		$current  = 'current.pdf';

		$dompdf = new \Dompdf\Dompdf(
			array(
				'isRemoteEnabled' => true,
				'isHtml5ParserEnabled' => true,
				'defaultPaperSize' => 'a4',
			)
		);
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		$pdf_content = $dompdf->output();

		$versioned_path = trailingslashit( $dir ) . $filename;
		$current_path   = trailingslashit( $dir ) . $current;

		if ( false === file_put_contents( $versioned_path, $pdf_content ) ) {
			throw new Exception( __( 'Unable to write versioned PDF file.', 'adfnews-weekly-pdf' ) );
		}

		// Update current.pdf only after successful versioned file write.
		if ( false === file_put_contents( $current_path, $pdf_content ) ) {
			throw new Exception( __( 'Unable to update stable current.pdf file.', 'adfnews-weekly-pdf' ) );
		}

		return array(
			'filename' => $filename,
			'path'     => $versioned_path,
			'url'      => trailingslashit( $uploads['baseurl'] ) . self::PDF_DIR . '/' . $filename,
		);
	}

	/**
	 * Keep only latest N versioned files.
	 *
	 * @param int $retention_files Number of files to retain.
	 */
	private function cleanup_old_files( $retention_files ) {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::PDF_DIR;
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( trailingslashit( $dir ) . 'adfnews-weekly-*.pdf' );
		if ( empty( $files ) ) {
			return;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);

		$stale = array_slice( $files, $retention_files );
		foreach ( $stale as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Append line to log file.
	 *
	 * @param string $level Log level.
	 * @param string $message Log message.
	 */
	private function log_event( $level, $message ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::LOG_DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$line = sprintf(
			"[%s] [%s] %s\n",
			wp_date( DATE_ATOM ),
			strtoupper( $level ),
			(string) $message
		);
		@file_put_contents( trailingslashit( $dir ) . 'weekly-pdf.log', $line, FILE_APPEND );
	}

	/**
	 * Get stable weekly PDF URL.
	 *
	 * @return string
	 */
	public function get_current_pdf_url() {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . self::PDF_DIR . '/current.pdf';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		return trailingslashit( $uploads['baseurl'] ) . self::PDF_DIR . '/current.pdf';
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public function shortcode_weekly_pdf_link() {
		$settings = $this->get_settings();
		$url      = $this->get_current_pdf_url();
		if ( ! $url ) {
			return '';
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( $url ),
			esc_html( $settings['header_link_text'] )
		);
	}

	/**
	 * Render link through wp_body_open when enabled.
	 */
	public function maybe_render_header_link() {
		$settings = $this->get_settings();
		if ( empty( $settings['auto_inject_link'] ) ) {
			return;
		}

		$link = $this->shortcode_weekly_pdf_link();
		if ( ! $link ) {
			return;
		}

		echo '<div class="adfnews-weekly-pdf-link" style="padding:8px 16px;background:#f7f7f7;text-align:right;">' . $link . '</div>';
	}
}

if ( ! function_exists( 'adfnews_weekly_pdf_url' ) ) {
	/**
	 * Public helper function for themes/templates.
	 *
	 * @return string
	 */
	function adfnews_weekly_pdf_url() {
		$plugin = new ADFNews_Weekly_PDF_Plugin();
		return $plugin->get_current_pdf_url();
	}
}

if ( ! function_exists( 'adfnews_weekly_pdf_link' ) ) {
	/**
	 * Public helper function for themes/templates.
	 *
	 * @return string
	 */
	function adfnews_weekly_pdf_link() {
		$plugin = new ADFNews_Weekly_PDF_Plugin();
		return $plugin->shortcode_weekly_pdf_link();
	}
}
