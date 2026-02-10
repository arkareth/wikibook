<?php

/* Copyright 2018 Sergey Menshikov

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE. */

use MediaWiki\Context\RequestContext;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Html\Html;
use MediaWiki\Language\Language;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use Wikimedia\Parsoid\Core\TOCData;

/**
 * SuperPageTOC extension - generates enhanced table of contents for superpages
 *
 * @copyright 2018 Sergey Menshikov
 * @license MIT
 */
class SuperPageTOC
{
	/** @var int|null */
	private static $mLevel;

	/** @var string|null Last heading found - toplevel document's first h1 */
	private static $mHeading;

	/** @var bool */
	private static $mBoolFlag;

	/** @var string|null */
	private static $mNamespace;

	/** @var string|null */
	private static $mCurrentLink;

	/** @var bool */
	private static $mBoolTopicFound;

	/** @var string|null */
	private static $mContLangCode;

	/** @var string|null */
	private static $mPageLangCode;

	/**
	 * Hook handler for BeforePageDisplay
	 * Ensure our TOC styles (sticky override, whitespace) load on every page for skins that
	 * move the TOC to a sidebar, so they apply regardless of parser cache.
	 *
	 * @param \MediaWiki\Output\OutputPage $out
	 * @param \MediaWiki\Skin\Skin $skin
	 * @return void
	 */
	public static function onBeforePageDisplay( $out, $skin ): void {
		$skinName = $skin->getSkinName();
		if ( in_array( $skinName, [ 'tweeki', 'vector' ], true ) ) {
			$out->addModuleStyles( [ 'ext.superpagetoc.styles' ] );
		}
	}

	/**
	 * Hook handler for ParserBeforeStrip
	 *
	 * @param Parser $parser
	 * @param string &$text
	 * @param \MediaWiki\Parser\StripState &$stripState
	 * @return bool
	 */
	public static function onParserBeforeStrip( Parser $parser, string &$text, &$stripState ): bool {
		$title = $parser->getPage();
		// Only do this for pages
		/** @var Title $title */
		if ( !$title->exists() ) {
			return true;
		}
		$request = RequestContext::getMain()->getRequest();
		$action = $request->getVal( 'action', 'view' );
		// Only inject version selector and TOC on view/print (not purge, history, etc.)
		if ( in_array( $action, [ 'view', 'print' ], true ) || $action === null ) {
			// Per parser instance so each parse (e.g. oldid view) gets the injection; static $hasRun
			// once per request caused version controls/TOC to be skipped on ?oldid pages.
			static $injectedParserIds = [];
			$parserId = spl_object_id( $parser );
			if ( isset( $injectedParserIds[$parserId] ) ) {
				return true;
			}
			$injectedParserIds[$parserId] = true;
			// Ensure TOC is always shown
			$text = "__FORCETOC__\r\n" . $text;
			// Show language bar for pages subject to translation
			$text = "<languages/><doc-version-selector/>\n" . $text;
			// Add /prevnext/ ? only from subpages
			$doc = $title->getFullText();
			$separatorPos = strpos( $doc, '/' );
			// Calling from a subpage
			if ( $separatorPos !== false && $separatorPos > 1 ) {
				$text .= "/prevnext/";
			}
			return true;
		}
		return true;
	}

	/**
	 * Hook handler for ParserAfterParse
	 *
	 * @param Parser $parser
	 * @param string &$text
	 * @param \MediaWiki\Parser\StripState &$stripState
	 * @return bool
	 */
	public static function onParserAfterParse( Parser $parser, string &$text, &$stripState ): bool
	{
		/** @var Title $title */
		$title = $parser->getPage();
		if ( !$title->exists() ) {
			return true;
		}
		$tocText = self::generateTOC( $parser->getOutput()->getTOCData() );
		// If there is a TOC and we are not printing - substitute with a new TOC
		if ( strlen( $tocText ) > 0 && !$parser->getOptions()->getIsPrintable() ) {
			// addModuleStyles() requires an array of module names (MW 1.40+)
			$parser->getOutput()->addModuleStyles( [ 'ext.superpagetoc.styles' ] );
			// Memorize lang codes
			self::$mNamespace = $title->getSubjectNsText();
			self::$mPageLangCode = $title->getPageLanguage()->getCode();
			self::$mContLangCode = MediaWikiServices::getInstance()->getContentLanguage()->getCode();
			// Build the TOC list
			self::$mHeading = self::findHeadingTextFromTitle( $title );
			$tocList = self::generateSuperPageTocList( $title, self::$mHeading, [ [ 'level' => 1, 'title' => 1, 'link' => 1 ] ] );

			$level = 1;
			$section = 1;
			$newTocText = '<li class="toc-heading">' . htmlspecialchars( self::$mHeading ?? '' ) . "</li>";
			$prev = false;
			$next = false;
			$last = false;
			$prevLast = false;
			$childFound = false;
			$openli = false;
			$index1 = stripos( $tocText, '<ul>' );
			$index2 = strripos( $tocText, '</ul>' );
			if ( $index1 !== false ) {
				$index1 += 4;
			}
			foreach ( $tocList as $item ) {
				// Not a link - text
				if ( $item['link'] === null ) {
					// TODO close li before static if changing levels - save levels with static
					$newTocText .= '<span class="toclevel-' . $level . ' toctext tocstatic">' .
						htmlspecialchars( $item['title'] ) . '</span>';
					continue;
				}
				// If matches link = 1, insert this page's toc HTML
				if ( $item['link'] == 1 ) {
					$tocSnippet = substr( $tocText, $index1, $index2 - $index1 );
					if ( preg_match( '/<li\s.+?(<ul.+)<\/li>/s', $tocSnippet, $matches ) == 1 ) {
						// Remove top heading
						$tocSnippet = $matches[1];
					} else {
						$tocSnippet = "";
					}
					$tocSnippet = preg_replace(
						'/toclevel-1 tocsection-1/',
						'toclevel-' . $level . ' tocsection-' . $section . ' toc-open',
						$tocSnippet,
						1
					);
					$newTocText .= $tocSnippet;
					$prev = $prevLast;
					$childFound = true;
					$section += 1;
					continue;
				}
				// If prev link is set, then this one is next
				if ( $childFound && !$next ) {
					$next = $item;
				}
				// Adjust levels
				if ( $level < $item['level'] ) {
					$newTocText .= str_repeat( '<ul>', $item['level'] - $level );
					$level = $item['level'];
				} elseif ( $level > $item['level'] ) {
					$newTocText .= str_repeat( '</ul></li>', $level - $item['level'] );
					$level = $item['level'];
					$openli = false;
				} elseif ( $openli ) {
					$newTocText .= '</li>';
				}
				// Render lines from toc list
				$url = self::renderLink( $item['link'] );
				$isOpen = "";
				if ( array_key_exists( 'is_open', $item ) ) {
					$isOpen = "toc-open";
				}
				$newTocText .= '<li class="toclevel-' . $level . ' tocsection-' . $section . ' ' . $isOpen . '">' .
					'<a href="' . htmlspecialchars( $url ) . '"><span class="toctext">' .
					htmlspecialchars( $item['title'] ) . '</span></a>';
				$openli = true;
				$section += 1;
				// Memorize last item so we could set prev link
				$prevLast = $last;
				$last = $item;
			}
			if ( $openli ) {
				$newTocText .= '</li>';
			}
			// Add beginning and end of the original HTML toc and replace TOC in the page
			$newToc = substr( $tocText, 0, $index1 ) . $newTocText . substr( $tocText, $index2 );
			$text = Parser::replaceTableOfContentsMarker( $text, $newToc );

			// Generate Previous | Next links at the bottom of the page
			$prevnext = "";
			if ( $prev ) {
				$prevnext .= '<a href="' . htmlspecialchars( self::renderLink( $prev['link'] ) ) . '">&lt; ' .
					htmlspecialchars( wfMessage( 'wikibook-prev' )->inLanguage( self::$mPageLangCode )->text() ) . '</a>';
			}
			if ( $prev && $next ) {
				$prevnext .= ' | ';
			}
			if ( $next ) {
				$prevnext .= '<a href="' . htmlspecialchars( self::renderLink( $next['link'] ) ) . '">' .
					htmlspecialchars( wfMessage( 'wikibook-next' )->inLanguage( self::$mPageLangCode )->text() ) . ' &gt;</a>';
			}
			$prevnext = "<center>" . $prevnext . "</center>";
			$text = str_replace( "/prevnext/", $prevnext, $text );
		}
		return true;
	}

	/**
	 * Render a link URL from a link identifier
	 *
	 * @param string|int $link Link identifier
	 * @return string URL
	 */
	private static function renderLink( $link ): string {
		// Prepare link
		$linkDoc = trim( self::$mNamespace . ':' . $link );
		// See if there is a language-specific version of the link doc
		if ( self::$mPageLangCode != self::$mContLangCode ) {
			$linkLangTitle = Title::newFromText( $linkDoc . "/" . self::$mPageLangCode );
			if ( $linkLangTitle && $linkLangTitle->exists() ) {
				$linkDoc .= "/" . self::$mPageLangCode;
			}
		}
		// Render URL
		$doc = Title::newFromText( $linkDoc );
		if ( $doc ) {
			return $doc->getFullURL();
		}
		return "bad+link";
	}

	/**
	 * Looks up for a parent title and returns TOC array; recurses if there are ancestors on top of the parent
	 *
	 * @param Title $childTitle
	 * @param string|null $heading
	 * @param array $superTocArray
	 * @return array
	 */
	private static function generateSuperPageTocList( Title $childTitle, ?string $heading, array $superTocArray ): array
	{
		$topicFound = false;

		// Find a superpage, if exists
		$parent = self::findSuperpage( $childTitle );
		if ( !$parent ) {
			// No parents, return whatever list was given as a parameter
			return $superTocArray;
		}

		// Get parent body
		$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $parent );
		$superPageText = ContentHandler::getContentText( $wikiPage->getContent() );
		// Strip HTML comments so they are not turned into TOC entries
		$superPageText = preg_replace( '/<!--.*?-->/s', '', $superPageText );
		self::$mHeading = self::findHeadingTextFromContent( $superPageText );

		// For each line in toc:, look for bullets with links; bullets can be multi-level
		$results = [];
		foreach ( explode( PHP_EOL, $superPageText ) as $line ) {
			// Link, with a bullet
			if ( preg_match( "/^(\*+)\s*\[\[\s*([^|]+)\s*(?:\|\s*([^\]]*))?\]\]/", $line, $matches ) == 1 ) {
				$asterisks = $matches[1];
				$level = strlen( $asterisks );
				$url = trim( $matches[2] );
				if ( self::$mPageLangCode != self::$mContLangCode ) {
					$url .= "/" . self::$mPageLangCode;
				}
				$title = ( count( $matches ) > 3 ) ? $matches[3] : $url;
				// TOC item matching with child
				$parentTitleText = $childTitle->getDBKey();
				$len = strlen( $parentTitleText );
				if ( strtolower( $url ) == strtolower( $parentTitleText ) ||
					( ( strtolower( substr( $url, 0, $len ) ) == strtolower( $parentTitleText ) ) &&
						( substr( $url, $len, 1 ) == '/' || substr( $url, $len, 1 ) == '#' ) ) ) {
					// Incorporate param array here, add current level to each item, set bool flag
					$results[] = [ 'level' => $level, 'title' => $title, 'link' => $url, 'is_open' => true ];
					foreach ( $superTocArray as $item ) {
						$item['level'] += $level;
						$results[] = $item;
					}
					$topicFound = true;
				} else {
					$results[] = [ 'level' => $level, 'title' => $title, 'link' => $url ];
				}
				// Heading and markup - ignore
			} elseif ( preg_match( "/(=.+?=|<.+>)/", $line ) ) {
				// Ignore headings and markup
			} elseif ( strlen( trim( $line ) ) > 0 ) {
				$results[] = [ 'level' => 0, 'title' => $line, 'link' => null ];
			}
		}
		if ( !$topicFound ) {
			// Paste child array to the end of the list
			foreach ( $superTocArray as $item ) {
				$results[] = $item;
			}
		}
		// Recurse parent ancestor tocs, if any
		return self::generateSuperPageTocList( $parent, $heading, $results );
	}

	/**
	 * Find the superpage (parent page) for a given title
	 * Shared with wikibook-breadcrumbs extension
	 *
	 * @param Title $title
	 * @return Title|null
	 */
	public static function findSuperpage( Title $title ): ?Title {
		if ( !$title->isSubpage() ) {
			return null;
		}
		// Find a superpage, if exists
		$doc = $title->getFullText();
		$pageLangCode = $title->getPageLanguage()->getCode();
		// Strip lang superpage suffix
		$langsuffix = "";
		$langsuffixLen = strlen( $pageLangCode ) + 1;
		if ( substr( $doc, -$langsuffixLen ) === ( '/' . $pageLangCode ) ) {
			$doc = substr( $doc, 0, -$langsuffixLen );
			$langsuffix = '/' . $pageLangCode;
		}
		// Find a parent
		$separatorPos = strrpos( $doc, '/' );
		if ( $separatorPos === false || $separatorPos < 1 ) {
			// Calling not from a subpage
			return null;
		}
		$superpageDoc = substr( $doc, 0, $separatorPos );
		// See if there is a language-specific version of the superpage (only if page had the suffix)
		if ( $langsuffix ) {
			$linkLangTitle = Title::newFromText( $superpageDoc . $langsuffix );
			if ( $linkLangTitle && $linkLangTitle->exists() ) {
				$superpageDoc .= $langsuffix;
			}
		}
		// Get the superpage
		return Title::newFromText( $superpageDoc );
	}

	/**
	 * Find heading text from a title
	 *
	 * @param Title $title
	 * @return string|null
	 */
	public static function findHeadingTextFromTitle( Title $title ): ?string {
		$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$text = ContentHandler::getContentText( $wikiPage->getContent() );
		if ( $text ) {
			return self::findHeadingTextFromContent( $text );
		}
		return null;
	}

	/**
	 * Find heading text from content
	 *
	 * @param string $text
	 * @return string|null
	 */
	public static function findHeadingTextFromContent( string $text ): ?string {
		if ( preg_match( "/=+([^=]+)=+/", $text, $matches ) ) {
			return trim( $matches[1] );
		}
		return null;
	}

    /**
     * Generate a table of contents from a section tree.
     *
     * @param ?TOCData $tocData Return value of ParserOutput::getSections()
     * @param Language|null $lang Language for the toc title, defaults to user language
     * @param array $options
     *   - 'maxtoclevel' Max TOC level to generate
     * @return string HTML fragment
     */
    public static function generateTOCHtml(?TOCData $tocData, ?Language $lang = null, array $options = []): string
    {
        $toc = '';
        $lastLevel = 0;
        $maxTocLevel = $options['maxtoclevel'] ?? null;
        if ($maxTocLevel === null) {
            // Use wiki-configured default
            $services = MediaWikiServices::getInstance();
            $config = $services->getMainConfig();
            $maxTocLevel = $config->get(MainConfigNames::MaxTocLevel);
        }
        foreach (($tocData ? $tocData->getSections() : []) as $section) {
            $tocLevel = $section->tocLevel;
            if ($tocLevel < $maxTocLevel) {
                if ($tocLevel > $lastLevel) {
                    $toc .= "\n<ul>\n";
                } elseif ($tocLevel < $lastLevel) {
                    if ($lastLevel < $maxTocLevel) {
                        $toc .= self::tocUnindent(
                            $lastLevel - $tocLevel);
                    } else {
                        $toc .= self::tocLineEnd();
                    }
                } else {
                    $toc .= self::tocLineEnd();
                }

                $toc .= self::tocLine($section->linkAnchor,
                    $section->line, $section->number,
                    $tocLevel, $section->index);
                $lastLevel = $tocLevel;
            }
        }
        if ($lastLevel < $maxTocLevel && $lastLevel > 0) {
            $toc .= self::tocUnindent($lastLevel - 1);
        }
        return self::tocList($toc, $lang);
    }

	/**
	 * Add another level to the Table of Contents
	 *
	 * @return string
	 */
	private static function tocIndent(): string {
		return "\n<ul>\n";
	}

	/**
	 * Finish one or more sublevels on the Table of Contents
	 *
	 * @param int $level
	 * @return string
	 */
	private static function tocUnindent( int $level ): string {
		return "</li>\n" . str_repeat( "</ul>\n</li>\n", $level > 0 ? $level : 0 );
	}

	/**
	 * End a TOC line
	 *
	 * @return string
	 */
	private static function tocLineEnd(): string {
		return "</li>\n";
	}

	/**
	 * Generate a TOC line
	 * Parameter level defines if we are on an indentation level
	 *
	 * @param string $linkAnchor Identifier
	 * @param string $tocline Properly escaped HTML
	 * @param string $tocnumber Unescaped text
	 * @param int $level
	 * @param string|false $sectionIndex
	 * @return string
	 */
	private static function tocLine( string $linkAnchor, string $tocline, string $tocnumber, int $level, $sectionIndex = false ): string
	{
		$classes = "toclevel-$level";

		// Parser.php used to suppress tocLine by setting $sectionIndex to false.
		// In those circumstances, we can now encounter '' or a "T-" prefixed index
		// for when the section comes from templates.
		if ( $sectionIndex !== false && $sectionIndex !== '' && !str_starts_with( $sectionIndex, "T-" ) ) {
			$classes .= " tocsection-$sectionIndex";
		}

		// <li class="$classes"><a href="#$linkAnchor"><span class="tocnumber">
		// $tocnumber</span> <span class="toctext">$tocline</span></a>
		return Html::openElement( 'li', [ 'class' => $classes ] )
			. Html::rawElement( 'a',
				[ 'href' => "#$linkAnchor" ],
				Html::element( 'span', [ 'class' => 'tocnumber' ], $tocnumber )
				. ' '
				. Html::rawElement( 'span', [ 'class' => 'toctext' ], $tocline )
			);
	}

	/**
	 * Wraps the TOC in a div with ARIA navigation role and provides the hide/collapse JavaScript.
	 *
	 * @param string $toc Html of the Table Of Contents
	 * @param Language|null $lang Language for the toc title, defaults to user language
	 * @return string Full html of the TOC
	 */
	private static function tocList( string $toc, ?Language $lang = null ): string
	{
		$lang ??= RequestContext::getMain()->getLanguage();

		$title = wfMessage( 'toc' )->inLanguage( $lang )->escaped();

		return '<div id="toc" class="toc" role="navigation" aria-labelledby="mw-toc-heading">'
			. Html::element( 'input', [
				'type' => 'checkbox',
				'role' => 'button',
				'id' => 'toctogglecheckbox',
				'class' => 'toctogglecheckbox',
				'style' => 'display:none',
			] )
			. Html::openElement( 'div', [
				'class' => 'toctitle',
				'lang' => $lang->getHtmlCode(),
				'dir' => $lang->getDir(),
			] )
			. '<h2 id="mw-toc-heading">' . $title . '</h2>'
			. '<span class="toctogglespan">'
			. Html::label( '', 'toctogglecheckbox', [
				'class' => 'toctogglelabel',
			] )
			. '</span>'
			. '</div>'
			. $toc
			. "</ul>\n</div>\n";
	}

	/**
	 * TOC building method has changed in recent MW versions
	 * Method is taken from MediaWiki's HandleTOCMarkers class, for compatibility with this BP extension
	 *
	 * Generate a table of contents from a section tree.
	 *
	 * @param ?TOCData $tocData Return value of ParserOutput::getTOCData()
	 * @param Language|null $lang Language for the toc title, defaults to user language
	 * @param array $options
	 *   - 'maxtoclevel' Max TOC level to generate
	 * @return string HTML fragment
	 */
	private static function generateTOC( ?TOCData $tocData, ?Language $lang = null, array $options = [] ): string
	{
		$toc = '';
		$lastLevel = 0;
		$maxTocLevel = $options['maxtoclevel'] ?? null;
		if ( $maxTocLevel === null ) {
			// Use wiki-configured default
			$services = MediaWikiServices::getInstance();
			$config = $services->getMainConfig();
			$maxTocLevel = $config->get( MainConfigNames::MaxTocLevel );
		}
		foreach ( ( $tocData ? $tocData->getSections() : [] ) as $section ) {
			$tocLevel = $section->tocLevel;
			if ( $tocLevel < $maxTocLevel ) {
				if ( $tocLevel > $lastLevel ) {
					$toc .= self::tocIndent();
				} elseif ( $tocLevel < $lastLevel ) {
					if ( $lastLevel < $maxTocLevel ) {
						$toc .= self::tocUnindent( $lastLevel - $tocLevel );
					} else {
						$toc .= self::tocLineEnd();
					}
				} else {
					$toc .= self::tocLineEnd();
				}

				$toc .= self::tocLine( $section->linkAnchor,
					$section->line, $section->number,
					$tocLevel, $section->index );
				$lastLevel = $tocLevel;
			}
		}
		if ( $lastLevel < $maxTocLevel && $lastLevel > 0 ) {
			$toc .= self::tocUnindent( $lastLevel - 1 );
		}
		return self::tocList( $toc, $lang );
	}
}

