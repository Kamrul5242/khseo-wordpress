<?php
/**
 * Structured, parse-once view of one fetched page.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Audit;

/**
 * Everything the analyzers need, extracted once from the fetched HTML.
 * All string values are UNTRUSTED page data: escape on output, never interpret.
 */
final class PageSnapshot {

	/**
	 * Constructor.
	 *
	 * @param string                                                                         $url               Requested (normalised) URL.
	 * @param string                                                                         $final_url         URL after redirects.
	 * @param int                                                                            $status            HTTP status of the final hop.
	 * @param array<int, string>                                                             $chain             URLs requested in order.
	 * @param string                                                                         $content_type      Content-Type header.
	 * @param string                                                                         $x_robots_tag      X-Robots-Tag header (lower-cased).
	 * @param string                                                                         $content_language  Content-Language header.
	 * @param string                                                                         $charset           Charset from header or meta ('' if none).
	 * @param bool                                                                           $is_html           Whether the body was parsed as HTML.
	 * @param bool                                                                           $truncated         Whether parsing stopped at the size cap.
	 * @param array<int, string>                                                             $titles            Every <title> text.
	 * @param array<int, string>                                                             $descriptions      Every meta description content.
	 * @param array<int, string>                                                             $canonicals        Every rel=canonical href (raw).
	 * @param array<int, string>                                                             $robots_meta       Every robots/googlebot meta content (lower-cased).
	 * @param string|null                                                                    $html_lang         <html lang> (null if missing).
	 * @param array<int, array{level: int, text: string}>                                    $headings      h1–h6 in document order.
	 * @param array<int, array{href: string, text: string, rel: array<int, string>}>         $links Links.
	 * @param array<int, array{src: string, alt: string|null, sized: bool, loading: string}> $images Images.
	 * @param array<int, string>                                                             $json_ld           Raw JSON-LD script bodies.
	 * @param array<string, array<int, string>>                                              $open_graph        og:* property => values.
	 * @param array<string, array<int, string>>                                              $twitter           twitter:* name => values.
	 * @param string                                                                         $text              Visible text (normalised, capped).
	 * @param int                                                                            $word_count        Words in visible text.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $final_url,
		public readonly int $status,
		public readonly array $chain,
		public readonly string $content_type,
		public readonly string $x_robots_tag,
		public readonly string $content_language,
		public readonly string $charset,
		public readonly bool $is_html,
		public readonly bool $truncated,
		public readonly array $titles = array(),
		public readonly array $descriptions = array(),
		public readonly array $canonicals = array(),
		public readonly array $robots_meta = array(),
		public readonly ?string $html_lang = null,
		public readonly array $headings = array(),
		public readonly array $links = array(),
		public readonly array $images = array(),
		public readonly array $json_ld = array(),
		public readonly array $open_graph = array(),
		public readonly array $twitter = array(),
		public readonly string $text = '',
		public readonly int $word_count = 0
	) {}

	/**
	 * All robots directives (meta + X-Robots-Tag without a bot prefix), lower-cased.
	 *
	 * @return array<int, string>
	 */
	public function robotsDirectives(): array {
		$parts = array();
		foreach ( array_merge( $this->robots_meta, array( $this->x_robots_tag ) ) as $value ) {
			foreach ( explode( ',', $value ) as $directive ) {
				$directive = trim( $directive );
				// "googlebot: noindex" style header values apply to that bot; KHSEO evaluates Googlebot and generic rules.
				if ( preg_match( '/^([a-z0-9_-]+):\s*(.+)$/', $directive, $m ) && ! in_array( $m[1], array( 'max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after' ), true ) ) {
					$directive = 'googlebot' === $m[1] ? trim( $m[2] ) : '';
				}
				if ( '' !== $directive ) {
					$parts[] = $directive;
				}
			}
		}
		return array_values( array_unique( $parts ) );
	}

	/**
	 * Whether a noindex/none directive applies.
	 */
	public function isNoindex(): bool {
		return (bool) array_intersect( array( 'noindex', 'none' ), $this->robotsDirectives() );
	}
}
