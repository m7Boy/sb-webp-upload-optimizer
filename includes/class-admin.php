<?php
/**
 * Settings screen.
 *
 * @package SB_WebP_Upload_Optimizer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page and the library conversion control.
 */
class SB_WebP_Upload_Optimizer_Admin {

	private SB_WebP_Upload_Optimizer_Settings $settings;

	private SB_WebP_Upload_Optimizer_Converter $converter;

	private string $hook_suffix = '';

	public function __construct( SB_WebP_Upload_Optimizer_Settings $settings, SB_WebP_Upload_Optimizer_Converter $converter ) {
		$this->settings  = $settings;
		$this->converter = $converter;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notice_missing_support' ) );
		add_action( 'wp_ajax_sb_webp_upload_optimizer_bulk_step', array( $this, 'ajax_bulk_step' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SB_WEBP_UPLOAD_OPTIMIZER_FILE ), array( $this, 'action_links' ) );
	}

	public function add_page(): void {
		$this->hook_suffix = (string) add_options_page(
			__( 'SB WebP Upload Optimizer', 'sb-webp-upload-optimizer' ),
			__( 'SB WebP Upload Optimizer', 'sb-webp-upload-optimizer' ),
			'manage_options',
			'sb-webp-upload-optimizer',
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'sb_webp_upload_optimizer',
			SB_WebP_Upload_Optimizer_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => $this->settings->defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'sb-webp-upload-optimizer-admin',
			plugins_url( 'assets/admin.js', SB_WEBP_UPLOAD_OPTIMIZER_FILE ),
			array(),
			SB_WEBP_UPLOAD_OPTIMIZER_VERSION,
			true
		);

		wp_localize_script(
			'sb-webp-upload-optimizer-admin',
			'sb_webp_upload_optimizer',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'sb_webp_upload_optimizer_bulk' ),
				'working'      => __( 'Working…', 'sb-webp-upload-optimizer' ),
				'errorMessage' => __( 'The conversion stopped because the server returned an error.', 'sb-webp-upload-optimizer' ),
			)
		);
	}

	public function notice_missing_support(): void {
		if ( ! current_user_can( 'manage_options' ) || $this->converter->supports_webp() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', $this->hook_suffix ), true ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'SB WebP Upload Optimizer cannot run: this server cannot write WebP images. Enable WebP in GD or install Imagick.', 'sb-webp-upload-optimizer' );
		echo '</p></div>';
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=sb-webp-upload-optimizer' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'sb-webp-upload-optimizer' ) . '</a>'
		);

		$links[] = '<a href="' . esc_url( SB_WEBP_UPLOAD_OPTIMIZER_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Software Brewery', 'sb-webp-upload-optimizer' ) . '</a>';

		return $links;
	}

	public function ajax_bulk_step(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to convert images.', 'sb-webp-upload-optimizer' ),
				),
				403
			);
		}

		check_ajax_referer( 'sb_webp_upload_optimizer_bulk', 'nonce' );

		if ( ! $this->settings->enabled() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Conversion is turned off.', 'sb-webp-upload-optimizer' ),
				)
			);
		}

		if ( ! $this->converter->supports_webp() ) {
			wp_send_json_error(
				array(
					'message' => __( 'This server cannot write WebP images.', 'sb-webp-upload-optimizer' ),
				)
			);
		}

		wp_send_json_success( $this->converter->convert_next() );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings->all();
		$option   = SB_WebP_Upload_Optimizer_Settings::OPTION;
		$waiting  = $this->converter->count_pending();
		$ready    = $this->settings->enabled() && $this->converter->supports_webp();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'SB WebP Upload Optimizer', 'sb-webp-upload-optimizer' ); ?></h1>
			<p><?php echo esc_html__( 'New JPEG, PNG, and HEIC uploads are stored as WebP. WordPress still creates the registered image sizes, and those sizes are WebP too.', 'sb-webp-upload-optimizer' ); ?></p>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Software Brewery website link. */
						__( 'Developed by %s.', 'sb-webp-upload-optimizer' ),
						'<a href="' . esc_url( SB_WEBP_UPLOAD_OPTIMIZER_AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">Software Brewery</a>'
					),
					array(
						'a' => array(
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
					)
				);
				?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'sb_webp_upload_optimizer' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Conversion', 'sb-webp-upload-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
								<?php echo esc_html__( 'Convert new uploads to WebP.', 'sb-webp-upload-optimizer' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sb-webp-upload-optimizer-quality"><?php echo esc_html__( 'WebP quality', 'sb-webp-upload-optimizer' ); ?></label>
						</th>
						<td>
							<input
								id="sb-webp-upload-optimizer-quality"
								name="<?php echo esc_attr( $option ); ?>[quality]"
								type="number"
								min="1"
								max="100"
								step="1"
								value="<?php echo esc_attr( (string) $settings['quality'] ); ?>"
								class="small-text"
							/>
							<p class="description"><?php echo esc_html__( '1 is the smallest file and 100 is the highest quality. 82 suits photographs.', 'sb-webp-upload-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sb-webp-upload-optimizer-max"><?php echo esc_html__( 'Maximum edge', 'sb-webp-upload-optimizer' ); ?></label>
						</th>
						<td>
							<input
								id="sb-webp-upload-optimizer-max"
								name="<?php echo esc_attr( $option ); ?>[max_dimension]"
								type="number"
								min="0"
								max="12000"
								step="1"
								value="<?php echo esc_attr( (string) $settings['max_dimension'] ); ?>"
								class="small-text"
							/>
							<p class="description"><?php echo esc_html__( 'Longest side of the full-size image, in pixels. 0 keeps the WordPress default of 2560.', 'sb-webp-upload-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Formats', 'sb-webp-upload-optimizer' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[convert_jpeg]" value="1" <?php checked( ! empty( $settings['convert_jpeg'] ) ); ?> />
									<?php echo esc_html__( 'JPEG', 'sb-webp-upload-optimizer' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[convert_png]" value="1" <?php checked( ! empty( $settings['convert_png'] ) ); ?> />
									<?php echo esc_html__( 'PNG', 'sb-webp-upload-optimizer' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[convert_heic]" value="1" <?php checked( ! empty( $settings['convert_heic'] ) ); ?> />
									<?php echo esc_html__( 'HEIC / HEIF', 'sb-webp-upload-optimizer' ); ?>
								</label>
								<p class="description"><?php echo esc_html__( 'HEIC files can be converted on the server when Imagick is installed. In the block editor the browser converts them first.', 'sb-webp-upload-optimizer' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Source files', 'sb-webp-upload-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[delete_original]" value="1" <?php checked( ! empty( $settings['delete_original'] ) ); ?> />
								<?php echo esc_html__( 'Delete the source file after the WebP copy is saved.', 'sb-webp-upload-optimizer' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'Leave this off to keep the source. WordPress can use it if the image sizes are regenerated later.', 'sb-webp-upload-optimizer' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save changes', 'sb-webp-upload-optimizer' ) ); ?>
			</form>

			<hr />
			<h2><?php echo esc_html__( 'Existing media library', 'sb-webp-upload-optimizer' ); ?></h2>
			<p><?php echo esc_html__( 'Convert images that are already in the library. Each image is processed on its own, so you can leave this page open until a large library finishes.', 'sb-webp-upload-optimizer' ); ?></p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of images that are not WebP yet. */
						__( 'Images waiting: %d', 'sb-webp-upload-optimizer' ),
						$waiting
					)
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-secondary" id="sb-webp-upload-optimizer-bulk" <?php disabled( ! $ready || $waiting < 1 ); ?>>
					<?php echo esc_html__( 'Convert existing images', 'sb-webp-upload-optimizer' ); ?>
				</button>
			</p>
			<p id="sb-webp-upload-optimizer-status" role="status"></p>
		</div>
		<?php
	}
}
