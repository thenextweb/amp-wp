<?php
/**
 * Class AMP_YouTube_Embed_Handler
 *
 * @package AMP
 */

/**
 * Class AMP_YouTube_Embed_Handler
 *
 * Much of this class is borrowed from Jetpack embeds.
 */
class AMP_YouTube_Embed_Handler extends AMP_Base_Embed_Handler {
	const SHORT_URL_HOST = 'youtu.be';
	// Only handling single videos. Playlists are handled elsewhere.
	const URL_PATTERN = '#https?://(?:www\.)?(?:youtube.com/(?:v/|e/|embed/|watch[/\#?])|youtu\.be/).*#i';
	const RATIO = 0.5625;

	protected $DEFAULT_WIDTH = 600;
	protected $DEFAULT_HEIGHT = 338;

	function __construct( $args = array() ) {
		parent::__construct( $args );

		if ( isset( $this->args['content_max_width'] ) ) {
			$max_width = $this->args['content_max_width'];
			$this->args['width']  = $max_width;
			$this->args['height'] = round( $max_width * self::RATIO );
		}
	}

	function register_embed() {
		wp_embed_register_handler( 'amp-youtube', self::URL_PATTERN, array( $this, 'oembed' ), -1 );
		add_shortcode( 'youtube', array( $this, 'shortcode' ) );
		add_filter( 'wp_video_shortcode_override', array( $this, 'video_override' ), 10, 2 );
	}

	public function unregister_embed() {
		wp_embed_unregister_handler( 'amp-youtube', -1 );
		remove_shortcode( 'youtube' );
	}

	public function shortcode( $attr ) {
		$url = false;
		$video_id = false;
		if ( isset( $attr[0] ) ) {
			$url = ltrim( $attr[0] , '=' );
		} elseif ( function_exists( 'shortcode_new_to_old_params' ) ) {
			$url = shortcode_new_to_old_params( $attr );
		}

		if ( empty( $url ) ) {
			return '';
		}

		$video_id = $this->get_video_id_from_url( $url );

		return $this->render( array(
			'url' => $url,
			'video_id' => $video_id,
		) );
	}

	public function oembed( $matches, $attr, $url, $rawattr ) {
		return $this->shortcode( array( $url ) );
	}

	public function render( $args ) {
		$args = wp_parse_args( $args, array(
			'video_id' => false,
		) );

		if ( empty( $args['video_id'] ) ) {
			return AMP_HTML_Utils::build_tag( 'a', array( 'href' => esc_url( $args['url'] ), 'class' => 'amp-wp-embed-fallback' ), esc_html( $args['url'] ) );
		}

		$this->did_convert_elements = true;

		return AMP_HTML_Utils::build_tag(
			'amp-youtube',
			array(
				'data-videoid' => $args['video_id'],
				'layout' => 'responsive',
				'width' => $this->args['width'],
				'height' => $this->args['height'],
			)
		);
	}

	private function get_video_id_from_url( $url ) {
		$parsed_url = wp_parse_url( $url );

		if ( ! isset( $parsed_url['host'] ) ) {
			return false;
		}

		$domain = implode( '.', array_slice( explode( '.', $parsed_url['host'] ), -2 ) );
		if ( ! in_array( $domain, [ 'youtu.be', 'youtube.com', 'youtube-nocookie.com' ], true ) ) {
			return false;
		}

		if ( ! isset( $parsed_url['path'] ) ) {
			return false;
		}

		$segments = explode( '/', trim( $parsed_url['path'], '/' ) );

		$query_vars = [];
		if ( isset( $parsed_url['query'] ) ) {
			wp_parse_str( $parsed_url['query'], $query_vars );

			// Handle video ID in v query param, e.g. <https://www.youtube.com/watch?v=XOY3ZUO6P0k>.
			// Support is also included for other query params which don't appear to be supported by YouTube anymore.
			if ( isset( $query_vars['v'] ) ) {
				return $query_vars['v'];
			} elseif ( isset( $query_vars['vi'] ) ) {
				return $query_vars['vi'];
			}
		}

		if ( empty( $segments[0] ) ) {
			return false;
		}

		// For shortened URLs like <http://youtu.be/XOY3ZUO6P0k>, the slug is the first path segment.
		if ( 'youtu.be' === $parsed_url['host'] ) {
			return $segments[0];
		}

		// For non-shortened URLs, the video ID is in the second path segment. For example:
		// * https://www.youtube.com/watch/XOY3ZUO6P0k
		// * https://www.youtube.com/embed/XOY3ZUO6P0k
		// Other top-level segments indicate non-video URLs. There are examples of URLs having segments including
		// 'v', 'vi', and 'e' but these do not work anymore. In any case, they are added here for completeness.
		if ( ! empty( $segments[1] ) && in_array( $segments[0], [ 'embed', 'watch', 'v', 'vi', 'e' ], true ) ) {
			return $segments[1];
		}

		return false;
	}

	private function sanitize_v_arg( $value ) {
		// Deal with broken params like `?v=123?rel=0`
		if ( false !== strpos( $value, '?' ) ) {
			$value = strtok( $value, '?' );
		}

		return $value;
	}

	/**
	 * Override the output of YouTube videos.
	 *
	 * This overrides the value in wp_video_shortcode().
	 * The pattern matching is copied from WP_Widget_Media_Video::render().
	 *
	 * @param string $html Empty variable to be replaced with shortcode markup.
	 * @param array  $attr The shortcode attributes.
	 * @return string|null $markup The markup to output.
	 */
	public function video_override( $html, $attr ) {
		if ( ! isset( $attr['src'] ) ) {
			return $html;
		}
		$src             = $attr['src'];
		$youtube_pattern = '#^https?://(?:www\.)?(?:youtube\.com/watch|youtu\.be/)#';
		if ( 1 === preg_match( $youtube_pattern, $src ) ) {
			return $this->shortcode( array( $src ) );
		}
		return $html;
	}

}
