<?php
/**
 * Upload conversion.
 *
 * @package SB_WebP_Upload_Optimizer
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts uploads through WordPress image generation and can walk the existing library.
 */
class SB_WebP_Upload_Optimizer_Converter {

	private const BULK_SKIP = 'sb_webp_upload_optimizer_bulk_skip';

	/**
	 * While true, this request must not rewrite the original. The block editor
	 * uploads the source first and then builds WebP sizes in the browser. Rewriting
	 * the original before that response would hide the target format from the editor.
	 */
	private bool $suppress_output_format = false;

	private SB_WebP_Upload_Optimizer_Settings $settings;

	public function __construct( SB_WebP_Upload_Optimizer_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'wp_image_editors', array( $this, 'filter_image_editors' ) );
		add_filter( 'image_editor_output_format', array( $this, 'filter_output_format' ), 10, 3 );
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_quality' ), 10, 3 );
		add_filter( 'big_image_size_threshold', array( $this, 'filter_threshold' ), 10, 4 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'filter_metadata' ), 20, 3 );
		add_filter( 'rest_prepare_attachment', array( $this, 'filter_rest_attachment' ), 10, 3 );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'sb-webp-upload-optimizer',
			false,
			dirname( plugin_basename( SB_WEBP_UPLOAD_OPTIMIZER_FILE ) ) . '/languages'
		);
	}

	public function supports_webp(): bool {
		return (bool) wp_image_editor_supports(
			array(
				'mime_type' => 'image/webp',
			)
		);
	}

	/**
	 * Swaps in a GD editor that can encode indexed-color images as WebP.
	 *
	 * WordPress includes the core editor classes before this filter runs.
	 *
	 * @param mixed $editors Editor class names.
	 * @return array<int, string>
	 */
	public function filter_image_editors( $editors ) {
		if ( ! is_array( $editors ) || ! class_exists( 'WP_Image_Editor_GD', false ) ) {
			return is_array( $editors ) ? $editors : array();
		}

		require_once __DIR__ . '/class-editor-gd.php';

		foreach ( $editors as $index => $editor ) {
			if ( 'WP_Image_Editor_GD' === $editor ) {
				$editors[ $index ] = 'SB_WebP_Upload_Optimizer_Editor_GD';
			}
		}

		return $editors;
	}

	/**
	 * Maps selected source types to WebP for the image editor and the block editor.
	 *
	 * @param mixed  $formats   Mime map from WordPress.
	 * @param string $filename  File being saved.
	 * @param string $mime_type Source mime type.
	 * @return array<string, string>
	 */
	public function filter_output_format( $formats, $filename = '', $mime_type = '' ) {
		if ( ! is_array( $formats ) ) {
			$formats = array();
		}

		if ( $this->suppress_output_format || ! $this->can_convert() ) {
			return $formats;
		}

		$filename  = is_string( $filename ) ? $filename : '';
		$mime_type = is_string( $mime_type ) ? $mime_type : '';

		if ( ! $this->allow_file( $filename, $mime_type ) ) {
			return $formats;
		}

		$sources = $this->mimes_for_file( $filename, $mime_type );
		foreach ( $sources as $source ) {
			$formats[ $source ] = 'image/webp';
		}

		return $formats;
	}

	/**
	 * @param mixed  $quality   Proposed quality.
	 * @param string $mime_type Output mime type.
	 * @param mixed  $size      Image dimensions. Unused.
	 * @return int
	 */
	public function filter_quality( $quality, $mime_type = '', $size = array() ) {
		unset( $size );

		if ( 'image/webp' !== $mime_type || ! $this->settings->enabled() ) {
			return (int) $quality;
		}

		return $this->settings->quality();
	}

	/**
	 * @param mixed    $threshold     Current threshold, or false when scaling is disabled.
	 * @param mixed    $imagesize     Width and height. Unused.
	 * @param mixed    $file          File path. Unused.
	 * @param mixed    $attachment_id Attachment ID. Unused.
	 * @return mixed
	 */
	public function filter_threshold( $threshold, $imagesize = array(), $file = '', $attachment_id = 0 ) {
		unset( $imagesize, $file, $attachment_id );

		if ( $this->request_skips_server_subsizes() ) {
			$this->suppress_output_format = true;
		}

		if ( ! $this->can_convert() ) {
			return $threshold;
		}

		$max = $this->settings->max_dimension();
		if ( $max < 1 ) {
			return $threshold;
		}

		return $max;
	}

	/**
	 * Keeps the attachment mime type in sync and converts originals WordPress left untouched.
	 *
	 * @param mixed  $metadata      Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context       "create" or "update".
	 * @return array<string, mixed>
	 */
	public function filter_metadata( $metadata, $attachment_id, $context = 'create' ) {
		$attachment_id = (int) $attachment_id;
		$deferred      = $this->suppress_output_format;
		$this->suppress_output_format = false;

		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		if ( ! $this->can_convert() ) {
			return $metadata;
		}

		$skip_until_client_finishes = ( 'create' === $context && $deferred );
		if ( ! $skip_until_client_finishes && $this->needs_conversion( $attachment_id ) ) {
			$metadata = $this->convert_full_size( $attachment_id, $metadata );
		}

		$this->sync_mime_type( $attachment_id );

		if ( ! $deferred && $this->settings->delete_original() ) {
			$metadata = $this->delete_original_file( $attachment_id, $metadata );
		}

		return $metadata;
	}

	/**
	 * The upload response can still carry the pre-conversion MIME type.
	 *
	 * @param WP_REST_Response $response Attachment response.
	 * @param WP_Post          $post     Attachment post.
	 * @param WP_REST_Request  $request  REST request.
	 * @return WP_REST_Response
	 */
	public function filter_rest_attachment( $response, $post, $request ) {
		unset( $request );

		if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post ) {
			return $response;
		}

		if ( ! is_string( $post->post_mime_type ) || 0 !== strpos( $post->post_mime_type, 'image/' ) ) {
			return $response;
		}

		if ( 'image/webp' !== $this->file_mime( (int) $post->ID ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$changed = false;
		if ( isset( $data['mime_type'] ) && 'image/webp' !== $data['mime_type'] ) {
			$data['mime_type'] = 'image/webp';
			$changed            = true;
		}

		if ( array_key_exists( 'image_output_format', $data ) && ! empty( $data['image_output_format'] ) ) {
			$data['image_output_format'] = null;
			$changed                     = true;
		}

		if ( $changed ) {
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Converts the next library image that is still a JPEG, PNG, or HEIC.
	 *
	 * @return array<string, mixed>
	 */
	public function convert_next(): array {
		$this->require_image_api();
		wp_raise_memory_limit( 'image' );

		$skip = $this->skipped_ids();
		$ids  = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $this->settings->source_mimes(),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'post__not_in'   => $skip,
				'no_found_rows'  => true,
			)
		);

		if ( empty( $ids ) ) {
			$failed = count( $skip );
			delete_transient( self::BULK_SKIP );

			if ( $failed > 0 ) {
				$message = sprintf(
					/* translators: %d: number of images that stayed in their original format. */
					__( 'Finished. Some images could not be converted (%d). Run the tool again to retry.', 'sb-webp-upload-optimizer' ),
					$failed
				);
			} else {
				$message = __( 'Finished. There are no JPEG, PNG, or HEIC images left to convert.', 'sb-webp-upload-optimizer' );
			}

			return array(
				'done'      => true,
				'remaining' => 0,
				'message'   => $message,
			);
		}

		$attachment_id = (int) $ids[0];
		$file          = get_attached_file( $attachment_id );
		$mime          = $this->file_mime( $attachment_id );

		if ( ! is_string( $file ) || ! $this->allow_file( $file, $mime ) ) {
			$this->remember_skip( $skip, $attachment_id );

			return array(
				'done'      => false,
				'remaining' => $this->count_pending( $this->skipped_ids() ),
				'message'   => sprintf(
					/* translators: 1: attachment ID, 2: number of images still waiting. */
					__( 'Skipped image #%1$d. %2$d still waiting.', 'sb-webp-upload-optimizer' ),
					$attachment_id,
					$this->count_pending( $this->skipped_ids() )
				),
			);
		}

		$old_metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $old_metadata ) ) {
			$old_metadata = array();
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );

		if ( 'image/webp' !== $this->file_mime( $attachment_id ) ) {
			$this->remember_skip( $skip, $attachment_id );
			$remaining = $this->count_pending( $this->skipped_ids() );

			return array(
				'done'      => false,
				'remaining' => $remaining,
				'message'   => sprintf(
					/* translators: 1: attachment ID, 2: number of images still waiting. */
					__( 'Could not convert image #%1$d. %2$d still waiting.', 'sb-webp-upload-optimizer' ),
					$attachment_id,
					$remaining
				),
			);
		}

		$this->delete_replaced_derivatives( $file, $old_metadata, $metadata );
		$remaining = $this->count_pending( $skip );

		return array(
			'done'      => false,
			'remaining' => $remaining,
			'message'   => sprintf(
				/* translators: 1: attachment ID, 2: number of images still waiting. */
				__( 'Converted image #%1$d. %2$d still waiting.', 'sb-webp-upload-optimizer' ),
				$attachment_id,
				$remaining
			),
		);
	}

	public function count_pending( ?array $skip = null ): int {
		if ( null === $skip ) {
			$skip = $this->skipped_ids();
		}

		$counts = wp_count_attachments();
		$total  = 0;

		foreach ( $this->settings->source_mimes() as $mime ) {
			if ( isset( $counts->$mime ) ) {
				$total += (int) $counts->$mime;
			}
		}

		return max( 0, $total - count( $skip ) );
	}

	private function can_convert(): bool {
		return $this->settings->enabled() && $this->supports_webp() && ! empty( $this->settings->source_mimes() );
	}

	/**
	 * The block editor sends this while it generates sizes itself.
	 */
	private function request_skips_server_subsizes(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || ! isset( $_REQUEST['generate_sub_sizes'] ) ) {
			return false;
		}

		return ! rest_sanitize_boolean( wp_unslash( $_REQUEST['generate_sub_sizes'] ) );
	}

	/**
	 * @return string[]
	 */
	private function mimes_for_file( string $filename, string $mime_type ): array {
		$sources = $this->settings->source_mimes();

		/**
		 * Filters which MIME types are written as WebP.
		 *
		 * @param string[] $sources   Mime types such as image/jpeg.
		 * @param string   $filename  File being saved.
		 * @param string   $mime_type Mime type WordPress passed to the editor.
		 */
		$filtered = apply_filters( 'sb_webp_upload_optimizer_mime_types', $sources, $filename, $mime_type );

		if ( ! is_array( $filtered ) ) {
			return $sources;
		}

		$clean = array();
		foreach ( $filtered as $mime ) {
			if ( is_string( $mime ) && '' !== $mime && 'image/webp' !== $mime ) {
				$clean[] = $mime;
			}
		}

		return $clean;
	}

	private function allow_file( string $filename, string $mime_type ): bool {
		if ( '' === $filename || ! has_filter( 'sb_webp_upload_optimizer_should_convert' ) ) {
			return true;
		}

		$attachment_id = $this->attachment_id_for_file( $filename );

		/**
		 * Short-circuits conversion for one file.
		 *
		 * @param bool   $convert       Whether to convert.
		 * @param int    $attachment_id Attachment ID, or 0 when it is not known yet.
		 * @param string $filename      Absolute path.
		 * @param string $mime_type     Source mime type.
		 */
		return (bool) apply_filters( 'sb_webp_upload_optimizer_should_convert', true, $attachment_id, $filename, $mime_type );
	}

	private function attachment_id_for_file( string $filename ): int {
		global $wpdb;

		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( (string) $uploads['basedir'] );
		$path    = wp_normalize_path( $filename );

		if ( strpos( $path, trailingslashit( $base ) ) !== 0 ) {
			return 0;
		}

		$relative = ltrim( substr( $path, strlen( $base ) ), '/' );
		$found    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative
			)
		);

		return (int) $found;
	}

	private function needs_conversion( int $attachment_id ): bool {
		return in_array( $this->file_mime( $attachment_id ), $this->settings->source_mimes(), true );
	}

	private function file_mime( int $attachment_id ): string {
		$file = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || ! is_file( $file ) ) {
			return '';
		}

		$checked = wp_check_filetype( $file );

		return isset( $checked['type'] ) ? (string) $checked['type'] : '';
	}

	/**
	 * Writes a WebP next to a source file WordPress did not already replace.
	 *
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return array<string, mixed>
	 */
	private function convert_full_size( int $attachment_id, array $metadata ): array {
		$file = get_attached_file( $attachment_id );
		$mime = $this->file_mime( $attachment_id );

		if ( ! is_string( $file ) || ! is_file( $file ) || ! $this->allow_file( $file, $mime ) ) {
			return $metadata;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $metadata;
		}

		$editor->set_quality( $this->settings->quality() );
		$saved = $editor->save( $editor->generate_filename( '' ), 'image/webp' );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_string( $saved['path'] ) ) {
			return $metadata;
		}

		$new_path = $saved['path'];
		if ( wp_normalize_path( $new_path ) === wp_normalize_path( $file ) ) {
			return $metadata;
		}

		update_attached_file( $attachment_id, $new_path );

		$metadata['width']    = isset( $saved['width'] ) ? (int) $saved['width'] : 0;
		$metadata['height']   = isset( $saved['height'] ) ? (int) $saved['height'] : 0;
		$metadata['file']     = _wp_relative_upload_path( $new_path );
		$metadata['filesize'] = isset( $saved['filesize'] ) ? (int) $saved['filesize'] : (int) wp_filesize( $new_path );

		if ( empty( $metadata['original_image'] ) ) {
			$metadata['original_image'] = wp_basename( $file );
		}

		return $metadata;
	}

	/**
	 * Updates the attachment row without a full post save.
	 *
	 * A normal post update from inside metadata generation can run save hooks
	 * that start another image pass.
	 */
	private function sync_mime_type( int $attachment_id ): void {
		global $wpdb;

		$actual = $this->file_mime( $attachment_id );
		if ( '' === $actual || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$current = (string) get_post_mime_type( $attachment_id );
		if ( $actual === $current ) {
			return;
		}

		$wpdb->update(
			$wpdb->posts,
			array( 'post_mime_type' => $actual ),
			array( 'ID' => $attachment_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $attachment_id );
		wp_cache_delete( 'attachments', 'counts' );
	}

	/**
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return array<string, mixed>
	 */
	private function delete_original_file( int $attachment_id, array $metadata ): array {
		if ( empty( $metadata['original_image'] ) || ! is_string( $metadata['original_image'] ) ) {
			return $metadata;
		}

		$attached = get_attached_file( $attachment_id );
		if ( ! is_string( $attached ) || ! is_file( $attached ) ) {
			return $metadata;
		}

		$original = path_join( dirname( $attached ), wp_basename( $metadata['original_image'] ) );
		if ( ! $this->is_deletable_upload( $original, $attached ) ) {
			return $metadata;
		}

		wp_delete_file( $original );
		unset( $metadata['original_image'] );

		return $metadata;
	}

	/**
	 * Removes previous JPEG/PNG derivatives after a library item has been regenerated.
	 *
	 * @param string               $old_file     Previous attached file.
	 * @param array<string, mixed> $old_metadata Metadata from before regeneration.
	 * @param array<string, mixed> $new_metadata Metadata returned by regeneration.
	 */
	private function delete_replaced_derivatives( string $old_file, array $old_metadata, array $new_metadata ): void {
		$directory = dirname( $old_file );
		$keep      = array( wp_basename( $old_file ) );

		if ( ! empty( $new_metadata['file'] ) && is_string( $new_metadata['file'] ) ) {
			$keep[] = wp_basename( $new_metadata['file'] );
		}
		if ( ! empty( $new_metadata['original_image'] ) && is_string( $new_metadata['original_image'] ) ) {
			$keep[] = wp_basename( $new_metadata['original_image'] );
		}

		foreach ( $this->size_basenames( $new_metadata ) as $name ) {
			$keep[] = $name;
		}

		$keep = array_unique( $keep );

		foreach ( $this->size_basenames( $old_metadata ) as $name ) {
			if ( in_array( $name, $keep, true ) ) {
				continue;
			}

			$path = path_join( $directory, $name );
			if ( $this->is_deletable_upload( $path, '' ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return string[]
	 */
	private function size_basenames( array $metadata ): array {
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return array();
		}

		$names = array();
		foreach ( $metadata['sizes'] as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$names[] = wp_basename( $size['file'] );
			}
		}

		return $names;
	}

	private function is_deletable_upload( string $path, string $protected ): bool {
		if ( ! is_file( $path ) ) {
			return false;
		}

		$real = realpath( $path );
		if ( false === $real ) {
			return false;
		}

		$real = wp_normalize_path( $real );
		if ( '' !== $protected && $real === wp_normalize_path( $protected ) ) {
			return false;
		}

		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( (string) $uploads['basedir'] );

		return $real === $base || strpos( $real, trailingslashit( $base ) ) === 0;
	}

	/**
	 * @return int[]
	 */
	private function skipped_ids(): array {
		$skip = get_transient( self::BULK_SKIP );
		if ( ! is_array( $skip ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $skip ) ) );
	}

	/**
	 * @param int[] $skip          IDs already set aside.
	 * @param int   $attachment_id ID that failed or was skipped.
	 */
	private function remember_skip( array $skip, int $attachment_id ): void {
		$skip[] = $attachment_id;
		set_transient( self::BULK_SKIP, array_values( array_unique( $skip ) ), HOUR_IN_SECONDS );
	}

	private function require_image_api(): void {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}
