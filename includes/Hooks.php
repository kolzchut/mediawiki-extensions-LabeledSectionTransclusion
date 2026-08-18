<?php

namespace MediaWiki\Extension\LabeledSectionTransclusion;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Parser\Parser;

class Hooks implements ParserFirstCallInitHook {

	/**
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		// MediaWiki supports localisation for the three kinds of magic words,
		// such as variable {{NAME}}, behaviours __NAME__, and parser functions
		// {{#name}}, but it does not support localisation of tag hooks, such
		// as <name>. Work around that limitation by performing the localisation
		// at run-time when calling Parser::setHook(), reading the names from the
		// lst_tag magic word so that they follow the wiki's language fallback
		// chain. The section markers aren't paired, so every spelling is just a
		// noop; registering all of them lets a page mix them freely.
		$tagNames = LabeledSectionTransclusion::getNames( $parser, LabeledSectionTransclusion::MW_TAG );
		foreach ( $tagNames as $tagName ) {
			$parser->setHook( $tagName, [ LabeledSectionTransclusion::class, 'noop' ] );
		}
		$parser->setFunctionHook(
			'lst', [ LabeledSectionTransclusion::class, 'pfuncIncludeObj' ], Parser::SFH_OBJECT_ARGS
		);
		$parser->setFunctionHook(
			'lstx', [ LabeledSectionTransclusion::class, 'pfuncExcludeObj' ], Parser::SFH_OBJECT_ARGS
		);
		$parser->setFunctionHook( 'lsth', [ LabeledSectionTransclusion::class, 'pfuncIncludeHeading' ] );
		$parser->setFunctionHook(
			'lstall', [ LabeledSectionTransclusion::class, 'pfuncIncludeAny' ], Parser::SFH_OBJECT_ARGS
		);
	}

}
