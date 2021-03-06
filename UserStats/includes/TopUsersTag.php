<?php
/**
 * A parser hook that allows showing up to 50 weekly or monthly top users.
 *
 * Usage: <topusers limit=15 period=monthly />
 *
 * @file
 * @ingroup Extensions
 * @date 19 August 2013
 * @author Jack Phoenix
 */
if ( !defined( 'MEDIAWIKI' ) ) {
	die();
}

$wgHooks['ParserFirstCallInit'][] = 'wfRegisterTopUsersTag';

/**
 * Register the new <topusers /> parser hook with the Parser.
 *
 * @param Parser $parser
 * @return bool true
 */
function wfRegisterTopUsersTag( &$parser ) {
	$parser->setHook( 'topusers', 'getTopUsersForTag' );
	return true;
}

/**
 * Get the given amount of top users for the given timeframe.
 *
 * @return string HTML
 */
function getTopUsersForTag( $input, $args, $parser ) {
	// Don't allow showing OVER 9000...I mean, over 50 users, duh.
	// Performance and all that stuff.
	if (
		!empty( $args['limit'] ) &&
		is_numeric( $args['limit'] ) &&
		$args['limit'] < 50
	) {
		$limit = intval( $args['limit'] );
	} else {
		$limit = 5;
	}

	if ( !empty( $args['period'] ) && strtolower( $args['period'] ) == 'monthly' ) {
		$period = 'monthly';
	} else {
		// "period" argument not supplied/it's not "monthly", so assume weekly
		$period = 'weekly';
	}

	$lookup = new TopUsersListLookup( $limit );
	$fans = $lookup->getListByTimePeriod( $period );
	$x = 1;
	$topfans = '';

	$linkRenderer = MediaWiki\MediaWikiServices::getInstance()->getLinkRenderer();
	foreach ( $fans as $fan ) {
		$avatar = new wAvatar( $fan['user_id'], 'm' );
		$user = Title::makeTitle( NS_USER, $fan['user_name'] );

		$userLink = $linkRenderer->makeLink(
			$user,
			$fan['user_name']
		);
		$safeUserURL = htmlspecialchars( $user->getFullURL() );
		$topfans .= "<div class=\"top-fan\">
				<span class=\"top-fan-number\">{$x}.</span>
				<a href=\"{$safeUserURL}\">{$avatar->getAvatarURL()}</a>
				<span class=\"top-fans-user\">{$userLink}</span>
				<span class=\"top-fans-points\">" .
				wfMessage( 'top-fans-points-tag' )->numParams( $fan['points'] )->parse() . '</span>
			</div>';
		$x++;
	}

	return $topfans;
}
