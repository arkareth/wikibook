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

use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use Parser;
use PPFrame;
use Title;

/**
 * Extension that provides a parser tag to list article namespaces (other
 * namespaces containing a page with the same base title).
 */
class ListArticleNamespaces {

	/**
	 * Register the list-article-namespaces parser tag.
	 *
	 * @param Parser $parser Parser
	 * @return void
	 */
	public static function onParserSetup( Parser $parser ): void {
		$parser->setHook( 'list-article-namespaces', [ self::class, 'renderTag' ] );
	}

	/**
	 * Render the list-article-namespaces tag: list namespaces that contain
	 * a page with the same base title as the current page.
	 *
	 * @param string|null $input Tag content (unused)
	 * @param array $args Tag arguments (unused)
	 * @param Parser $parser Parser
	 * @param PPFrame $frame Frame
	 * @return string HTML
	 */
	public static function renderTag( $input, array $args, Parser $parser, PPFrame $frame ): string {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$extraNamespaces = $config->get( MainConfigNames::ExtraNamespaces );
		if ( !is_array( $extraNamespaces ) ) {
			return '<div class="list-article-namespaces"></div>';
		}

		$user = RequestContext::getMain()->getUser();
		$loggedIn = $user->isSafeToLoad() && $user->isRegistered();
		$whitelistReadRegexp = $config->get( MainConfigNames::WhitelistReadRegexp ) ?? [];

		$title = $parser->getTitle();
		$titleText = $title->getText();
		$nsText = $title->getNsText();

		$nslist = [];
		foreach ( $extraNamespaces as $index => $namespaceName ) {
			if ( $index & 1 ) {
				continue;
			}
			$nslist[] = $namespaceName;
		}
		usort( $nslist, 'strcasecmp' );

		$output = '<div class="list-article-namespaces">';
		foreach ( $nslist as $namespaceName ) {
			$possibleTitle = Title::newFromText( $namespaceName . ':' . $titleText );
			if ( !$possibleTitle || !$possibleTitle->exists() ) {
				continue;
			}

			$url = $possibleTitle->getFullURL();

			if ( !$loggedIn ) {
				$fullText = $possibleTitle->getFullText();
				$whitelisted = false;
				foreach ( $whitelistReadRegexp as $listItem ) {
					if ( preg_match( $listItem, $fullText ) ) {
						$whitelisted = true;
						break;
					}
				}
				if ( !$whitelisted ) {
					continue;
				}
			}

			if ( $namespaceName === $nsText ) {
				$output .= '&bull;&nbsp;<span class="list-article-namespaces-item selected">' .
					htmlspecialchars( $namespaceName ) . '</span> ';
			} else {
				$output .= '&bull;&nbsp;<span class="list-article-namespaces-item"><a href="' .
					htmlspecialchars( $url ) . '">' . htmlspecialchars( $namespaceName ) . '</a></span> ';
			}
		}
		$output .= '</div>';

		return $output;
	}
}
