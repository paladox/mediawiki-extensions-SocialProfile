<?php
/**
 * A special page for removing existing friends/foes for the current logged in user
 *
 * Example URL: /index.php?title=Special:RemoveRelationship&user=Awrigh01
 *
 * @file
 * @ingroup Extensions
 * @author David Pean <david.pean@gmail.com>
 * @copyright Copyright © 2007, Wikia Inc.
 * @license GPL-2.0-or-later
 */

class SpecialRemoveRelationship extends UnlistedSpecialPage {

	/**
	 * @var string $user_name_to Name of the user who we are unfriending/unfoeing
	 */
	public $user_name_to;

	/**
	 * @var int $user_id_to User ID of the aforementioned person
	 */
	public $user_id_to;

	/**
	 * @var int $relationship_type 1 for friending, any other number for foeing
	 */
	public $relationship_type;

	public function __construct() {
		parent::__construct( 'RemoveRelationship' );
	}

	/**
	 * Group this special page under the correct header in Special:SpecialPages.
	 *
	 * @return string
	 */
	function getGroupName() {
		return 'users';
	}

	/**
	 * Show the special page
	 *
	 * @param string|null $params
	 */
	public function execute( $params ) {
		$out = $this->getOutput();
		$user = $this->getUser();

		// Can't use $this->setHeaders(); here because then it'll set the page
		// title to <removerelationship> and we don't want that, we'll be
		// messing with the page title later on in the code
		$out->setArticleRelated( false );
		$out->setRobotPolicy( 'noindex,nofollow' );

		// Add CSS
		$out->addModuleStyles( [
			'ext.socialprofile.userrelationship.css'
		] );
		$usertitle = Title::makeTitleSafe( NS_USER, $this->getRequest()->getVal( 'user' ) );
		if ( !$usertitle ) {
			$out->setPageTitle( $this->msg( 'ur-error-title' )->plain() );
			$out->addWikiMsg( 'ur-add-no-user' );
			return false;
		}

		$this->user_name_to = $usertitle->getText();
		$this->user_id_to = User::idFromName( $this->user_name_to );
		$this->relationship_type = UserRelationship::getUserRelationshipByID(
			$this->user_id_to,
			$user->getId()
		);

		if ( $this->relationship_type == 1 ) {
			$confirmTitle = $this->msg( 'ur-remove-relationship-title-confirm-friend', $this->user_name_to )->parse();
			$confirmMsg = $this->msg( 'ur-remove-relationship-message-confirm-friend', $this->user_name_to )->parseAsBlock();
			$error = htmlspecialchars( $this->msg( 'ur-remove-error-not-loggedin-friend' )->plain() );
			$pending = $this->msg( 'ur-remove-error-message-pending-friend-request', $this->user_name_to )->parse();
		} else {
			$confirmTitle = $this->msg( 'ur-remove-relationship-title-confirm-foe', $this->user_name_to )->parse();
			$confirmMsg = $this->msg( 'ur-remove-relationship-message-confirm-foe', $this->user_name_to )->parseAsBlock();
			$error = htmlspecialchars( $this->msg( 'ur-remove-error-not-loggedin-foe' )->plain() );
			$pending = $this->msg( 'ur-remove-error-message-pending-foe-request', $this->user_name_to )->parse();
		}

		$output = '';
		if ( $user->getId() == $this->user_id_to ) {
			$out->setPageTitle( $this->msg( 'ur-error-title' )->plain() );

			$output .= '<div class="relationship-error-message">' .
				htmlspecialchars( $this->msg( 'ur-remove-error-message-remove-yourself' )->plain() ) .
			'</div>
			<div>
				<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'mainpage' )->plain() ) . '" size="20" onclick=\'window.location="index.php?title=' . $this->msg( 'mainpage' )->inContentLanguage()->escaped() . '"\' />';
			if ( $user->isLoggedIn() ) {
				$output .= '<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'ur-your-profile' )->plain() ) . '" size="20" onclick=\'window.location="' . htmlspecialchars( $user->getUserPage()->getFullURL() ) . '"\' />';
			}
			$output .= '</div>';

			$out->addHTML( $output );
		} elseif ( $this->relationship_type == false ) {
			$out->setPageTitle( $this->msg( 'ur-error-title' )->plain() );

			$output = '<div class="relationship-error-message">' .
				$this->msg( 'ur-remove-error-message-no-relationship', $this->user_name_to )->parse() .
			'</div>
			<div>
				<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'mainpage' )->plain() ) . '" size="20" onclick=\'window.location="index.php?title="' . $this->msg( 'mainpage' )->inContentLanguage()->escaped() . '"\' />';
			if ( $user->isLoggedIn() ) {
				$output .= '<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'ur-your-profile' )->plain() ) . '" size="20" onclick=\'window.location="' . htmlspecialchars( $user->getUserPage()->getFullURL() ) . '"\' />';
			}
			$output .= '</div>';

			$out->addHTML( $output );
		} elseif ( UserRelationship::userHasRequestByID( $this->user_id_to, $user->getId() ) == true ) {
			$out->setPageTitle( $this->msg( 'ur-error-title' )->plain() );

			$output = '<div class="relationship-error-message">' .
				$pending .
				'</div>
			<div>
				<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'mainpage' )->plain() ) . '" size="20" onclick=\'window.location="index.php?title="' . $this->msg( 'mainpage' )->inContentLanguage()->escaped() . '"\' />';
			if ( $user->isLoggedIn() ) {
				$output .= '<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'ur-your-profile' )->plain() ) . '" size="20" onclick=\'window.location="' . htmlspecialchars( $user->getUserPage()->getFullURL() ) . '"\' />';
			}
			$output .= '</div>';

			$out->addHTML( $output );
		} elseif ( $user->getId() == 0 ) {
			$out->setPageTitle( $this->msg( 'ur-error-title' )->plain() );
			$output = '<div class="relationship-error-message">' .
				$error .
			'</div>
			<div>
				<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'mainpage' )->plain() ) . '" size="20" onclick=\'window.location="index.php?title="' . $this->msg( 'mainpage' )->inContentLanguage()->escaped() . '"\' />';
			if ( $user->isLoggedIn() ) {
				$output .= '<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'ur-your-profile' )->plain() ) . '" size="20" onclick=\'window.location="' . htmlspecialchars( $user->getUserPage()->getFullURL() ) . '"\' />';
			}
			$output .= '</div>';

			$out->addHTML( $output );
		} else {
			$rel = new UserRelationship( $user->getName() );
			if ( $this->getRequest()->wasPosted() && $_SESSION['alreadysubmitted'] == false ) {
				$_SESSION['alreadysubmitted'] = true;
				$rel->removeRelationshipByUserID(
					$this->user_id_to,
					$user->getId()
				);
				$rel->sendRelationshipRemoveEmail(
					$this->user_id_to,
					$user->getName(),
					$this->relationship_type
				);
				$avatar = new wAvatar( $this->user_id_to, 'l' );

				$out->setPageTitle( $confirmTitle );

				$output = "<div class=\"relationship-action\">
					{$avatar->getAvatarURL()}" .
					$confirmMsg .
					"<div class=\"relationship-buttons\">
						<input type=\"button\" class=\"site-button\" value=\"" . htmlspecialchars( $this->msg( 'mainpage' )->plain() ) . "\" size=\"20\" onclick=\"window.location='index.php?title=" . $this->msg( 'mainpage' )->inContentLanguage()->escaped() . "'\"/>
						<input type=\"button\" class=\"site-button\" value=\"" . htmlspecialchars( $this->msg( 'ur-your-profile' )->plain() ) . "\" size=\"20\" onclick=\"window.location='" . htmlspecialchars( $user->getUserPage()->getFullURL() ) . "'\"/>
					</div>
					<div class=\"visualClear\"></div>
					</div>";

				$out->addHTML( $output );
			} else {
				$_SESSION['alreadysubmitted'] = false;
				$out->addHTML( $this->displayForm() );
			}

		}
	}

	/**
	 * Displays the form for removing a friend or a foe
	 *
	 * @return string HTML code for the form
	 */
	function displayForm() {
		$avatar = new wAvatar( $this->user_id_to, 'l' );

		if ( $this->relationship_type == 1 ) {
			$title = $this->msg(
				'ur-remove-relationship-title-friend',
				$this->user_name_to
			)->parse();
			$remove = $this->msg(
				'ur-remove-relationship-message-friend',
				$this->user_name_to,
				$this->msg( 'ur-remove' )->plain()
			)->parseAsBlock();
		} else {
			$title = $this->msg(
				'ur-remove-relationship-title-foe',
				$this->user_name_to
			)->parse();
			$remove = $this->msg(
				'ur-remove-relationship-message-foe',
				$this->user_name_to,
				$this->msg( 'ur-remove' )->plain()
			)->parseAsBlock();
		}

		$this->getOutput()->setPageTitle( $title );

		$form = "<form action=\"\" method=\"post\" enctype=\"multipart/form-data\" name=\"form1\">
			<div class=\"relationship-action\">
			{$avatar->getAvatarURL()}" .
			$remove .
			'<div class="relationship-buttons">
				<input type="hidden" name="user" value="' . htmlspecialchars( $this->user_name_to ) . '" />
				<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'ur-remove' )->plain() ) . '" size="20" onclick="document.form1.submit()" />
				<input type="button" class="site-button" value="' . htmlspecialchars( $this->msg( 'cancel' )->plain() ) . '" size="20" onclick="history.go(-1)" />
			</div>
			<div class="visualClear"></div>
			</div>

		</form>';

		return $form;
	}
}
