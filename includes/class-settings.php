<?php
/**
 * Stored options for SB WebP Upload Optimizer.
 *
 * @package SB_WebP_Upload_Optimizer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes the plugin option.
 */
class SB_WebP_Upload_Optimizer_Settings {

	public const OPTION = 'sb_webp_upload_optimizer_settings';

	/**
	 * Default values used when an option has never been saved.
	 *
	 * @return array<string, bool|int>
	 */
	public function defaults(): array {
		return array(
			'enabled'         => true,
			'quality'         => 82,
			'max_dimension'   => 2560,
			'convert_jpeg'    => true,
			'convert_png'     => true,
			'convert_heic'    => true,
			'delete_original' => false,
		);
	}

	/**
	 * Merged settings.
	 *
	 * @return array<string, bool|int>
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( $this->defaults(), $saved );
	}

	public function enabled(): bool {
		return ! empty( $this->all()['enabled'] );
	}

	public function quality(): int {
		$quality = (int) $this->all()['quality'];

		/**
		 * Filters the WebP encode quality.
		 *
		 * @param int $quality Quality from 1 to 100.
		 */
		$quality = (int) apply_filters( 'sb_webp_upload_optimizer_quality', $quality );

		if ( $quality < 1 || $quality > 100 ) {
			return 82;
		}

		return $quality;
	}

	/**
	 * Longest side for the full-size image. 0 keeps the WordPress default.
	 */
	public function max_dimension(): int {
		$max = (int) $this->all()['max_dimension'];

		/**
		 * Filters the maximum full-size edge in pixels.
		 *
		 * Return 0 to leave WordPress's own threshold untouched.
		 *
		 * @param int $max Maximum width and height in pixels.
		 */
		$max = (int) apply_filters( 'sb_webp_upload_optimizer_max_dimension', $max );

		if ( $max < 0 ) {
			return 0;
		}

		return min( 12000, $max );
	}

	public function delete_original(): bool {
		return ! empty( $this->all()['delete_original'] );
	}

	/**
	 * MIME types selected for conversion.
	 *
	 * @return string[]
	 */
	public function source_mimes(): array {
		$settings = $this->all();
		$mimes    = array();

		if ( ! empty( $settings['convert_jpeg'] ) ) {
			$mimes[] = 'image/jpeg';
		}

		if ( ! empty( $settings['convert_png'] ) ) {
			$mimes[] = 'image/png';
		}

		if ( ! empty( $settings['convert_heic'] ) ) {
			$mimes[] = 'image/heic';
			$mimes[] = 'image/heif';
		}

		return $mimes;
	}

	/**
	 * Sanitizes the Settings API payload.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string, bool|int>
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$quality = isset( $input['quality'] ) ? absint( $input['quality'] ) : 82;
		if ( $quality < 1 || $quality > 100 ) {
			$quality = 82;
		}

		return array(
			'enabled'         => ! empty( $input['enabled'] ),
			'quality'         => $quality,
			'max_dimension'   => min( 12000, isset( $input['max_dimension'] ) ? absint( $input['max_dimension'] ) : 2560 ),
			'convert_jpeg'    => ! empty( $input['convert_jpeg'] ),
			'convert_png'     => ! empty( $input['convert_png'] ),
			'convert_heic'    => ! empty( $input['convert_heic'] ),
			'delete_original' => ! empty( $input['delete_original'] ),
		);
	}
}
