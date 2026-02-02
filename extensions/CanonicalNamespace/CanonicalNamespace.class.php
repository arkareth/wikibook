<?php

/*
 * Copyright 2018, 2019 Sergey Menshikov
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
use Title;

/**
 * Extension that sets canonical URLs and redirects based on canonical and
 * latest namespace names.
 */
class CanonicalNamespace {

	/** Draft namespace ID (custom namespace, typically 208) */
	private const DRAFT_NS = 208;

	/**
	 * Redirect missing article to the latest namespace equivalent when the
	 * title matches the canonical namespace prefix.
	 *
	 * @param \Article $article Article being viewed
	 * @return void
	 */
	public static function onBeforeDisplayNoArticleText( \Article $article ): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$canonicalNamespaceName = $config->get( 'CanonicalNamespaceName' );
		$latestNamespaceName = $config->get( 'LatestNamespaceName' );
		if ( !$canonicalNamespaceName || !$latestNamespaceName ) {
			return;
		}

		$prefix = $canonicalNamespaceName . ':';
		$title = $article->getTitle();
		$titleText = $title->getText();
		if ( strcasecmp( substr( $titleText, 0, strlen( $prefix ) ), $prefix ) !== 0 ) {
			return;
		}

		$r_title = Title::newFromText(
			$latestNamespaceName . ':' . substr( $titleText, strlen( $prefix ) )
		);
		if ( !$r_title ) {
			return;
		}

		$article->getContext()->getOutput()->redirect( $r_title->getFullURL(), 302 );
		exit( 0 );
	}

	/**
	 * Set canonical URL for pages in extra namespaces to the latest namespace.
	 *
	 * @param \OutputPage &$out OutputPage
	 * @param \Skin &$skin Skin
	 * @return void
	 */
	public static function onBeforePageDisplay( \OutputPage &$out, \Skin &$skin ): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$extraNamespaces = $config->get( MainConfigNames::ExtraNamespaces );
		if ( !is_array( $extraNamespaces ) ) {
			return;
		}

		$title = $skin->getTitle();
		if ( !array_key_exists( $title->getNamespace(), $extraNamespaces ) ) {
			return;
		}

		$latestNamespaceName = $config->get( 'LatestNamespaceName' );
		if ( !$latestNamespaceName ) {
			return;
		}

		$baseUrl = 'https://help.brightpattern.com/';
		if ( $title->getNamespace() !== self::DRAFT_NS ) {
			$out->setCanonicalUrl( $baseUrl . $latestNamespaceName . ':' . $title->getText() );
		} else {
			$out->setCanonicalUrl( $baseUrl . 'draft:' . $title->getText() );
		}
	}

	/**
	 * Replace wikilinks using the canonical namespace name with the latest
	 * namespace name in parser output. Cache must be purged for changes to apply.
	 *
	 * @param \Parser &$parser Parser
	 * @param string &$text Text being parsed
	 * @return void
	 */
	public static function onInternalParseBeforeLinks( \Parser &$parser, string &$text ): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$latestNamespaceName = $config->get( 'LatestNamespaceName' );
		$canonicalNamespaceName = $config->get( 'CanonicalNamespaceName' );
		if ( !$latestNamespaceName || !$canonicalNamespaceName ) {
			return;
		}

		$pattern = '/\[\[(\s?)' . preg_quote( $canonicalNamespaceName, '/' ) . ':(.+)]]/iU';
		if ( !preg_match_all( $pattern, $text, $matches, PREG_PATTERN_ORDER ) || empty( $matches[0] ) ) {
			return;
		}

		$replaced = [];
		$replacePattern = '/^\[\[(\s?)' . preg_quote( $canonicalNamespaceName, '/' ) . ':/iU';
		$replacement = '[[' . $latestNamespaceName . ':';

		foreach ( $matches[0] as $m ) {
			if ( in_array( $m, $replaced, true ) ) {
				continue;
			}

			$r = preg_replace( $replacePattern, $replacement, $m, -1 );
			if ( $r === false || $r === $m ) {
				$replaced[] = $m;
				continue;
			}

			$text = preg_replace( '/' . preg_quote( $m, '/' ) . '/', $r, $text, -1 );
			$replaced[] = $m;
		}
	}
}
