<?php
/**
 * Small, dependency-free HTML → GitHub-flavoured Markdown converter for post content.
 * Handles headings, paragraphs, emphasis, links, images, lists (nested), block quotes, code,
 * tables, figures and line breaks. Scripts, styles, forms and embeds are dropped or linked.
 *
 * @package PublishRankSEO
 */
namespace PublishRankSEO\AI;

defined( 'ABSPATH' ) || exit;

final class HtmlToMarkdown {
	/** @var string */
	private $base = '';

	public function __construct( $base_url = '' ) {
		$this->base = (string) $base_url;
	}

	public function convert( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}
		if ( ! class_exists( '\\DOMDocument' ) ) {
			return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="pr-md-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $doc->getElementById( 'pr-md-root' );
		if ( ! $root ) {
			return trim( wp_strip_all_tags( $html ) );
		}
		$md = $this->children( $root, 0 );
		$md = preg_replace( "/[ \t]+\n/", "\n", $md );
		$md = preg_replace( "/\n{3,}/", "\n\n", (string) $md );
		return trim( (string) $md ) . "\n";
	}

	private function children( \DOMNode $node, $depth ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= $this->node( $child, $depth );
		}
		return $out;
	}

	private function inline( \DOMNode $node ) {
		$t = trim( preg_replace( '/\s+/u', ' ', $this->children( $node, 0 ) ) );
		return $t;
	}

	private function url( $u ) {
		$u = trim( (string) $u );
		if ( '' === $u || preg_match( '#^(javascript|data|vbscript):#i', $u ) ) {
			return '';
		}
		if ( 0 === strpos( $u, '//' ) ) {
			return 'https:' . $u;
		}
		if ( '/' === substr( $u, 0, 1 ) && '' !== $this->base ) {
			return untrailingslashit( $this->base ) . $u;
		}
		return $u;
	}

	private static function esc( $text ) {
		return preg_replace( '/([\\\\`*_\[\]])/', '\\\\$1', $text );
	}

	private function node( \DOMNode $n, $depth ) {
		if ( XML_TEXT_NODE === $n->nodeType ) {
			$t = preg_replace( '/\s+/u', ' ', $n->nodeValue );
			return self::esc( (string) $t );
		}
		if ( XML_ELEMENT_NODE !== $n->nodeType ) {
			return '';
		}
		/** @var \DOMElement $n */
		$tag = strtolower( $n->nodeName );
		switch ( $tag ) {
			case 'script':
			case 'style':
			case 'noscript':
			case 'template':
			case 'form':
			case 'button':
			case 'input':
			case 'select':
			case 'textarea':
			case 'svg':
			case 'nav':
				return '';
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return "\n\n" . str_repeat( '#', $level ) . ' ' . $this->inline( $n ) . "\n\n";
			case 'p':
				$t = $this->inline( $n );
				return '' === $t ? '' : "\n\n" . $t . "\n\n";
			case 'br':
				return "  \n";
			case 'hr':
				return "\n\n---\n\n";
			case 'strong':
			case 'b':
				$t = $this->inline( $n );
				return '' === $t ? '' : '**' . $t . '**';
			case 'em':
			case 'i':
				$t = $this->inline( $n );
				return '' === $t ? '' : '*' . $t . '*';
			case 'del':
			case 's':
				$t = $this->inline( $n );
				return '' === $t ? '' : '~~' . $t . '~~';
			case 'code':
				if ( $n->parentNode && 'pre' === strtolower( $n->parentNode->nodeName ) ) {
					return $n->textContent;
				}
				$t = $n->textContent;
				$f = false !== strpos( $t, '`' ) ? '``' : '`';
				return $f . $t . $f;
			case 'pre':
				$lang = '';
				$code = $n->getElementsByTagName( 'code' )->item( 0 );
				$cls  = $code instanceof \DOMElement ? $code->getAttribute( 'class' ) : $n->getAttribute( 'class' );
				if ( preg_match( '/language-([a-z0-9+#-]+)/i', $cls, $m ) ) {
					$lang = $m[1];
				}
				return "\n\n```" . $lang . "\n" . rtrim( $n->textContent ) . "\n```\n\n";
			case 'a':
				$href = $this->url( $n->getAttribute( 'href' ) );
				$t    = $this->inline( $n );
				if ( '' === $href || '#' === substr( $href, 0, 1 ) ) {
					return $t;
				}
				if ( '' === $t ) {
					return '<' . $href . '>';
				}
				return '[' . $t . '](' . str_replace( array( ' ', ')' ), array( '%20', '%29' ), $href ) . ')';
			case 'img':
				$src = $this->url( $n->getAttribute( 'src' ) );
				if ( '' === $src ) {
					return '';
				}
				return '![' . self::esc( trim( $n->getAttribute( 'alt' ) ) ) . '](' . str_replace( ' ', '%20', $src ) . ')';
			case 'figure':
				$cap  = '';
				$caps = $n->getElementsByTagName( 'figcaption' );
				if ( $caps->length ) {
					$cap = $this->inline( $caps->item( 0 ) );
					$caps->item( 0 )->parentNode->removeChild( $caps->item( 0 ) );
				}
				$body = trim( $this->children( $n, $depth ) );
				return "\n\n" . $body . ( '' !== $cap ? "\n\n*" . $cap . '*' : '' ) . "\n\n";
			case 'blockquote':
				$inner = trim( $this->children( $n, $depth ) );
				$lines = preg_split( "/\n/", $inner );
				return "\n\n" . implode( "\n", array_map( static function ( $l ) {
					return '> ' . $l;
				}, $lines ) ) . "\n\n";
			case 'ul':
			case 'ol':
				return "\n\n" . $this->list_items( $n, $depth, 'ol' === $tag ) . "\n\n";
			case 'table':
				return "\n\n" . $this->table( $n ) . "\n\n";
			case 'iframe':
			case 'video':
			case 'audio':
			case 'embed':
			case 'object':
				$src = $this->url( $n->getAttribute( 'src' ) ? $n->getAttribute( 'src' ) : $n->getAttribute( 'data' ) );
				if ( '' === $src ) {
					foreach ( $n->getElementsByTagName( 'source' ) as $s ) {
						$src = $this->url( $s->getAttribute( 'src' ) );
						if ( '' !== $src ) {
							break;
						}
					}
				}
				$title = $n->getAttribute( 'title' );
				return '' === $src ? '' : "\n\n[" . ( '' !== $title ? self::esc( $title ) : ucfirst( $tag ) ) . '](' . $src . ")\n\n";
			case 'div':
			case 'section':
			case 'article':
			case 'main':
			case 'header':
			case 'footer':
			case 'aside':
				return "\n\n" . $this->children( $n, $depth ) . "\n\n";
			default:
				return $this->children( $n, $depth );
		}
	}

	private function list_items( \DOMNode $list, $depth, $ordered ) {
		$out = array();
		$i   = 1;
		foreach ( $list->childNodes as $li ) {
			if ( XML_ELEMENT_NODE !== $li->nodeType || 'li' !== strtolower( $li->nodeName ) ) {
				continue;
			}
			$text   = '';
			$nested = '';
			foreach ( $li->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType && in_array( strtolower( $c->nodeName ), array( 'ul', 'ol' ), true ) ) {
					$nested .= "\n" . $this->list_items( $c, $depth + 1, 'ol' === strtolower( $c->nodeName ) );
				} else {
					$text .= $this->node( $c, $depth + 1 );
				}
			}
			$text  = trim( preg_replace( '/\s*\n\s*/', ' ', trim( $text ) ) );
			$out[] = str_repeat( '  ', $depth ) . ( $ordered ? $i . '. ' : '- ' ) . $text . $nested;
			++$i;
		}
		return implode( "\n", $out );
	}

	private function table( \DOMElement $table ) {
		$rows = array();
		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $c ) {
				if ( XML_ELEMENT_NODE === $c->nodeType && in_array( strtolower( $c->nodeName ), array( 'td', 'th' ), true ) ) {
					$cells[] = str_replace( '|', '\\|', $this->inline( $c ) );
				}
			}
			if ( $cells ) {
				$rows[] = $cells;
			}
		}
		if ( ! $rows ) {
			return '';
		}
		$cols = max( array_map( 'count', $rows ) );
		$out  = array();
		foreach ( $rows as $i => $r ) {
			$r     = array_pad( $r, $cols, '' );
			$out[] = '| ' . implode( ' | ', $r ) . ' |';
			if ( 0 === $i ) {
				$out[] = '|' . str_repeat( ' --- |', $cols );
			}
		}
		return implode( "\n", $out );
	}
}
