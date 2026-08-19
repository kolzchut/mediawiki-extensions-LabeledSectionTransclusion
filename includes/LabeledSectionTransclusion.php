<?php

namespace MediaWiki\Extension\LabeledSectionTransclusion;

use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\PPNode;
use MediaWiki\Title\Title;

class LabeledSectionTransclusion {

	/**
	 * Magic word ids holding the localised spelling of the <section> tag and of
	 * its begin/end attributes. They are name tables rather than invocable
	 * magic words — see LabeledSectionTransclusion.i18n.magic.php.
	 */
	public const MW_TAG = 'lst_tag';
	private const MW_ATTR_BEGIN = 'lst_attr_begin';
	private const MW_ATTR_END = 'lst_attr_end';

	/**
	 * Every accepted spelling of a magic word on this wiki, in fallback order.
	 *
	 * LocalisationCache merges magic word synonyms down the language fallback
	 * chain, so the English name is always present and a wiki also inherits the
	 * names of the languages it falls back to.
	 *
	 * @param Parser $parser
	 * @param string $magicWordId
	 * @return string[]
	 */
	public static function getNames( Parser $parser, string $magicWordId ): array {
		return $parser->getMagicWordFactory()->get( $magicWordId )->getSynonyms();
	}

	/*
	 * To do transclusion from an extension, we need to interact with the parser
	 * at a low level. This is the general transclusion functionality
	 */

	/**
	 * Register what we're working on in the parser, so we don't fall into a loop
	 * Upstream removed this entire function, claiming it didn't prevent loops,
	 * but we're not sure, so we just fixed it to use ParserOutput instead.
	 * @param Parser $parser
	 * @param string $part1
	 * @return bool
	 */
	private static function open( $parser, $part1 ) {
		$parserOutput = $parser->getOutput();
		// WikiRights's version sets this on ParserOutput to prevent false positives
		// when multiple parses are done in the same request
		$lstTemplatePath = $parserOutput->getExtensionData( 'LSTTemplatePath' ) ?? [];

		// Infinite loop test
		if ( isset( $lstTemplatePath[$part1] ) ) {
			wfDebug( __METHOD__ . ": template loop broken at '$part1'\n" );
			return false;
		} else {
			$lstTemplatePath[$part1] = 1;
			$parserOutput->setExtensionData( 'LSTTemplatePath', $lstTemplatePath );
			return true;
		}
	}

	/**
	 * Handle recursive substitution here, so we can break cycles, and set up
	 * return values so that edit sections will resolve correctly.
	 * @param Parser $parser
	 * @param Title $title of target page
	 * @param string $text
	 * @param string $part1 Key for cycle detection
	 * @param int $skiphead Number of source string headers to skip for numbering
	 * @return mixed string or magic array of bits
	 */
	private static function parse( $parser, $title, $text, $part1, $skiphead = 0 ) {
		// if someone tries something like<section begin=blah>lst only</section>
		// text, may as well do the right thing. str_ireplace() rather than
		// str_replace() so that a mixed-case closing tag is caught too.
		foreach ( self::getNames( $parser, self::MW_TAG ) as $tagName ) {
			$text = str_ireplace( "</$tagName>", '', $text );
		}

		if ( self::open( $parser, $part1 ) ) {
			// Try to get edit sections correct by munging around the parser's guts.
			return [ $text, 'title' => $title, 'replaceHeadings' => true,
				'headingOffset' => $skiphead, 'noparse' => false, 'noargs' => false ];
		} else {
			return "[[" . $title->getPrefixedText() . "]]" .
				"<!-- WARNING: LST loop detected -->";
		}
	}

	/*
	 * And now, the labeled section transclusion
	 */

	/**
	 * Parser tag hook for <section>.
	 * The section markers aren't paired, so we only need to remove them.
	 *
	 * @param string $in
	 * @param array $assocArgs
	 * @param Parser|null $parser
	 * @return string HTML output
	 */
	public static function noop( $in, $assocArgs = [], $parser = null ) {
		return '';
	}

	/**
	 * Generate a regex fragment matching the attribute portion of a section tag
	 * @param string $sec Name of the target section
	 * @param string[] $attrNames Accepted spellings of the "begin" or "end" attribute
	 * @return string
	 */
	private static function getAttrPattern( $sec, array $attrNames ) {
		$sec = preg_quote( $sec, '/' );
		$ws = "(?:\s+[^>]*)?"; // was like $ws="\s*"
		$attrs = array_map( static function ( $name ) {
			return preg_quote( $name, '/' );
		}, $attrNames );
		$attrName = '(?i:' . implode( '|', $attrs ) . ')';
		return "$ws\s+$attrName\s*=\s*([\"']?)$sec\\1$ws";
	}

	/**
	 * Count headings in skipped text.
	 *
	 * Count skipped headings, so parser (as of r18218) can skip them, to
	 * prevent wrong heading links (see bug 6563).
	 *
	 * @param string $text
	 * @param int $limit Cutoff point in the text to stop searching
	 * @return int Number of matches
	 */
	private static function countHeadings( $text, $limit ) {
		$pat = '^(={1,6}).+\1\s*$()';

		$count = 0;
		$offset = 0;
		$m = [];
		while ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			if ( $m[2][1] > $limit ) {
				break;
			}

			$count++;
			$offset = $m[2][1];
		}

		return $count;
	}

	/**
	 * Fetches content of target page if valid and found, otherwise
	 * produces wikitext of a link to the target page.
	 *
	 * @param Parser $parser
	 * @param string $page title text of target page
	 * @param Title &$title normalized title object
	 * @param string &$text wikitext output
	 * @return bool true if returning text, false if target not found
	 */
	private static function getTemplateText( $parser, $page, &$title, &$text ) {
		$title = Title::newFromText( $page );

		if ( $title === null || $title->isExternal() ) {
			$text = '';
			return false;
		} else {
			[ $text, $title ] = $parser->fetchTemplateAndTitle( $title );
		}

		// if article doesn't exist, return a red link.
		if ( $text === false ) {
			$text = "[[" . $title->getPrefixedText() . "]]";
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Set up some variables for MW-1.12 parser functions
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @param string $func
	 * @return array|string
	 */
	private static function setupPfunc12( $parser, $frame, $args, $func = 'lst' ) {
		if ( !count( $args ) ) {
			$parser->addTrackingCategory( "lst-invalid-section-category" );
			return '';
		}

		$title = Title::newFromText( trim( $frame->expand( array_shift( $args ) ) ) );
		if ( !$title || $title->isExternal() ) {
			$parser->addTrackingCategory( "lst-invalid-section-category" );
			return '';
		}
		if ( !$frame->loopCheck( $title ) ) {
			return '<span class="error">'
				. wfMessage( 'parser-template-loop-warning', $title->getPrefixedText() )
					->inContentLanguage()->text()
				. '</span>';
		}

		[ $root, $finalTitle ] = $parser->getTemplateDom( $title );

		// if article doesn't exist, return a red link.
		if ( $root === false ) {
			return "[[" . $title->getPrefixedText() . "]]";
		}

		$newFrame = $frame->newChild( false, $finalTitle );
		if ( !count( $args ) ) {
			return $newFrame->expand( $root );
		}

		$begin = trim( $frame->expand( array_shift( $args ) ) );

		$repl = null;
		if ( $func == 'lstx' ) {
			if ( !count( $args ) ) {
				$repl = '';
			} else {
				$repl = trim( $frame->expand( array_shift( $args ) ) );
			}
		}

		if ( !count( $args ) ) {
			$end = $begin;
		} else {
			$end = trim( $frame->expand( array_shift( $args ) ) );
		}

		$beginAttr = self::getAttrPattern( $begin, self::getNames( $parser, self::MW_ATTR_BEGIN ) );
		$beginRegex = "/^$beginAttr$/s";
		$endAttr = self::getAttrPattern( $end, self::getNames( $parser, self::MW_ATTR_END ) );
		$endRegex = "/^$endAttr$/s";

		return [
			'root' => $root,
			'newFrame' => $newFrame,
			'repl' => $repl,
			'beginRegex' => $beginRegex,
			'begin' => $begin,
			'endRegex' => $endRegex,
		];
	}

	/**
	 * Returns true if the given extension name is one of the section tag's
	 * accepted spellings on this wiki
	 * @param string $name
	 * @param string[] $tagNames
	 * @return bool
	 */
	private static function isSection( $name, array $tagNames ) {
		$name = strtolower( $name );
		foreach ( $tagNames as $tagName ) {
			if ( $name === strtolower( $tagName ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the text for the inside of a split <section> node
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $parts
	 * @return string
	 */
	private static function expandSectionNode( $parser, $frame, $parts ) {
		if ( isset( $parts['inner'] ) ) {
			return $parser->replaceVariables( $parts['inner'], $frame );
		} else {
			return '';
		}
	}

	/**
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return array|string
	 */
	public static function pfuncIncludeObj( $parser, $frame, $args ) {
		$text = self::getSectionText( $parser, $frame, $args );
		if ( $text === null ) {
			$parser->addTrackingCategory( "lst-invalid-section-category" );
			return '';
		}
		return $text;
	}

	/**
	 * Core of #lst: transclude the labeled <section>...</section> identified
	 * by $args, or return null if no matching section exists on the (existing)
	 * target page. Unlike pfuncIncludeObj() this does NOT add the tracking
	 * category, so callers such as pfuncIncludeAny() can decide whether a miss
	 * is really an error.
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return string|null
	 */
	private static function getSectionText( $parser, $frame, $args ) {
		$setup = self::setupPfunc12( $parser, $frame, $args, 'lst' );
		if ( !is_array( $setup ) ) {
			return $setup;
		}

		/**
		 * @var $root PPNode
		 */
		$root = $setup['root'];
		/**
		 * @var $newFrame PPFrame
		 */
		$newFrame = $setup['newFrame'];
		$beginRegex = $setup['beginRegex'];
		$endRegex = $setup['endRegex'];
		$begin = $setup['begin'];

		$tagNames = self::getNames( $parser, self::MW_TAG );
		$text = '';
		$node = $root->getFirstChild();
		$foundSection = false;
		while ( $node ) {
			// If the name of the begin node was specified, find it.
			// Otherwise transclude everything from the beginning of the page.
			if ( $begin !== '' ) {
				// Find the begin node
				$found = false;
				for ( ; $node; $node = $node->getNextSibling() ) {
					if ( $node->getName() !== 'ext' ) {
						continue;
					}
					$parts = $node->splitExt();
					$parts = array_map( [ $newFrame, 'expand' ], $parts );
					if ( self::isSection( $parts['name'], $tagNames ) ) {
						// @phan-suppress-next-line SecurityCheck-ReDoS
						if ( preg_match( $beginRegex, $parts['attr'] ) ) {
							$found = true;
							$foundSection = true;
							break;
						}
					}
				}
				if ( !$found || !$node ) {
					break;
				}
			}

			// Write the text out while looking for the end node
			$found = false;
			for ( ; $node; $node = $node->getNextSibling() ) {
				if ( $node->getName() === 'ext' ) {
					$parts = $node->splitExt();
					$parts = array_map( [ $newFrame, 'expand' ], $parts );
					if ( self::isSection( $parts['name'], $tagNames ) ) {
						// @phan-suppress-next-line SecurityCheck-ReDoS
						if ( preg_match( $endRegex, $parts['attr'] ) ) {
							$found = true;
							$foundSection = true;
							break;
						}
						$text .= self::expandSectionNode( $parser, $newFrame, $parts );
					} else {
						$text .= $newFrame->expand( $node );
					}
				} else {
					$text .= $newFrame->expand( $node );
				}
			}
			if ( !$found ) {
				break;
			} elseif ( $begin === '' ) {
				// When the end node was found and text is transcluded from
				// the beginning of the page, finish the transclusion
				break;
			}

			$node = $node->getNextSibling();
		}
		if ( !$foundSection ) {
			return null;
		}
		return $text;
	}

	/**
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return array|string
	 */
	public static function pfuncExcludeObj( $parser, $frame, $args ) {
		$setup = self::setupPfunc12( $parser, $frame, $args, 'lstx' );
		if ( !is_array( $setup ) ) {
			return $setup;
		}

		/**
		 * @var $root PPNode
		 */
		$root = $setup['root'];
		/**
		 * @var $newFrame PPFrame
		 */
		$newFrame = $setup['newFrame'];
		$beginRegex = $setup['beginRegex'];
		$endRegex = $setup['endRegex'];
		$repl = $setup['repl'];

		$tagNames = self::getNames( $parser, self::MW_TAG );
		$text = '';
		// phpcs:ignore Generic.CodeAnalysis.JumbledIncrementer.Found
		for ( $node = $root->getFirstChild(); $node; $node = $node ? $node->getNextSibling() : false ) {
			// Search for the start tag
			$found = false;
			for ( ; $node; $node = $node->getNextSibling() ) {
				if ( $node->getName() == 'ext' ) {
					$parts = $node->splitExt();
					$parts = array_map( [ $newFrame, 'expand' ], $parts );
					if ( self::isSection( $parts['name'], $tagNames ) ) {
						// @phan-suppress-next-line SecurityCheck-ReDoS
						if ( preg_match( $beginRegex, $parts['attr'] ) ) {
							$found = true;
							break;
						}
						$text .= self::expandSectionNode( $parser, $newFrame, $parts );
					} else {
						$text .= $newFrame->expand( $node );
					}
				} else {
					$text .= $newFrame->expand( $node );
				}
			}

			if ( !$found ) {
				break;
			}

			// Append replacement text
			$text .= $repl;

			// Search for the end tag
			for ( ; $node; $node = $node->getNextSibling() ) {
				if ( $node->getName() == 'ext' ) {
					$parts = $node->splitExt();
					$parts = array_map( [ $newFrame, 'expand' ], $parts );
					if ( self::isSection( $parts['name'], $tagNames ) ) {
						// @phan-suppress-next-line SecurityCheck-ReDoS
						if ( preg_match( $endRegex, $parts['attr'] ) ) {
							$text .= self::expandSectionNode( $parser, $newFrame, $parts );
							break;
						}
					}
				}
			}
		}
		return $text;
	}

	/**
	 * section inclusion - include all matching sections
	 *
	 * A parser extension that further extends labeled section transclusion,
	 * adding a function, #lsth for transcluding marked sections of text,
	 *
	 * @todo MW 1.12 version, as per #lst/#lstx
	 *
	 * @param Parser $parser
	 * @param string $page
	 * @param string $sec
	 * @param string $to
	 * @return mixed|string
	 */
	public static function pfuncIncludeHeading( $parser, $page = '', $sec = '', $to = '' ) {
		$text = self::getHeadingText( $parser, $page, $sec, $to );
		if ( $text === null ) {
			$parser->addTrackingCategory( "lst-invalid-section-category" );
			return '';
		}
		return $text;
	}

	/**
	 * Core of #lsth: transclude the content beneath the === heading ===
	 * identified by $sec (optionally up to $to), or return null if no such
	 * heading exists on the (existing) target page. Unlike
	 * pfuncIncludeHeading() this does NOT add the tracking category.
	 *
	 * @param Parser $parser
	 * @param string $page
	 * @param string $sec
	 * @param string $to
	 * @return string|null
	 */
	private static function getHeadingText( $parser, $page = '', $sec = '', $to = '' ) {
		if ( self::getTemplateText( $parser, $page, $title, $text ) == false ) {
			return $text;
		}

		// Generate a regex to match the === classical heading section(s) === we're
		// interested in.
		if ( $sec == '' ) {
			$begin_off = 0;
			$head_len = 6;
		} else {
			//WR: changed pattern to ignore comments on the same line as the heading
			//$pat = '^(={1,6})\s*' . preg_quote( $sec, '/' ) . '\s*\1\s*($)';
			$pat = '^(={1,6})\s*' . preg_quote( $sec, '/' ) . '\s*\1\s*(?:<!--(?!-->).*-->)?\s*($)' ;
			if ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE ) ) {
				$begin_off = $m[2][1];
				$head_len = strlen( $m[1][0] );
			} else {
				return null;
			}

		}

		$end_off = null;
		if ( $to != '' ) {
			// if $to is supplied, try and match it. If we don't match, just
			// ignore it.
			//WR: changed pattern to ignore comments on the same line as the heading
			//$pat = '^(={1,6})\s*' . preg_quote( $to, '/' ) . '\s*\1\s*$';
			$pat = '^(={1,6})\s*' . preg_quote( $to, '/' ) . '\s*\1\s*(?:<!--(?!-->).*-->)?\s*$';
			if ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $begin_off ) ) {
				$end_off = $m[0][1] - 1;
			}
		}

		if ( $end_off === null ) {
			//WR: changed pattern to ignore comments on the same line as the heading
			//$pat = '^(={1,' . $head_len . '})(?!=).*?\1\s*$';
			$pat = '^(={1,' . $head_len . '})(?!=).*?\1\s*(?:<!--(?!-->).*-->)?\s*$';
			if ( preg_match( "/$pat/im", $text, $m, PREG_OFFSET_CAPTURE, $begin_off ) ) {
				$end_off = $m[0][1] - 1;
			}
		}

		$nhead = self::countHeadings( $text, $begin_off );

		if ( $end_off !== null ) {
			$result = substr( $text, $begin_off, $end_off - $begin_off );
		} else {
			$result = substr( $text, $begin_off );
		}

		$frame = $parser->getPreprocessor()->newFrame();
		$dom = $parser->preprocessToDom( $result, Parser::PTD_FOR_INCLUSION );
		$result = $frame->expand( $dom );
		$result = trim( $result );

		return self::parse( $parser, $title, $result, "#lsth:{$page}|{$sec}", $nhead );
	}

	/**
	 * #lstall: transclude a portion of a page identified either by a
	 * === heading === (as #lsth does) or by <section> labels (as #lst does),
	 * whichever exists. Tries the heading first — matching the historical
	 * {{#lsth:...}}{{#lst:...}} template idiom used on-wiki — then falls back
	 * to a labeled section.
	 *
	 * The "nonexistent section" tracking category is added only when BOTH
	 * lookups miss. This avoids the false positive inherent in the old idiom,
	 * where a name that is legitimately a heading (but not a <section>), or
	 * vice-versa, always tripped the category via the one function that could
	 * never match it.
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return string
	 */
	public static function pfuncIncludeAny( $parser, $frame, $args ) {
		$page = isset( $args[0] ) ? trim( $frame->expand( $args[0] ) ) : '';
		$sec = isset( $args[1] ) ? trim( $frame->expand( $args[1] ) ) : '';
		$to = isset( $args[2] ) ? trim( $frame->expand( $args[2] ) ) : '';

		// Prefer a classical heading (the common case in the on-wiki idiom).
		$text = self::getHeadingText( $parser, $page, $sec, $to );
		if ( $text !== null ) {
			return $text;
		}

		// Fall back to a labeled <section>.
		$text = self::getSectionText( $parser, $frame, $args );
		if ( $text !== null ) {
			return $text;
		}

		// Neither a heading nor a labeled section by that name exists.
		$parser->addTrackingCategory( "lst-invalid-section-category" );
		return '';
	}
}
