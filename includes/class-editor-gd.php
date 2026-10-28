<?php
/**
 * GD editor that can encode indexed images as WebP.
 *
 * @package SB_WebP_Upload_Optimizer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GD refuses to write a palette image as WebP or AVIF.
 *
 * Indexed PNGs are common after PNG optimizers. WordPress calls imagewebp()
 * from WP_Image_Editor::make_image(), which is where that failure is reported.
 * Promoting the in-memory image to truecolor first lets the same save succeed
 * and keeps the alpha channel.
 */
class SB_WebP_Upload_Optimizer_Editor_GD extends WP_Image_Editor_GD {

	/**
	 * @param resource|GdImage $image
	 * @param string|null      $filename
	 * @param string|null      $mime_type
	 * @return array|WP_Error
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) {
		$image = $this->prepare_modern_output( $image, $filename, $mime_type );

		return parent::_save( $image, $filename, $mime_type );
	}

	/**
	 * @param string|null $mime_type
	 * @return bool
	 */
	public function stream( $mime_type = null ) {
		$prepared = $this->prepare_modern_output( $this->image, null, $mime_type );

		if ( $prepared !== $this->image ) {
			$this->replace_image( $prepared );
		}

		return parent::stream( $mime_type );
	}

	/**
	 * @param resource|GdImage|false $image
	 * @param string|null            $filename
	 * @param string|null            $mime_type
	 * @return resource|GdImage|false
	 */
	private function prepare_modern_output( $image, $filename, $mime_type ) {
		if ( ! $this->is_palette_image( $image ) ) {
			return $image;
		}

		$format = $this->get_output_format(
			is_string( $filename ) ? $filename : null,
			is_string( $mime_type ) ? $mime_type : null
		);
		$output = ( isset( $format[2] ) && is_string( $format[2] ) ) ? $format[2] : '';

		if ( 'image/webp' !== $output && 'image/avif' !== $output ) {
			return $image;
		}

		return $this->to_truecolor( $image );
	}

	/**
	 * @param resource|GdImage|false $image
	 */
	private function is_palette_image( $image ): bool {
		return is_gd_image( $image )
			&& function_exists( 'imageistruecolor' )
			&& ! imageistruecolor( $image );
	}

	/**
	 * @param resource|GdImage $image
	 * @return resource|GdImage
	 */
	private function to_truecolor( $image ) {
		if ( function_exists( 'imagepalettetotruecolor' ) && imagepalettetotruecolor( $image ) ) {
			$this->preserve_alpha( $image );

			return $image;
		}

		$width  = imagesx( $image );
		$height = imagesy( $image );

		if ( $width < 1 || $height < 1 ) {
			return $image;
		}

		$true = wp_imagecreatetruecolor( $width, $height );

		if ( ! is_gd_image( $true ) ) {
			return $image;
		}

		imagecopy( $true, $image, 0, 0, 0, 0, $width, $height );
		$this->preserve_alpha( $true );

		return $true;
	}

	/**
	 * @param resource|GdImage $image
	 */
	private function preserve_alpha( $image ): void {
		if ( function_exists( 'imagealphablending' ) && function_exists( 'imagesavealpha' ) ) {
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
		}
	}

	/**
	 * @param resource|GdImage $image
	 */
	private function replace_image( $image ): void {
		if ( PHP_VERSION_ID < 80000 && is_gd_image( $this->image ) ) {
			imagedestroy( $this->image );
		}

		$this->image = $image;
	}
}
