<?php
/**
 * Plugin Name: FirePunch | Instant AVIF & WebP images for Advanced Custom Fields developers
 * Description: Automatic and asynchronous AVIF & WebP image optimization for custom themes and Advanced Custom Fields.
 * Version:     2.0
 * Author:      Matteo Bruschetti
 * Author URI:  https://matteobruschetti.it
 * License:     GPLv2 or later
 * Text Domain: firepunch-images-for-acf
 * Requires PHP: 8.1
 *
 * === System Requirements & Dependencies ===
 * To ensure correct functionality, the server and the theme must meet the following requirements:
 *
 * 1. SERVER & PHP:
 * - PHP version 8.1 or higher (required for native AVIF support in the GD library).
 * - GD Extension (v2.3.0+) or ImageMagick with both AVIF and WebP support enabled in the graphics module.
 *
 * 2. THEME DEVELOPMENT (ACF / Custom Theme):
 * - The theme must retrieve images using the Attachment ID.
 * - If used with ACF (Advanced Custom Fields), the image field return format must be set to "Image ID".
 * - Template images must be rendered using either the global plugin function `wp_avif_img($id)` or the native `wp_get_attachment_image($id)`.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MB_AVIF_WebP_Optimizer
 *
 * Handles backend image generation, frontend HTML rewriting, metadata manipulation,
 * and attachment deletion cleanup for AVIF and WebP variants.
 */
class MB_AVIF_WebP_Optimizer {

	/**
	 * Instance of this class.
	 *
	 * @var MB_AVIF_WebP_Optimizer|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return MB_AVIF_WebP_Optimizer
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers hooks.
	 */
	private function __construct() {
		// Backend hook for variant generation when attachment metadata is generated
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_variants_on_upload' ), 10, 2 );

		// Asynchronous background generation hook
		add_action( 'mb_avif_webp_generate_variants', array( $this, 'process_attachment_variants' ) );

		// Frontend hooks for rewriting <img> to <picture>
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image_html' ), 10, 5 );

		// Metadata hook for dynamic "ghost file" inclusion
		add_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10, 2 );

		// Cleanup hook when attachment is deleted
		add_action( 'delete_attachment', array( $this, 'cleanup_variants_on_delete' ) );

		// Diagnostic page hook
		add_action( 'admin_menu', array( $this, 'add_diagnostic_page' ) );

		// Quick link to settings on the plugins page
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Adds the "Settings" link to the WordPress plugins page.
	 *
	 * @param array $links Array of existing action links.
	 * @return array Updated array of links.
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=firepunch-images-for-acf' ) ) . '">' . esc_html__( 'Settings', 'firepunch-images-for-acf' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Backend Hook: Generates AVIF and WebP files when image metadata is generated.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function generate_variants_on_upload( $metadata, $attachment_id ) {
		// Verify this is an image attachment by checking file presence.
		if ( empty( $metadata['file'] ) ) {
			return $metadata;
		}

		// Scheduliamo l'elaborazione in background
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler (WooCommerce e altri) gestisce le code in background in modo sequenziale
			as_enqueue_async_action( 'mb_avif_webp_generate_variants', array( (int) $attachment_id ) );
		} else {
			// WP-Cron fallback con distanziamento degli eventi (offset) per evitare congestioni/DoS
			$last_scheduled = get_transient( 'mb_avif_webp_last_scheduled' );
			$now            = time();

			if ( false === $last_scheduled || $last_scheduled < $now ) {
				$schedule_time = $now;
			} else {
				$schedule_time = $last_scheduled + 10; // Spazia gli eventi di 10 secondi l'uno dall'altro
			}

			set_transient( 'mb_avif_webp_last_scheduled', $schedule_time, HOUR_IN_SECONDS );
			wp_schedule_single_event( $schedule_time, 'mb_avif_webp_generate_variants', array( (int) $attachment_id ) );
		}

		return $metadata;
	}

	/**
	 * Background task: elabora in modo asincrono l'allegato per generare le varianti.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function process_attachment_variants( $attachment_id ) {
		// Rimuoviamo temporaneamente il nostro filtro sui metadati per evitare letture anticipate o interferenze
		remove_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10 );

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $metadata ) || empty( $metadata['file'] ) ) {
			add_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10, 2 );
			return;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			add_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10, 2 );
			return;
		}

		$base_dir = $uploads['basedir'] . '/' . dirname( $metadata['file'] );
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		// 1. Process original file
		$original_file = $uploads['basedir'] . '/' . $metadata['file'];
		$this->generate_variants_for_file( $original_file );

		// Aggiorna le chiavi del file originale nei metadati
		$metadata['webp_file'] = ( file_exists( $original_file . '.webp' ) && filesize( $original_file . '.webp' ) > 0 ) ? $metadata['file'] . '.webp' : '';
		$metadata['avif_file'] = ( file_exists( $original_file . '.avif' ) && filesize( $original_file . '.avif' ) > 0 ) ? $metadata['file'] . '.avif' : '';

		// 2. Process all generated sizes (thumbnails, custom sizes, etc.)
		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_key => $size ) {
				if ( ! empty( $size['file'] ) ) {
					$size_file = $base_dir . $size['file'];
					$this->generate_variants_for_file( $size_file );

					// Aggiorna le chiavi della size nei metadati
					$metadata['sizes'][ $size_key ]['webp_file'] = ( file_exists( $size_file . '.webp' ) && filesize( $size_file . '.webp' ) > 0 ) ? $size['file'] . '.webp' : '';
					$metadata['sizes'][ $size_key ]['avif_file'] = ( file_exists( $size_file . '.avif' ) && filesize( $size_file . '.avif' ) > 0 ) ? $size['file'] . '.avif' : '';
				}
			}
		}

		// Salviamo i metadati aggiornati direttamente nel database
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Ripristiniamo il filtro
		add_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10, 2 );
	}

	/**
	 * Generates WebP and AVIF variants for a specific filepath.
	 *
	 * @param string $filepath Absolute file path to the source image.
	 * @return void
	 */
	private function generate_variants_for_file( $filepath ) {
		if ( ! file_exists( $filepath ) ) {
			return;
		}

		$mime_type = wp_get_image_mime( $filepath );
		if ( ! $mime_type ) {
			return;
		}

		// Early Exit: Se le varianti esistono già, non inizializziamo alcun editor per risparmiare CPU/RAM
		$webp_path = $filepath . '.webp';
		$avif_path = $filepath . '.avif';
		
		$needs_webp = ( 'image/webp' !== $mime_type && ( ! file_exists( $webp_path ) || filesize( $webp_path ) === 0 ) );
		$needs_avif = ( 'image/avif' !== $mime_type && ( ! file_exists( $avif_path ) || filesize( $avif_path ) === 0 ) );

		if ( ! $needs_webp && ! $needs_avif ) {
			return;
		}

		// Editor primario (WordPress sceglierà Imagick se disponibile, ottimo per WebP/PNG)
		$editor = wp_get_image_editor( $filepath );
		
		if ( is_wp_error( $editor ) ) {
			return;
		}

		$supports_webp = $editor->supports_mime_type( 'image/webp' );
		$supports_avif = $editor->supports_mime_type( 'image/avif' );

		// WebP generation con Editor Primario (es. Imagick)
		if ( $needs_webp && $supports_webp ) {
			$quality = apply_filters( 'wp_editor_set_quality', 82, 'image/webp' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$editor->set_quality( $quality );
			$editor->save( $webp_path, 'image/webp' );
			
			// Autopulizia
			if ( file_exists( $webp_path ) && filesize( $webp_path ) === 0 ) {
				wp_delete_file( $webp_path );
			}
		}

		// AVIF generation con Editor Primario (se lo supporta nativamente)
		if ( $needs_avif && $supports_avif ) {
			$quality = apply_filters( 'wp_editor_set_quality', 82, 'image/avif' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$editor->set_quality( $quality );
			$editor->save( $avif_path, 'image/avif' );
			
			// Autopulizia
			if ( file_exists( $avif_path ) && filesize( $avif_path ) === 0 ) {
				wp_delete_file( $avif_path );
			}
		}

		// MOTORE IBRIDO: Se l'editor primario (es. Imagick) NON supporta AVIF, passiamo la palla a GD
		if ( $needs_avif && ! $supports_avif ) {
			if ( ! class_exists( 'WP_Image_Editor_GD' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
				require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
			}

			// Se GD è disponibile sul server e ha il modulo AVIF abilitato
			if ( WP_Image_Editor_GD::test() && WP_Image_Editor_GD::supports_mime_type( 'image/avif' ) ) {
				
				// Alziamo temporaneamente il limite di memoria prima di passare l'immagine a GD,
				// prevenendo crash di memoria se GD incontra PNG molto grandi.
				wp_raise_memory_limit( 'image' );

				$gd_editor = new WP_Image_Editor_GD( $filepath );
				if ( ! is_wp_error( $gd_editor->load() ) ) {
					$quality = apply_filters( 'wp_editor_set_quality', 82, 'image/avif' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					$gd_editor->set_quality( $quality );
					
					// GD salverà l'AVIF per JPG e PNG normali. 
					// Se è una PNG indicizzata e GD fallisce, fallirà in silenzio restituendo un WP_Error
					// ma il sito non andrà in crash e il WebP (già salvato da Imagick) funzionerà.
					$gd_editor->save( $avif_path, 'image/avif' );
					
					// Autopulizia in caso di fallimento silenzioso di GD (file a 0 byte)
					if ( file_exists( $avif_path ) && filesize( $avif_path ) === 0 ) {
						wp_delete_file( $avif_path );
					}
				}
			}
		}
	}

	/**
	 * Metadata Hook: Dynamically enrich metadata with ghost files info on-the-fly.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function filter_attachment_metadata( $metadata, $attachment_id ) {
		if ( empty( $metadata ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}

		// Se le chiavi sono già impostate (anche se stringhe vuote), evitiamo qualsiasi controllo sul File System.
		if ( isset( $metadata['webp_file'] ) && isset( $metadata['avif_file'] ) ) {
			return $metadata;
		}

		// Altrimenti, si tratta di una vecchia immagine o non ancora migrata. Eseguiamo il controllo sul disco UNA SOLA VOLTA.
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return $metadata;
		}

		$base_dir = $uploads['basedir'] . '/' . dirname( $metadata['file'] );
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		// Controlli per il file originale
		$original_file = $uploads['basedir'] . '/' . $metadata['file'];
		if ( ! isset( $metadata['webp_file'] ) ) {
			$metadata['webp_file'] = ( file_exists( $original_file . '.webp' ) && filesize( $original_file . '.webp' ) > 0 ) ? $metadata['file'] . '.webp' : '';
		}
		if ( ! isset( $metadata['avif_file'] ) ) {
			$metadata['avif_file'] = ( file_exists( $original_file . '.avif' ) && filesize( $original_file . '.avif' ) > 0 ) ? $metadata['file'] . '.avif' : '';
		}

		// Controlli per i tagli intermedi (sizes)
		if ( ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_key => $size_data ) {
				if ( ! empty( $size_data['file'] ) ) {
					$size_filepath = $base_dir . $size_data['file'];
					if ( ! isset( $metadata['sizes'][ $size_key ]['webp_file'] ) ) {
						$metadata['sizes'][ $size_key ]['webp_file'] = ( file_exists( $size_filepath . '.webp' ) && filesize( $size_filepath . '.webp' ) > 0 ) ? $size_data['file'] . '.webp' : '';
					}
					if ( ! isset( $metadata['sizes'][ $size_key ]['avif_file'] ) ) {
						$metadata['sizes'][ $size_key ]['avif_file'] = ( file_exists( $size_filepath . '.avif' ) && filesize( $size_filepath . '.avif' ) > 0 ) ? $size_data['file'] . '.avif' : '';
					}
				}
			}
		}

		// Salviamo i metadati aggiornati nel database per metterli in cache permanentemente ed eliminare futuri I/O.
		// Rimuoviamo temporaneamente il filtro prima di salvare per prevenire la ricorsione infinita.
		remove_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10 );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		add_filter( 'wp_get_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10, 2 );

		return $metadata;
	}

	/**
	 * Frontend Hook: Filter attachment <img> tag and wrap with <picture>.
	 *
	 * @param string       $html          The image HTML.
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|array $size          Requested image size.
	 * @param bool         $icon          Whether the attachment is an icon.
	 * @param array        $attr          Image attributes.
	 * @return string
	 */
	public function filter_attachment_image_html( $html, $attachment_id, $size, $icon, $attr ) {
		return $this->transform_img_to_picture( $html, $attachment_id );
	}

	/**
	 * Helper function to transform <img> string to <picture> string with <source> elements.
	 *
	 * @param string $html          Original HTML.
	 * @param int    $attachment_id Optional attachment ID.
	 * @return string
	 */
	public function transform_img_to_picture( $html, $attachment_id = null ) {
		// Avoid double wrapping if already a picture
		if ( empty( $html ) || false !== strpos( $html, '<picture' ) ) {
			return $html;
		}

		// Usa WP_HTML_Tag_Processor se disponibile (WP 6.2+), altrimenti fallback a regex
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $html );
			if ( ! $processor->next_tag( 'img' ) ) {
				return $html;
			}
			$src    = $processor->get_attribute( 'src' );
			$srcset = $processor->get_attribute( 'srcset' ) ?: '';
			$sizes  = $processor->get_attribute( 'sizes' ) ?: '';
		} else {
			// Match the <img> tag and its attributes
			if ( ! preg_match( '/<img\s+([^>]+)>/i', $html, $matches ) ) {
				return $html;
			}

			$attrs_str = $matches[1];
			$attrs     = $this->parse_attributes( $attrs_str );

			$src    = isset( $attrs['src'] ) ? $attrs['src'] : '';
			$srcset = isset( $attrs['srcset'] ) ? $attrs['srcset'] : '';
			$sizes  = isset( $attrs['sizes'] ) ? $attrs['sizes'] : '';
		}

		if ( empty( $src ) ) {
			return $html;
		}

		// If no attachment ID was passed, try to look it up
		if ( ! $attachment_id ) {
			$attachment_id = attachment_url_to_postid( $src );
		}

		if ( ! $attachment_id ) {
			return $html;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $metadata ) || empty( $metadata['file'] ) ) {
			return $html;
		}

		$has_avif = ! empty( $metadata['avif_file'] );
		$has_webp = ! empty( $metadata['webp_file'] );

		// If neither variant exists, return original HTML
		if ( ! $has_avif && ! $has_webp ) {
			return $html;
		}

		$avif_srcset_candidates = array();
		$webp_srcset_candidates = array();

		if ( ! empty( $srcset ) ) {
			$candidates = $this->parse_srcset( $srcset );
			foreach ( $candidates as $candidate ) {
				$candidate_basename = rawurldecode( wp_basename( $candidate['url'] ) );
				
				// Check if this candidate is the original image or one of the sizes
				$is_original = ( wp_basename( $metadata['file'] ) === $candidate_basename );
				
				$candidate_has_avif = false;
				$candidate_has_webp = false;

				if ( $is_original ) {
					$candidate_has_avif = $has_avif;
					$candidate_has_webp = $has_webp;
				} elseif ( ! empty( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size_data ) {
						if ( ! empty( $size_data['file'] ) && $size_data['file'] === $candidate_basename ) {
							$candidate_has_avif = ! empty( $size_data['avif_file'] );
							$candidate_has_webp = ! empty( $size_data['webp_file'] );
							break;
						}
					}
				}

				$descriptor = ! empty( $candidate['descriptor'] ) ? ' ' . $candidate['descriptor'] : '';
				if ( $has_avif && $candidate_has_avif ) {
					$avif_srcset_candidates[] = $candidate['url'] . '.avif' . $descriptor;
				}
				if ( $has_webp && $candidate_has_webp ) {
					$webp_srcset_candidates[] = $candidate['url'] . '.webp' . $descriptor;
				}
			}
		} else {
			// No srcset on original img, fall back to single file URLs
			if ( $has_avif ) {
				$avif_srcset_candidates[] = $src . '.avif';
			}
			if ( $has_webp ) {
				$webp_srcset_candidates[] = $src . '.webp';
			}
		}

		$avif_srcset_str = implode( ', ', $avif_srcset_candidates );
		$webp_srcset_str = implode( ', ', $webp_srcset_candidates );

		$picture_html = "<picture>\n";

		// AVIF source element
		if ( $has_avif && ! empty( $avif_srcset_str ) ) {
			$sizes_attr = ! empty( $sizes ) ? ' sizes="' . esc_attr( $sizes ) . '"' : '';
			$picture_html .= '  <source srcset="' . esc_attr( $avif_srcset_str ) . '"' . $sizes_attr . ' type="image/avif">' . "\n";
		}

		// WebP source element
		if ( $has_webp && ! empty( $webp_srcset_str ) ) {
			$sizes_attr = ! empty( $sizes ) ? ' sizes="' . esc_attr( $sizes ) . '"' : '';
			$picture_html .= '  <source srcset="' . esc_attr( $webp_srcset_str ) . '"' . $sizes_attr . ' type="image/webp">' . "\n";
		}

		// Fallback original img
		$picture_html .= '  ' . $html . "\n";
		$picture_html .= '</picture>';

		return $picture_html;
	}

	/**
	 * Parse attributes from HTML attributes string.
	 *
	 * @param string $attrs_str Attributes string from regex match.
	 * @return array
	 */
	private function parse_attributes( $attrs_str ) {
		$attrs = array();
		// Matches name="value", name='value' or name=value attributes
		preg_match_all( '/([a-zA-Z0-9\-]+)\s*=\s*(?:([\'"])(.*?)\2|([^\s\'"]+))/i', $attrs_str, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$name = strtolower( $match[1] );
			$value = isset( $match[3] ) && '' !== $match[3] ? $match[3] : ( isset( $match[4] ) ? $match[4] : '' );
			$attrs[ $name ] = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		}
		return $attrs;
	}

	/**
	 * Parse srcset attribute string into structured array.
	 *
	 * @param string $srcset_str The raw srcset value.
	 * @return array
	 */
	private function parse_srcset( $srcset_str ) {
		$candidates = array();
		$parts      = explode( ',', $srcset_str );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}
			$subparts = preg_split( '/\s+/', $part );
			if ( ! empty( $subparts[0] ) ) {
				$candidates[] = array(
					'url'        => $subparts[0],
					'descriptor' => isset( $subparts[1] ) ? $subparts[1] : '',
				);
			}
		}
		return $candidates;
	}



	/**
	 * Backend Hook: Cleanup generated variants when attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function cleanup_variants_on_delete( $attachment_id ) {
		// Use original attachment metadata (to get sizes) and path
		$metadata      = wp_get_attachment_metadata( $attachment_id );
		$original_file = get_attached_file( $attachment_id );

		if ( $original_file ) {
			$this->delete_file_variants( $original_file );
		}

		if ( ! empty( $metadata['sizes'] ) && $original_file ) {
			$base_dir = dirname( $original_file );
			$base_dir = rtrim( $base_dir, '/\\' ) . '/';

			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$size_file = $base_dir . $size['file'];
					$this->delete_file_variants( $size_file );
				}
			}
		}
	}

	/**
	 * Unlinks WebP and AVIF variants for a single filepath.
	 *
	 * @param string $filepath The absolute filepath.
	 * @return void
	 */
	private function delete_file_variants( $filepath ) {
		// Protezione contro Path Traversal
		$uploads  = wp_upload_dir();
		$base_dir = realpath( $uploads['basedir'] );
		$file_dir = realpath( dirname( $filepath ) );

		if ( false === $base_dir || false === $file_dir ) {
			return;
		}

		// Verifichiamo rigorosamente che il file da eliminare si trovi nella directory di upload di WordPress
		if ( strpos( $file_dir, $base_dir ) !== 0 ) {
			return;
		}

		$webp_file = $filepath . '.webp';
		$avif_file = $filepath . '.avif';

		if ( file_exists( $webp_file ) ) {
			wp_delete_file( $webp_file );
		}
		if ( file_exists( $avif_file ) ) {
			wp_delete_file( $avif_file );
		}
	}

	/**
	 * Add a diagnostic page under the Settings menu.
	 */
	public function add_diagnostic_page() {
		add_options_page(
			__( 'Diagnostica FirePunch', 'firepunch-images-for-acf' ),
			__( 'FirePunch', 'firepunch-images-for-acf' ),
			'manage_options',
			'firepunch-images-for-acf',
			array( $this, 'render_diagnostic_page' )
		);
	}

	/**
	 * Render the diagnostic page content.
	 */
	public function render_diagnostic_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 1. PHP Version
		$php_version = phpversion();
		$php_ok      = version_compare( $php_version, '8.1', '>=' );
		$icon_php    = $php_ok ? '✅' : '❌';

		// 2. ImageMagick
		$imagick_active = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$icon_imagick   = $imagick_active ? '✅' : '❌';

		$imagick_webp = false;
		$imagick_avif = false;

		if ( $imagick_active ) {
			try {
				$formats      = Imagick::queryFormats();
				$imagick_webp = in_array( 'WEBP', $formats, true );
				$imagick_avif = in_array( 'AVIF', $formats, true );
			} catch ( Exception $e ) {
				// Silently fail if queryFormats throws an error
			}
		}

		$icon_im_webp = $imagick_active ? ( $imagick_webp ? '✅' : '❌' ) : '-';
		$icon_im_avif = $imagick_active ? ( $imagick_avif ? '✅' : '❌' ) : '-';

		// 3. GD Library
		$gd_active = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$icon_gd   = $gd_active ? '✅' : '❌';

		$gd_webp = false;
		$gd_avif = false;

		if ( $gd_active ) {
			$gd_info = gd_info();
			$gd_webp = isset( $gd_info['WebP Support'] ) && $gd_info['WebP Support'];
			$gd_avif = isset( $gd_info['AVIF Support'] ) && $gd_info['AVIF Support'];
		}

		$icon_gd_webp = $gd_active ? ( $gd_webp ? '✅' : '❌' ) : '-';
		$icon_gd_avif = $gd_active ? ( $gd_avif ? '✅' : '❌' ) : '-';

		// 4. Hosting Provider Detection (Pure Static & WP-Compliant)
		$server_provider = __( 'Unknown / Custom', 'firepunch-images-for-acf' );
		$doc_root        = isset( $_SERVER['DOCUMENT_ROOT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) ) : '';
		$server_name     = isset( $_SERVER['SERVER_NAME'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) ) : '';
		$server_admin    = isset( $_SERVER['SERVER_ADMIN'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADMIN'] ) ) ) : '';
		$host_name       = function_exists( 'gethostname' ) ? strtolower( (string) gethostname() ) : '';

		// 1. Managed WordPress Specialized Platforms
		if ( isset( $_SERVER['IS_WPE'] ) || isset( $_SERVER['WPE_APIKEY'] ) ) {
			$server_provider = 'WP Engine';
		} elseif ( isset( $_SERVER['KINSTA_DEFAULT_HOST'] ) || strpos( $doc_root, 'kinsta' ) !== false ) {
			$server_provider = 'Kinsta';
		} elseif ( isset( $_SERVER['FLYWHEEL_APP_ID'] ) ) {
			$server_provider = 'Flywheel';
		} elseif ( strpos( $doc_root, 'sg-host.com' ) !== false || strpos( $doc_root, '/home/customer/www/' ) !== false ) {
			$server_provider = 'SiteGround';
		}

		// 2. Specific Signature Checks for Aruba, Serverplan, and Netsons
		elseif ( strpos( $doc_root, '/web/htdocs/' ) !== false || strpos( $host_name, 'aruba' ) !== false ) {
			// Aruba Shared Linux hosting strictly enforces the unique directory structure: /web/htdocs/www.domain.com/home
			$server_provider = 'Aruba';
		} elseif ( strpos( $doc_root, 'serverplan' ) !== false || strpos( $server_name, 'serverplan' ) !== false || strpos( $host_name, 'serverplan' ) !== false || strpos( $server_admin, 'serverplan' ) !== false ) {
			$server_provider = 'Serverplan';
		} elseif ( strpos( $doc_root, 'netsons' ) !== false || strpos( $host_name, 'netsons' ) !== false || strpos( $server_admin, 'netsons' ) !== false ) {
			$server_provider = 'Netsons';
		} elseif ( strpos( $doc_root, 'hostinger' ) !== false || strpos( $server_name, 'hostinger' ) !== false || strpos( $host_name, 'hostinger' ) !== false || strpos( $server_admin, 'hostinger' ) !== false ) {
			$server_provider = 'Hostinger';
		} elseif ( isset( $_SERVER['CLOUDWAYS'] ) || strpos( $doc_root, 'cloudways' ) !== false ) {
			$server_provider = 'Cloudways';
		}

		// 3. Generic Architecture Fallbacks (Plesk / cPanel Environments)
		elseif ( strpos( $doc_root, '/vhosts/' ) !== false || isset( $_SERVER['PP_USER'] ) ) {
			// Plesk environments universally map custom domains inside the '/var/www/vhosts/' system root
			$server_provider = 'Plesk Control Panel (Custom VPS / Dedicated)';
		} elseif ( strpos( $doc_root, '/public_html' ) !== false && strpos( $doc_root, '/home/' ) !== false ) {
			$server_provider = 'cPanel (Custom VPS / Dedicated)';
		}

		// Final Synthesis Status
		$supports_both      = ( $imagick_webp && $imagick_avif ) || ( $gd_webp && $gd_avif );
		$supports_webp_only = ( $imagick_webp || $gd_webp ) && ! $supports_both;

		if ( $supports_both ) {
			$summary_title = __( 'Advanced Optimization (Full)', 'firepunch-images-for-acf' );
			$summary_desc  = __( 'The server supports the generation of both next-gen formats WebP and AVIF from both JPG and PNG images.', 'firepunch-images-for-acf' );
			$summary_class = 'notice-success';
		} elseif ( $supports_webp_only ) {
			$summary_title = __( 'Partial Optimization', 'firepunch-images-for-acf' );
			$summary_desc  = __( 'The server only supports the generation of the WebP format from JPG and PNG images. AVIF variants will not be generated.', 'firepunch-images-for-acf' );
			$summary_class = 'notice-warning';
		} else {
			$summary_title = __( 'No Optimization (Fallback)', 'firepunch-images-for-acf' );
			$summary_desc  = __( 'The server does not support WebP or AVIF generation. The plugin will perform a safe fallback, returning the original JPG and PNG files without optimizations.', 'firepunch-images-for-acf' );
			$summary_class = 'notice-error';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '🔥 FirePunch Diagnostics', 'firepunch-images-for-acf' ); ?></h1>
			
			<div class="card" style="max-width: 1080px; margin-top: 24px; border-radius: 16px;">
				<h2 class="title" style="margin-top: 6px; margin-top: 8px;"><?php esc_html_e( 'ℹ️ System Requirements', 'firepunch-images-for-acf' ); ?></h2>
            <p style="margin-top: 0px; margin-bottom: 16px; color: #646970;">
               <?php esc_html_e( 'These are the your server details.', 'firepunch-images-for-acf' ); ?>
            </p>
				<table class="widefat striped" style="margin-top: 16px; margin-bottom: 8px; border-radius: 8px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Requirement', 'firepunch-images-for-acf' ); ?></th>
							<th><?php esc_html_e( 'Status', 'firepunch-images-for-acf' ); ?></th>
							<th><?php esc_html_e( 'Details', 'firepunch-images-for-acf' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Hosting Provider', 'firepunch-images-for-acf' ); ?></strong></td>
							<td>ℹ️</td>
							<td>
								<?php echo esc_html( $server_provider ); ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'PHP Version', 'firepunch-images-for-acf' ); ?></strong></td>
							<td><?php echo esc_html( $icon_php ); ?></td>
							<td>
								<?php
								/* translators: %s is the current PHP version */
								echo esc_html( sprintf( __( 'Current version: %s. Required: 8.1+', 'firepunch-images-for-acf' ), $php_version ) );
								?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'ImageMagick (Imagick)', 'firepunch-images-for-acf' ); ?></strong></td>
							<td><?php echo esc_html( $icon_imagick ); ?></td>
							<td>
								<?php if ( $imagick_active ) : ?>
									<?php esc_html_e( 'Active. See compatibility matrix below for formats.', 'firepunch-images-for-acf' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Extension not active or not installed.', 'firepunch-images-for-acf' ); ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'GD Library', 'firepunch-images-for-acf' ); ?></strong></td>
							<td><?php echo esc_html( $icon_gd ); ?></td>
							<td>
								<?php if ( $gd_active ) : ?>
									<?php esc_html_e( 'Active. See compatibility matrix below for formats.', 'firepunch-images-for-acf' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Extension not active or not installed.', 'firepunch-images-for-acf' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
         </div>

         <div class="card" style="max-width: 1080px; margin-top: 24px; border-radius: 16px;">
				<h2 class="title" style="margin-top: 6px; margin-bottom: 8px;"><?php esc_html_e( '🖼️ Compatible Conversions', 'firepunch-images-for-acf' ); ?></h3>
            <p style="margin-top: 0px; margin-bottom: 16px; color: #646970;">
               <?php esc_html_e( 'These are the active image processing libraries available on your server, enabling native and completely free image conversions.', 'firepunch-images-for-acf' ); ?>
            </p>
				<table class="widefat striped" style="margin-top: 16px; margin-bottom: 8px; border-radius: 8px; text-align: center;">
					<thead>
						<tr>
							<th style="text-align: left;"><?php esc_html_e( 'Image Library', 'firepunch-images-for-acf' ); ?></th>
							<th style="text-align: center;"><?php esc_html_e( 'JPG to WebP', 'firepunch-images-for-acf' ); ?></th>
							<th style="text-align: center;"><?php esc_html_e( 'JPG to AVIF', 'firepunch-images-for-acf' ); ?></th>
							<th style="text-align: center;"><?php esc_html_e( 'PNG to WebP', 'firepunch-images-for-acf' ); ?></th>
							<th style="text-align: center;"><?php esc_html_e( 'PNG to AVIF', 'firepunch-images-for-acf' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td style="text-align: left;"><strong><?php esc_html_e( 'GD Library', 'firepunch-images-for-acf' ); ?></strong></td>
							<td><?php echo esc_html( $icon_gd_webp ); ?></td>
							<td><?php echo esc_html( $icon_gd_avif ); ?></td>
							<td><?php echo esc_html( $icon_gd_webp ); ?></td>
							<td>
								<?php echo esc_html( $icon_gd_avif ); ?>
							</td>
						</tr>
						<tr>
							<td style="text-align: left;"><strong><?php esc_html_e( 'ImageMagick', 'firepunch-images-for-acf' ); ?></strong></td>
							<td><?php echo esc_html( $icon_im_webp ); ?></td>
							<td><?php echo esc_html( $icon_im_avif ); ?></td>
							<td><?php echo esc_html( $icon_im_webp ); ?></td>
							<td><?php echo esc_html( $icon_im_avif ); ?></td>
						</tr>
					</tbody>
				</table>
         </div>

         <?php
         $supports_webp = $imagick_webp || $gd_webp;
         $supports_avif = $imagick_avif || $gd_avif;

         if ( $supports_webp || $supports_avif ) {
            ?>
            <div class="card" style="max-width: 1080px; margin-top: 24px; border-radius: 16px;">
               <h2 class="title" style="margin-top: 6px; margin-bottom: 8px;"><?php esc_html_e( '👀 How to manually verify generated files', 'firepunch-images-for-acf' ); ?></h3>
               <p style="margin-top: 0px; margin-bottom: 16px; color: #646970;">
                  <ol>
                     <li>
                        <?php esc_html_e( 'Upload an image in the media library.', 'firepunch-images-for-acf' ); ?>
                     </li>
                     <li>
                        <?php esc_html_e( 'To see the image, paste its URL into a new browser tab. For example:', 'firepunch-images-for-acf' ); ?>
                        <pre style="background: #f6f7f7; padding: 15px; border-left: 4px solid #72aee6; overflow-x: auto; font-family: monospace; direction: ltr;"><code><?php
                           echo "https://example.com/wp-content/uploads/immagine.jpg";
                        ?></code></pre>
                     </li>
                     <li>
                        <?php esc_html_e( 'To see the converted image in next-gen formats, add .webp or .avif to the end of the URL. For example:', 'firepunch-images-for-acf' ); ?>
                        <pre style="background: #f6f7f7; padding: 15px; border-left: 4px solid #72aee6; overflow-x: auto; font-family: monospace; direction: ltr;"><code><?php
                           if ( $supports_avif ) {
                              echo "https://example.com/wp-content/uploads/immagine.jpg.avif\n";
                           }
                           if ( $supports_webp ) {
                              echo "https://example.com/wp-content/uploads/immagine.jpg.webp";
                           }
                        ?></code></pre>
                     </li>
                  </ol>
               </p>
			   </div>


            <div class="card" style="max-width: 1080px; margin-top: 24px; border-radius: 16px;">
               <h2 class="title" style="margin-top: 6px; margin-bottom: 8px;"><?php esc_html_e( '🧑‍💻 How to use in ACF based WP custom theme development', 'firepunch-images-for-acf' ); ?></h3>
               <p style="margin-top: 0px; margin-bottom: 16px; color: #646970;">
                  <?php echo wp_kses_post( __( 'The plugin provides a global function <code>wp_avif_img()</code> designed to integrate, inside a <code>&lt;picture&gt;</code> HTML tag, the next-gen format images (with fallbacks to standard formats) from an ACF image field.', 'firepunch-images-for-acf' ) ); ?>
               </p>
               
               <hr style="border: 0; border-top: 1px solid #f0f0f1; margin: 15px 0;">

               <h3 style="font-size: 1.1em; margin-bottom: 5px;">1. <?php esc_html_e( 'Standard Example', 'firepunch-images-for-acf' ); ?></h3>
               <p style="margin-top: 0; color: #646970;"><?php esc_html_e( 'Use this approach to retrieve a specific ACF image field, while maintaining a fallback in case the plugin is uninstalled.', 'firepunch-images-for-acf' ); ?></p>
               <pre style="background: #f6f7f7; padding: 15px; border-left: 4px solid #3582c4; overflow-x: auto; font-family: monospace; direction: ltr;"><code>&lt;figure&gt;
   &lt;?php if($acf_img_field): ?&gt;
      &lt;?php if ( function_exists('wp_avif_img') ) {
         $img_id = $acf_img_field['ID']; 
         echo wp_avif_img($img_id);
      } else { ?&gt;
         &lt;img src="&lt;?php echo esc_url($acf_img_field['url']); ?&gt;" alt="&lt;?php echo esc_attr( $acf_img_field['alt'] ); ?&gt;" /&gt;
      &lt;?php } ?&gt;
   &lt;?php endif; ?&gt;
&lt;/figure&gt;</code></pre>

               <hr style="border: 0; border-top: 1px solid #f0f0f1; margin: 15px 0;">

               <h3 style="font-size: 1.1em; margin-top: 20px; margin-bottom: 5px;">2. <?php esc_html_e( 'Advanced Example with Specific Size and Lazy Loading', 'firepunch-images-for-acf' ); ?></h3>
               <p style="margin-top: 0; color: #646970;"><?php esc_html_e( 'Use this approach to request a specific image size (e.g., large) while also enabling native lazy loading.', 'firepunch-images-for-acf' ); ?></p>
               <pre style="background: #f6f7f7; padding: 15px; border-left: 4px solid #3582c4; overflow-x: auto; font-family: monospace; direction: ltr;"><code>&lt;figure&gt;
   &lt;?php if($acf_img_field): ?&gt;
      &lt;?php if ( function_exists('wp_avif_img') ) {
         $img_id = $acf_img_field['ID']; 
         echo wp_avif_img($img_id, 'large', 'lazy');
      } else { ?&gt;
         &lt;img loading="lazy" src="&lt;?php echo esc_url($acf_img_field['url']); ?&gt;" alt="&lt;?php echo esc_attr( $acf_img_field['alt'] ); ?&gt;" /&gt;
      &lt;?php } ?&gt;
   &lt;?php endif; ?&gt;
&lt;/figure&gt;</code></pre>
            </div>
            <?php
         }
         ?>

			<div class="notice <?php echo esc_attr( $summary_class ); ?>" style="max-width: 1080px; margin-top: 20px;">
				<p><strong><?php echo esc_html( $summary_title ); ?></strong><br>
				<?php echo esc_html( $summary_desc ); ?></p>
			</div>

			
		</div>
		<?php
	}
}

// Instantiate singleton.
MB_AVIF_WebP_Optimizer::get_instance();

/**
 * Global helper function for templates.
 *
 * Renders the HTML with picture tags (benefitting from filter rewrite).
 *
 * @param int          $attachment_id The attachment ID.
 * @param string|array $size          The image size. Default 'full'.
 * @param mixed        $attr          Image attributes array, or 'lazy' / 'eager'.
 * @return string Image HTML wrapped in <picture>.
 */
if ( ! function_exists( 'wp_avif_img' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	function wp_avif_img( $attachment_id, $size = 'full', $attr = '' ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return '';
		}

		// Shortcut se viene passato 'lazy' o 'eager' direttamente come size
		if ( $size === 'lazy' || $size === 'eager' ) {
			$attr = $size;
			$size = 'full';
		}

		// Convertiamo sempre in array per una gestione unificata
		if ( ! is_array( $attr ) ) {
			$attr_val = $attr;
			$attr = array();
			if ( $attr_val === true || $attr_val === 'lazy' ) {
				$attr['loading'] = 'lazy';
			} elseif ( $attr_val === false || $attr_val === 'eager' ) {
				$attr['loading'] = 'eager';
			}
		}

		// Verifichiamo se l'utente ha passato una sua classe custom
		$has_custom_class = isset( $attr['class'] );

		// Se non c'è una classe custom, impostiamo un placeholder per sovrascrivere 
		// le classi fastidiose generate di default da WP (es. attachment-full)
		if ( ! $has_custom_class ) {
			$attr['class'] = 'mb-remove-class-placeholder';
		}

		$html = wp_get_attachment_image( $attachment_id, $size, false, $attr );

		// Rimuoviamo completamente l'attributo usando il placeholder
		if ( ! $has_custom_class ) {
			$html = str_replace( ' class="mb-remove-class-placeholder"', '', $html );
		}

		return $html;
	}
}
