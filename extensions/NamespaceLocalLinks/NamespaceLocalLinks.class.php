<?php

/*
 * Copyright 2018 Sergey Menshikov
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\StripState;
use MediaWiki\Request\WebRequest;
use Title;

/**
 * Extension that makes links in custom namespaces resolve relative to the
 * current namespace (namespace-local links).
 */
class NamespaceLocalLinks {

	/**
	 * Resolve namespace-local wikilinks in parser output (before strip).
	 *
	 * @param \Parser $parser Parser
	 * @param string &$text Page text being parsed
	 * @param StripState &$stripState Strip state
	 * @return bool True to continue hook processing
	 */
	public static function onParserBeforeStrip( &$parser, &$text, &$stripState ): bool {
		$title = $parser->getTitle();
		if ( !$title ) {
			return true;
		}

		$extraNamespaces = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::ExtraNamespaces );
		if ( !is_array( $extraNamespaces ) || !array_key_exists( $title->getNamespace(), $extraNamespaces ) ) {
			return true;
		}

		$contentLanguage = MediaWikiServices::getInstance()->getContentLanguage()->getCode();
		$pageLang = $title->getPageLanguage()->getCode();
		$langSuffix = ( $pageLang !== $contentLanguage ) ? ( '/' . $pageLang ) : false;

		if (
			!preg_match_all(
				'/\[\[([A-Za-z0-9,.\/_ \(\)-]+)(\#[A-Za-z0-9 ._-]*)?([|](.*?))?\]\]/',
				$text,
				$matches,
				PREG_SET_ORDER
			)
		) {
			return true;
		}

		foreach ( $matches as $match ) {
			$linkDoc = trim( $title->getSubjectNsText() . ':' . $match[1] );
			if ( $langSuffix ) {
				$linkLangTitle = Title::newFromText( $linkDoc . $langSuffix );
				if ( $linkLangTitle && $linkLangTitle->exists() ) {
					$linkDoc .= $langSuffix;
				}
			}
			$fragment = $match[2] ?? '';
			$pipeAndLabel = $match[3] ?? '';
			$text = str_replace( $match[0], '[[' . $linkDoc . $fragment . $pipeAndLabel . ']]', $text );
		}

		return true;
	}

	/**
	 * Resolve namespace-local redirect targets.
	 *
	 * @param Title $title Title of the redirect page
	 * @param WebRequest $request Request
	 * @param bool &$ignoreRedirect Whether to ignore the redirect
	 * @param Title|null &$target Redirect target (output)
	 * @param \Article $article Article being viewed
	 * @return void
	 */
	public static function onInitializeArticleMaybeRedirect(
		$title,
		$request,
		&$ignoreRedirect,
		&$target,
		$article
	): void {
		$content = $article->getPage()->getContent();
		if ( !$content || !$content instanceof \TextContent ) {
			return;
		}

		$text = $content->getText();
		if ( !preg_match( '/^#REDIRECT\s+\[\[\s*(.*)\s*\]\]/', $text, $matches ) ) {
			return;
		}

		$link = $matches[1];
		if ( str_contains( $link, ':' ) ) {
			return;
		}

		$languageCode = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::LanguageCode );
		$pageLang = $title->getPageLanguage()->getCode();
		$langSuffix = ( $pageLang !== $languageCode ) ? ( '/' . $pageLang ) : false;

		$linkDoc = trim( $title->getSubjectNsText() . ':' . $link );
		if ( $langSuffix ) {
			$linkLangTitle = Title::newFromText( $linkDoc . $langSuffix );
			if ( $linkLangTitle && $linkLangTitle->exists() ) {
				$target = $linkLangTitle;
				return;
			}
		}
		$target = Title::newFromText( $linkDoc );
	}

	/**
	 * Hook handler for HtmlPageLinkRendererBegin.
	 *
	 * @param \MediaWiki\Linker\LinkRenderer $linkRenderer Link renderer
	 * @param Title $target Link target
	 * @param string &$text Link text (optional output)
	 * @param array &$extraAttribs Extra attributes (optional output)
	 * @param array &$query Query parameters (optional output)
	 * @param string &$ret Return value (optional output)
	 * @return void
	 */
	public static function onHtmlPageLinkRendererBegin(
		$linkRenderer,
		$target,
		&$text,
		&$extraAttribs,
		&$query,
		&$ret
	): void {
		// TODO: "unbreak" namespace-local links
	}
}
