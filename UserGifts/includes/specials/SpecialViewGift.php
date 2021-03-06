<?php

class ViewGift extends UnlistedSpecialPage {

	public function __construct() {
		parent::__construct( 'ViewGift' );
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
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$user = $this->getUser();

		// Set the page title, robot policies, etc.
		$this->setHeaders();

		// Add CSS
		$out->addModuleStyles( [
			'ext.socialprofile.usergifts.css',
			'ext.socialprofile.special.viewgift.css'
		] );

		$giftId = $this->getRequest()->getInt( 'gift_id' );
		if ( !$giftId || !is_numeric( $giftId ) ) {
			$out->setPageTitle( $this->msg( 'g-error-title' )->plain() );
			$out->addHTML( htmlspecialchars( $this->msg( 'g-error-message-invalid-link' )->plain() ) );
			return false;
		}

		$gift = UserGifts::getUserGift( $giftId );

		if ( $gift ) {
			if ( $gift['status'] == 1 ) {
				if ( $gift['user_name_to'] == $user->getName() ) {
					$g = new UserGifts( $gift['user_name_to'] );
					$g->clearUserGiftStatus( $gift['id'] );
				}
			}

			// DB stuff
			$dbr = wfGetDB( DB_REPLICA );
			$res = $dbr->select(
				'user_gift',
				[ 'DISTINCT ug_user_name_to', 'ug_user_id_to', 'ug_date' ],
				[
					'ug_gift_id' => $gift['gift_id'],
					'ug_user_name_to <> ' . $dbr->addQuotes( $gift['user_name_to'] )
				],
				__METHOD__,
				[
					'GROUP BY' => 'ug_user_name_to',
					'ORDER BY' => 'ug_date DESC',
					'LIMIT' => 6
				]
			);

			$out->setPageTitle( $this->msg(
				'g-description-title',
				$gift['user_name_to'],
				$gift['name']
			)->parse() );

			$output = '<div class="back-links">
				<a href="' . htmlspecialchars( Title::makeTitle( NS_USER, $gift['user_name_to'] )->getFullURL() ) . '">'
				. $this->msg( 'g-back-link', $gift['user_name_to'] )->parse() . '</a>
			</div>';

			$sender = Title::makeTitle( NS_USER, $gift['user_name_from'] );
			$removeGiftLink = SpecialPage::getTitleFor( 'RemoveGift' );
			$giveGiftLink = SpecialPage::getTitleFor( 'GiveGift' );

			$userGiftIcon = new UserGiftIcon( $gift['gift_id'], 'l' );
			$icon = $userGiftIcon->getIconHTML();

			$message = $out->parse( trim( $gift['message'] ), false );

			$output .= '<div class="g-description-container">';
			$output .= '<div class="g-description">' .
					$icon .
					'<div class="g-name">' . htmlspecialchars( $gift['name'] ) . '</div>
					<div class="g-timestamp">(' . $gift['timestamp'] . ')</div>
					<div class="g-from">' . $this->msg( // FIXME: Message with raw HTML
						'g-from',
						htmlspecialchars( $sender->getFullURL() ),
						htmlspecialchars( $gift['user_name_from'] )
					)->text() . '</div>';
			if ( $message ) {
				$output .= '<div class="g-user-message">' . $message . '</div>';
			}
			$output .= '<div class="visualClear"></div>
					<div class="g-describe">' . htmlspecialchars( $gift['description'] ) . '</div>
					<div class="g-actions">
						<a href="' . htmlspecialchars( $giveGiftLink->getFullURL( 'gift_id=' . $gift['gift_id'] ) ) . '">' .
							htmlspecialchars( $this->msg( 'g-to-another' )->plain() ) . '</a>';
			if ( $gift['user_name_to'] == $user->getName() ) {
				$output .= $this->msg( 'pipe-separator' )->escaped();
				$output .= '<a href="' . htmlspecialchars( $removeGiftLink->getFullURL( 'gift_id=' . $gift['id'] ) ) . '">' .
					htmlspecialchars( $this->msg( 'g-remove-gift' )->plain() ) . '</a>';
			}
			$output .= '</div>
				</div>';

			$output .= '<div class="g-recent">
					<div class="g-recent-title">' .
						htmlspecialchars( $this->msg( 'g-recent-recipients' )->plain() ) .
					'</div>
					<div class="g-gift-count">' .
						$this->msg( 'g-given', $gift['gift_count'] )->parse() .
					'</div>';

			foreach ( $res as $row ) {
				$userToId = $row->ug_user_id_to;
				$avatar = new wAvatar( $userToId, 'ml' );
				$userNameLink = Title::makeTitle( NS_USER, $row->ug_user_name_to );

				$output .= '<a href="' . htmlspecialchars( $userNameLink->getFullURL() ) . "\">
					{$avatar->getAvatarURL()}
				</a>";
			}

			$output .= '<div class="visualClear"></div>
				</div>
			</div>';

			$out->addHTML( $output );
		} else {
			$out->setPageTitle( $this->msg( 'g-error-title' )->plain() );
			$out->addHTML( htmlspecialchars( $this->msg( 'g-error-message-invalid-link' )->plain() ) );
		}
	}
}
