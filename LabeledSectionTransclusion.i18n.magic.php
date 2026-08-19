<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

/**
 * `lst_tag`, `lst_attr_begin` and `lst_attr_end` are not invocable magic words:
 * they are never registered as a variable or a parser function. They exist only
 * so that the localised spellings of the <section> tag and of its begin/end
 * attributes are stored where MediaWiki already merges synonyms down the
 * language fallback chain (LocalisationCache::mergeMagicWords()). A wiki whose
 * content language has no translation of its own therefore inherits its
 * fallback's spellings for free. Core uses the same pattern for image
 * parameters (img_thumbnail, img_right, ...), which are likewise keywords
 * inside a construct rather than {{...}} invocations.
 *
 * Keep every id present in the 'en' block: MagicWordFactory::get() throws
 * UnexpectedValueException for an id that no language in the chain defines, and
 * 'en' terminates every chain.
 */

$magicWords = [];

$magicWords['en'] = [
	'lst' => [ 0, 'lst', 'section' ],
	'lstx' => [ 0, 'lstx', 'section-x' ],
	'lsth' => [ 0, 'lsth', 'section-h' ],
	'lstall' => [ 0, 'lstall', 'section-all' ],
	'lst_tag' => [ 0, 'section' ],
	'lst_attr_begin' => [ 0, 'begin' ],
	'lst_attr_end' => [ 0, 'end' ],
];

$magicWords['de'] = [
	'lst' => [ 0, 'lst', 'section', 'Abschnitt' ],
	'lstx' => [ 0, 'lstx', 'section-x', 'Abschnitt-x' ],
	'lst_tag' => [ 0, 'Abschnitt' ],
	'lst_attr_begin' => [ 0, 'Anfang' ],
	'lst_attr_end' => [ 0, 'Ende' ],
];

$magicWords['he'] = [
	'lst' => [ 0, 'lst', 'section', 'קטע' ],
	'lstx' => [ 0, 'lstx', 'section-x', 'בלי קטע' ],
	'lst_tag' => [ 0, 'קטע' ],
	'lst_attr_begin' => [ 0, 'התחלה' ],
	'lst_attr_end' => [ 0, 'סוף' ],
];

$magicWords['pt'] = [
	'lst' => [ 0, 'lst', 'section', 'trecho' ],
	'lstx' => [ 0, 'lstx', 'section-x', 'trecho-x' ],
	'lst_tag' => [ 0, 'trecho' ],
	'lst_attr_begin' => [ 0, 'começo' ],
	'lst_attr_end' => [ 0, 'fim' ],
];
