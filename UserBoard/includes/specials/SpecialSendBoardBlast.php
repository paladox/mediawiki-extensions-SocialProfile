<?php
/**
 * A special page to allow users to send a mass board message by selecting from
 * a list of their friends and foes
 *
 * @file
 * @ingroup Extensions
 * @author David Pean <david.pean@gmail.com>
 * @copyright Copyright © 2007, Wikia Inc.
 * @license GPL-2.0-or-later
 */

class SpecialBoardBlast extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'SendBoardBlast' );
	}

	/**
	 * Show the special page
	 *
	 * @param string|null $params
	 */
	public function execute( $params ) {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		// This feature is available only to logged-in users.
		$this->requireLogin();

		// Is the database locked?
		$this->checkReadOnly();

		// Blocked through Special:Block? No access for you!
		if ( $user->isBlocked() ) {
			throw new UserBlockedError( $user->getBlock() );
		}

		// Set the page title, robot policies, etc.
		$this->setHeaders();

		// Add CSS & JS
		$out->addModuleStyles( [
			'ext.socialprofile.userboard.boardblast.css'
		] );
		$out->addModules( 'ext.socialprofile.userboard.boardblast.js' );

		$output = '';

		if ( $request->wasPosted() ) {
			$out->setPageTitle( $this->msg( 'messagesenttitle' )->plain() );
			$b = new UserBoard();

			$count = 0;
			$user_ids_to = explode( ',', $request->getVal( 'ids' ) );
			foreach ( $user_ids_to as $user_id ) {
				$recipient = User::newFromId( $user_id );
				$recipient->loadFromId();
				$user_name = $recipient->getName();
				$b->sendBoardMessage(
					$user->getId(),
					$user->getName(),
					$user_id,
					$user_name,
					$request->getVal( 'message' ),
					1
				);
				$count++;
			}
			$output .= htmlspecialchars( $this->msg( 'messagesentsuccess' )->plain() );
		} else {
			$out->setPageTitle( $this->msg( 'boardblasttitle' )->plain() );
			$output .= $this->displayForm();
		}

		$out->addHTML( $output );
	}

	/**
	 * Displays the form for sending board blasts
	 *
	 * @return string HTML
	 */
	function displayForm() {
		$user = $this->getUser();

		$stats = new UserStats( $user->getId(), $user->getName() );
		$stats_data = $stats->getUserStats();
		$friendCount = $stats_data['friend_count'];
		$foeCount = $stats_data['foe_count'];

		$output = '<div class="board-blast-message-form">
				<h2>' . $this->msg( 'boardblaststep1' )->escaped() . '</h2>
				<form method="post" name="blast" action="">
					<input type="hidden" name="ids" id="ids" />
					<div class="blast-message-text">'
						. $this->msg( 'boardblastprivatenote' )->escaped() .
					'</div>
					<textarea name="message" id="message" cols="63" rows="4"></textarea>
				</form>
		</div>
		<div class="blast-nav">
				<h2>' . $this->msg( 'boardblaststep2' )->escaped() . '</h2>
				<div class="blast-nav-links">
					<a href="javascript:void(0);" class="blast-select-all-link">' .
						$this->msg( 'boardlinkselectall' )->escaped() . '</a> -
					<a href="javascript:void(0);" class="blast-unselect-all-link">' .
						$this->msg( 'boardlinkunselectall' )->escaped() . '</a> ';

		if ( $friendCount > 0 && $foeCount > 0 ) {
			$output .= '- <a href="javascript:void(0);" class="blast-select-friends-link">' .
				$this->msg( 'boardlinkselectfriends' )->escaped() . '</a> -';
			$output .= '<a href="javascript:void(0);" class="blast-unselect-friends-link">' .
				$this->msg( 'boardlinkunselectfriends' )->escaped() . '</a>';
		}

		if ( $foeCount > 0 && $friendCount > 0 ) {
			$output .= '- <a href="javascript:void(0);" class="blast-select-foes-link">' .
				$this->msg( 'boardlinkselectfoes' )->escaped() . '</a> -';
			$output .= '<a href="javascript:void(0);" class="blast-unselect-foes-link">' .
				$this->msg( 'boardlinkunselectfoes' )->escaped() . '</a>';
		}
		$output .= '</div>
		</div>';

		$listLookup = new RelationshipListLookup( $user );
		$relationships = $listLookup->getRelationshipList();

		$output .= '<div id="blast-friends-list" class="blast-friends-list">';

		$x = 1;
		$per_row = 3;
		if ( count( $relationships ) > 0 ) {
			foreach ( $relationships as $relationship ) {
				if ( $relationship['type'] == 1 ) {
					$class = 'friend';
				} else {
					$class = 'foe';
				}
				$id = $relationship['user_id'];
				$output .= '<div class="blast-' . $class . "-unselected\" id=\"user-{$id}\">
						" . htmlspecialchars( $relationship['user_name'] ) . "
					</div>";
				if ( $x == count( $relationships ) || $x != 1 && $x % $per_row == 0 ) {
					$output .= '<div class="visualClear"></div>';
				}
				$x++;
			}
		} else {
			$output .= '<div>' . $this->msg( 'boardnofriends' )->escaped() . '</div>';
		}

		$output .= '</div>

			<div class="visualClear"></div>';

		$output .= '<div class="blast-message-box-button">
			<input type="button" value="' . $this->msg( 'boardsendbutton' )->escaped() . '" class="site-button" />
		</div>';

		return $output;
	}
}
