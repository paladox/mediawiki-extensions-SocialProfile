<?php
/**
 * UserActivity class
 */
class UserActivity {

	/**
	 * All member variables should be considered private
	 * Please use the accessor functions
	 */

	private $user_id;       # Text form (spaces not underscores) of the main part
	private $user_name;		# Text form (spaces not underscores) of the main part
	private $items;         # Text form (spaces not underscores) of the main part
	private $rel_type;
	private $show_current_user = false;
	private $show_edits = 1;
	private $show_votes = 0;
	private $show_comments = 1;
	private $show_relationships = 1;
	private $show_gifts_sent = 0;
	private $show_gifts_rec = 1;
	private $show_system_gifts = 1;
	private $show_system_messages = 1;
	private $show_messages_sent = 1;
	private $show_network_updates = 0;
	private $show_all;
	private $item_max;
	private $now;
	private $three_days_ago;
	private $items_grouped;
	private $displayed;
	private $activityLines;

	/**
	 * @param string $username Username (usually $wgUser's username)
	 * @param string $filter Passed to setFilter(); can be either
	 * 'user', 'friends', 'foes' or 'all', depending on what
	 * kind of information is wanted
	 * @param int $item_max Maximum amount of items to display in the feed
	 */
	public function __construct( $username, $filter, $item_max ) {
		if ( $username ) {
			$title1 = Title::newFromDBkey( $username );
			$this->user_name = $title1->getText();
			$this->user_id = User::idFromName( $this->user_name );
		}
		$this->setFilter( $filter );
		$this->item_max = $item_max;
		$this->now = time();
		$this->three_days_ago = $this->now - ( 60 * 60 * 24 * 3 );
		$this->items_grouped = [];
	}

	private function setFilter( $filter ) {
		if ( strtoupper( $filter ) == 'USER' ) {
			$this->show_current_user = true;
		}
		if ( strtoupper( $filter ) == 'FRIENDS' ) {
			$this->rel_type = 1;
		}
		if ( strtoupper( $filter ) == 'FOES' ) {
			$this->rel_type = 2;
		}
		if ( strtoupper( $filter ) == 'ALL' ) {
			$this->show_all = true;
		}
	}

	/**
	 * Sets the value of class member variable $name to $value.
	 */
	public function setActivityToggle( $name, $value ) {
		$this->$name = $value;
	}

	/**
	 * Get recent edits from the recentchanges table and set them in the
	 * appropriate class member variables.
	 */
	private function setEdits() {
		$dbr = wfGetDB( DB_REPLICA );

		$where = [];

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "rc_user IN ($userIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['rc_user'] = $this->user_id;
		}

		$commentStore = CommentStore::getStore();
		$commentQuery = $commentStore->getJoin( 'rc_comment' );

		$res = $dbr->select(
			[ 'recentchanges' ] + $commentQuery['tables'],
			[
				'rc_timestamp', 'rc_title',
				'rc_user', 'rc_user_text', 'rc_id', 'rc_minor',
				'rc_new', 'rc_namespace', 'rc_cur_id', 'rc_this_oldid',
				'rc_last_oldid', 'rc_log_action'
			] + $commentQuery['fields'],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'rc_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			],
			$commentQuery['joins']
		);

		foreach ( $res as $row ) {
			// Special pages aren't editable, so ignore them
			// And blocking a vandal should not be counted as editing said
			// vandal's user page...
			if ( $row->rc_namespace == NS_SPECIAL || $row->rc_log_action != null ) {
				continue;
			}

			$title = Title::makeTitle( $row->rc_namespace, $row->rc_title );
			$unixTS = wfTimestamp( TS_UNIX, $row->rc_timestamp );

			$this->items_grouped['edit'][$title->getPrefixedText()]['users'][$row->rc_user_text][] = [
				'id' => 0,
				'type' => 'edit',
				'timestamp' => $unixTS,
				'pagetitle' => $row->rc_title,
				'namespace' => $row->rc_namespace,
				'username' => $row->rc_user_text,
				'userid' => $row->rc_user,
				'comment' => $this->fixItemComment( $commentStore->getComment(
					'rc_comment', $row )->text ),
				'minor' => $row->rc_minor,
				'new' => $row->rc_new
			];

			// set last timestamp
			$this->items_grouped['edit'][$title->getPrefixedText()]['timestamp'] = $unixTS;

			$this->items[] = [
				'id' => 0,
				'type' => 'edit',
				'timestamp' => $unixTS,
				'pagetitle' => $row->rc_title,
				'namespace' => $row->rc_namespace,
				'username' => $row->rc_user_text,
				'userid' => $row->rc_user,
				'comment' => $this->fixItemComment( $commentStore->getComment(
					'rc_comment', $row )->text ),
				'minor' => $row->rc_minor,
				'new' => $row->rc_new
			];
		}
	}

	/**
	 * Get recent votes from the Vote table (provided by VoteNY extension) and
	 * set them in the appropriate class member variables.
	 */
	private function setVotes() {
		$dbr = wfGetDB( DB_REPLICA );

		# Bail out if Vote table doesn't exist
		if ( !$dbr->tableExists( 'Vote' ) ) {
			return false;
		}

		$where = [];
		$where[] = 'vote_page_id = page_id';

		if ( $this->rel_type ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "vote_user_id IN ($userIDs)";
			}
		}
		if ( $this->show_current_user ) {
			$where['vote_user_id'] = $this->user_id;
		}

		$res = $dbr->select(
			[ 'Vote', 'page' ],
			[
				'vote_date AS item_date', 'username',
				'page_title', 'vote_count', 'comment_count', 'vote_ip',
				'vote_user_id'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'vote_date DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			]
		);

		foreach ( $res as $row ) {
			$username = $row->username;
			$this->items[] = [
				'id' => 0,
				'type' => 'vote',
				'timestamp' => wfTimestamp( TS_UNIX, $row->vote_date ),
				'pagetitle' => $row->page_title,
				'namespace' => $row->page_namespace,
				'username' => $username,
				'userid' => $row->vote_user_id,
				'comment' => '-',
				'new' => '0',
				'minor' => 0
			];
		}
	}

	/**
	 * Get recent comments from the Comments table (provided by the Comments
	 * extension) and set them in the appropriate class member variables.
	 */
	private function setComments() {
		$dbr = wfGetDB( DB_REPLICA );

		# Bail out if Comments table doesn't exist
		if ( !$dbr->tableExists( 'Comments' ) ) {
			return false;
		}

		$where = [];
		$where[] = 'Comment_Page_ID = page_id';

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "Comment_user_id IN ($userIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['Comment_user_id'] = $this->user_id;
		}

		$res = $dbr->select(
			[ 'Comments', 'page' ],
			[
				'Comment_Date AS item_date',
				'Comment_Username', 'Comment_IP', 'page_title', 'Comment_Text',
				'Comment_user_id', 'page_namespace', 'CommentID'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'comment_date DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			]
		);

		foreach ( $res as $row ) {
			$show_comment = true;

			global $wgFilterComments;
			if ( $wgFilterComments ) {
				if ( $row->vote_count <= 4 ) {
					$show_comment = false;
				}
			}

			if ( $show_comment ) {
				$title = Title::makeTitle( $row->page_namespace, $row->page_title );
				$unixTS = wfTimestamp( TS_UNIX, $row->item_date );
				$this->items_grouped['comment'][$title->getPrefixedText()]['users'][$row->Comment_Username][] = [
					'id' => $row->CommentID,
					'type' => 'comment',
					'timestamp' => $unixTS,
					'pagetitle' => $row->page_title,
					'namespace' => $row->page_namespace,
					'username' => $row->Comment_Username,
					'userid' => $row->Comment_user_id,
					'comment' => $this->fixItemComment( $row->Comment_Text ),
					'minor' => 0,
					'new' => 0
				];

				// set last timestamp
				$this->items_grouped['comment'][$title->getPrefixedText()]['timestamp'] = $unixTS;

				$username = $row->Comment_Username;
				$this->items[] = [
					'id' => $row->CommentID,
					'type' => 'comment',
					'timestamp' => $unixTS,
					'pagetitle' => $row->page_title,
					'namespace' => $row->page_namespace,
					'username' => $username,
					'userid' => $row->Comment_user_id,
					'comment' => $this->fixItemComment( $row->Comment_Text ),
					'new' => '0',
					'minor' => 0
				];
			}
		}
	}

	/**
	 * Get recently sent user-to-user gifts from the user_gift and gift tables
	 * and set them in the appropriate class member variables.
	 */
	private function setGiftsSent() {
		$dbr = wfGetDB( DB_REPLICA );

		$where = [];

		if ( $this->rel_type ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "ug_user_id_to IN ($userIDs)";
			}
		}

		if ( $this->show_current_user ) {
			$where['ug_user_id_from'] = $this->user_id;
		}

		$res = $dbr->select(
			[ 'user_gift', 'gift' ],
			[
				'ug_id', 'ug_user_id_from', 'ug_user_name_from',
				'ug_user_id_to', 'ug_user_name_to',
				'ug_date', 'gift_name', 'gift_id'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'ug_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			],
			[ 'gift' => [ 'INNER JOIN', 'gift_id = ug_gift_id' ] ]
		);

		foreach ( $res as $row ) {
			$this->items[] = [
				'id' => $row->ug_id,
				'type' => 'gift-sent',
				'timestamp' => wfTimestamp( TS_UNIX, $row->ug_date ),
				'pagetitle' => $row->gift_name,
				'namespace' => $row->gift_id,
				'username' => $row->ug_user_name_from,
				'userid' => $row->ug_user_id_from,
				'comment' => $row->ug_user_name_to,
				'new' => '0',
				'minor' => 0
			];
		}
	}

	/**
	 * Get recently received user-to-user gifts from the user_gift and gift
	 * tables and set them in the appropriate class member variables.
	 */
	private function setGiftsRec() {
		$dbr = wfGetDB( DB_REPLICA );

		$where = [];

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "ug_user_id_to IN ($userIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['ug_user_id_to'] = $this->user_id;
		}

		$res = $dbr->select(
			[ 'user_gift', 'gift' ],
			[
				'ug_id', 'ug_user_id_from', 'ug_user_name_from',
				'ug_user_id_to', 'ug_user_name_to',
				'ug_date', 'gift_name', 'gift_id'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'ug_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			],
			[ 'gift' => [ 'INNER JOIN', 'gift_id = ug_gift_id' ] ]
		);

		foreach ( $res as $row ) {
			$user_title = Title::makeTitle( NS_USER, $row->ug_user_name_to );
			$user_title_from = Title::makeTitle( NS_USER, $row->ug_user_name_from );

			$userGiftIcon = new UserGiftIcon( $row->gift_id, 'm' );
			$icon = $userGiftIcon->getIconHTML();
			$view_gift_link = SpecialPage::getTitleFor( 'ViewGift' );

			$html = wfMessage( 'useractivity-gift',
				'<b><a href="' . htmlspecialchars( $user_title->getFullURL() ) . "\">" . htmlspecialchars( $row->ug_user_name_to ) . "</a></b>",
				'<a href="' . htmlspecialchars( $user_title_from->getFullURL() ) . "\">" . htmlspecialchars( $user_title_from->getText() ) . "</a>"
			)->text() .
			"<div class=\"item\">
				<a href=\"" . htmlspecialchars( $view_gift_link->getFullURL( 'gift_id=' . $row->ug_id ) ) . "\" rel=\"nofollow\">
					{$icon}
					" . htmlspecialchars( $row->gift_name ) . "
				</a>
			</div>";

			$unixTS = wfTimestamp( TS_UNIX, $row->ug_date );

			$this->activityLines[] = [
				'type' => 'gift-rec',
				'timestamp' => $unixTS,
				'data' => ' ' . $html
			];

			$this->items[] = [
				'id' => $row->ug_id,
				'type' => 'gift-rec',
				'timestamp' => $unixTS,
				'pagetitle' => $row->gift_name,
				'namespace' => $row->gift_id,
				'username' => $row->ug_user_name_to,
				'userid' => $row->ug_user_id_to,
				'comment' => $row->ug_user_name_from,
				'new' => '0',
				'minor' => 0
			];
		}
	}

	/**
	 * Get recently received system gifts (awards) from the user_system_gift
	 * and system_gift tables and set them in the appropriate class member
	 * variables.
	 */
	private function setSystemGiftsRec() {
		$dbr = wfGetDB( DB_REPLICA );

		$where = [];

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "sg_user_id IN ($userIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['sg_user_id'] = $this->user_id;
		}

		$res = $dbr->select(
			[ 'user_system_gift', 'system_gift' ],
			[
				'sg_id', 'sg_user_id', 'sg_user_name',
				'sg_date', 'gift_name', 'gift_id'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'sg_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			],
			[ 'system_gift' => [ 'INNER JOIN', 'gift_id = sg_gift_id' ] ]
		);

		foreach ( $res as $row ) {
			$user_title = Title::makeTitle( NS_USER, $row->sg_user_name );
			$systemGiftIcon = new SystemGiftIcon( $row->gift_id, 'm' );
			$icon = $systemGiftIcon->getIconHTML();

			$system_gift_link = SpecialPage::getTitleFor( 'ViewSystemGift' );

			$html = wfMessage( 'useractivity-award' )->rawParams(
				'<b><a href="' . htmlspecialchars( $user_title->getFullURL() ) . "\">" . htmlspecialchars( $row->sg_user_name ) . "</a></b>",
				htmlspecialchars( $row->sg_user_name ) )->escaped() .
			'<div class="item">
				<a href="' . htmlspecialchars( $system_gift_link->getFullURL( 'gift_id=' . $row->sg_id ) ) . "\" rel=\"nofollow\">
					{$icon}
					" . htmlspecialchars( $row->gift_name ) . "
				</a>
			</div>";

			$unixTS = wfTimestamp( TS_UNIX, $row->sg_date );

			$this->activityLines[] = [
				'type' => 'system_gift',
				'timestamp' => $unixTS,
				'data' => ' ' . $html
			];

			$this->items[] = [
				'id' => $row->sg_id,
				'type' => 'system_gift',
				'timestamp' => $unixTS,
				'pagetitle' => $row->gift_name,
				'namespace' => $row->gift_id,
				'username' => $row->sg_user_name,
				'userid' => $row->sg_user_id,
				'comment' => '-',
				'new' => '0',
				'minor' => 0
			];
		}
	}

	/**
	 * Get recent changes in user relationships from the user_relationship
	 * table and set them in the appropriate class member variables.
	 */
	private function setRelationships() {
		global $wgLang;

		$dbr = wfGetDB( DB_REPLICA );

		$where = [];

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "r_user_id IN ($userIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['r_user_id'] = $this->user_id;
		}

		$res = $dbr->select(
			'user_relationship',
			[
				'r_id', 'r_user_id', 'r_user_name', 'r_user_id_relation',
				'r_user_name_relation', 'r_type', 'r_date'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'r_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			]
		);

		foreach ( $res as $row ) {
			if ( $row->r_type == 1 ) {
				$r_type = 'friend';
			} else {
				$r_type = 'foe';
			}

			$user_name_short = $wgLang->truncateForVisual( $row->r_user_name, 25 );
			$unixTS = wfTimestamp( TS_UNIX, $row->r_date );

			$this->items_grouped[$r_type][$row->r_user_name_relation]['users'][$row->r_user_name][] = [
				'id' => $row->r_id,
				'type' => $r_type,
				'timestamp' => $unixTS,
				'pagetitle' => '',
				'namespace' => '',
				'username' => $user_name_short,
				'userid' => $row->r_user_id,
				'comment' => $row->r_user_name_relation,
				'minor' => 0,
				'new' => 0
			];

			// set last timestamp
			$this->items_grouped[$r_type][$row->r_user_name_relation]['timestamp'] = $unixTS;

			$this->items[] = [
				'id' => $row->r_id,
				'type' => $r_type,
				'timestamp' => $unixTS,
				'pagetitle' => '',
				'namespace' => '',
				'username' => $row->r_user_name,
				'userid' => $row->r_user_id,
				'comment' => $row->r_user_name_relation,
				'new' => '0',
				'minor' => 0
			];
		}
	}

	/**
	 * Get recently sent public user board messages from the user_board table
	 * and set them in the appropriate class member variables.
	 */
	private function setMessagesSent() {
		$dbr = wfGetDB( DB_REPLICA );

		$where = [];
		// We do *not* want to display private messages...
		$where['ub_type'] = 0;

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "ub_user_id_from IN ($userIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['ub_user_id_from'] = $this->user_id;
		}

		$res = $dbr->select(
			'user_board',
			[
				'ub_id', 'ub_user_id', 'ub_user_name', 'ub_user_id_from',
				'ub_user_name_from', 'ub_date', 'ub_message'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'ub_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			]
		);

		foreach ( $res as $row ) {
			// Ignore nonexistent (for example, renamed) users
			$uid = User::idFromName( $row->ub_user_name );
			if ( !$uid ) {
				continue;
			}

			$to = $row->ub_user_name;
			$from = $row->ub_user_name_from;
			$unixTS = wfTimestamp( TS_UNIX, $row->ub_date );

			$this->items_grouped['user_message'][$to]['users'][$from][] = [
				'id' => $row->ub_id,
				'type' => 'user_message',
				'timestamp' => $unixTS,
				'pagetitle' => '',
				'namespace' => '',
				'username' => $from,
				'userid' => $row->ub_user_id_from,
				'comment' => $to,
				'minor' => 0,
				'new' => 0
			];

			// set last timestamp
			$this->items_grouped['user_message'][$to]['timestamp'] = $unixTS;

			$this->items[] = [
				'id' => $row->ub_id,
				'type' => 'user_message',
				'timestamp' => $unixTS,
				'pagetitle' => '',
				'namespace' => $this->fixItemComment( $row->ub_message ),
				'username' => $from,
				'userid' => $row->ub_user_id_from,
				'comment' => $to,
				'new' => '0',
				'minor' => 0
			];
		}
	}

	/**
	 * Get recent system messages (i.e. "User Foo advanced to level Bar") from
	 * the user_system_messages table and set them in the appropriate class
	 * member variables.
	 */
	private function setSystemMessages() {
		global $wgLang;

		$dbr = wfGetDB( DB_REPLICA );

		$where = [];

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "um_user_id IN ($userIDs)";
			}
		}

		if ( !empty( $this->show_current_user ) ) {
			$where['um_user_id'] = $this->user_id;
		}

		$res = $dbr->select(
			'user_system_messages',
			[
				'um_id', 'um_user_id', 'um_user_name', 'um_type', 'um_message',
				'um_date'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'um_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			]
		);

		foreach ( $res as $row ) {
			$user_title = Title::makeTitle( NS_USER, $row->um_user_name );
			$user_name_short = htmlspecialchars( $wgLang->truncateForVisual( $row->um_user_name, 15 ) );
			$unixTS = wfTimestamp( TS_UNIX, $row->um_date );

			$this->activityLines[] = [
				'type' => 'system_message',
				'timestamp' => $unixTS,
				'data' => ' <b><a href="' . htmlspecialchars( $user_title->getFullURL() ) . "\">{$user_name_short}</a></b> " . htmlspecialchars( $row->um_message )
			];

			$this->items[] = [
				'id' => $row->um_id,
				'type' => 'system_message',
				'timestamp' => $unixTS,
				'pagetitle' => '',
				'namespace' => '',
				'username' => $row->um_user_name,
				'userid' => $row->um_user_id,
				'comment' => $this->fixItemComment( $row->um_message ),
				'new' => '0',
				'minor' => 0
			];
		}
	}

	/**
	 * Get recent network updates (but only if the SportsTeams extension is
	 * installed) and set them in the appropriate class member variables.
	 */
	private function setNetworkUpdates() {
		global $wgLang;

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'SportsTeams' ) ) {
			return;
		}

		$dbr = wfGetDB( DB_REPLICA );

		$where = [];

		if ( !empty( $this->rel_type ) ) {
			$users = $dbr->select(
				'user_relationship',
				'r_user_id_relation',
				[
					'r_user_id' => $this->user_id,
					'r_type' => $this->rel_type
				],
				__METHOD__
			);
			$userArray = [];
			foreach ( $users as $user ) {
				$userArray[] = $user;
			}
			$userIDs = implode( ',', $userArray );
			if ( !empty( $userIDs ) ) {
				$where[] = "us_user_id IN ($userIDs)";
			}
		}

		if ( $this->show_current_user ) {
			$where['us_user_id'] = $this->user_id;
		}

		$res = $dbr->select(
			'user_status',
			[
				'us_id', 'us_user_id', 'us_user_name', 'us_text',
				'us_date', 'us_sport_id', 'us_team_id'
			],
			$where,
			__METHOD__,
			[
				'ORDER BY' => 'us_id DESC',
				'LIMIT' => $this->item_max,
				'OFFSET' => 0
			]
		);

		foreach ( $res as $row ) {
			if ( $row->us_team_id ) {
				$team = SportsTeams::getTeam( $row->us_team_id );
				$network_name = $team['name'];
			} else {
				$sport = SportsTeams::getSport( $row->us_sport_id );
				$network_name = $sport['name'];
			}
			$unixTS = wfTimestamp( TS_UNIX, $row->us_date );

			$this->items[] = [
				'id' => $row->us_id,
				'type' => 'network_update',
				'timestamp' => $unixTS,
				'pagetitle' => '',
				'namespace' => '',
				'username' => $row->us_user_name,
				'userid' => $row->us_user_id,
				'comment' => $this->fixItemComment( $row->us_text ),
				'sport_id' => $row->us_sport_id,
				'team_id' => $row->us_team_id,
				'network' => $network_name
			];

			$user_title = Title::makeTitle( NS_USER, $row->us_user_name );
			$user_name_short = $wgLang->truncateForVisual( $row->us_user_name, 15 );

			$sportsNetworkURL = htmlspecialchars(
				SpecialPage::getTitleFor( 'FanHome' )->getFullURL( [
					'sport_id' => $row->us_sport_id,
					'team_id' => $row->us_team_id
				] ),
				ENT_QUOTES
			);

			$page_link = '<a href="' . $sportsNetworkURL . "\" rel=\"nofollow\">" . htmlspecialchars( $network_name ) . "</a>";
			$network_image = SportsTeams::getLogo( $row->us_sport_id, $row->us_team_id, 's' );

			// FIXME: This message uses raw HTML
			$html = wfMessage(
				'useractivity-network-thought',
				htmlspecialchars( $row->us_user_name ),
				htmlspecialchars( $user_name_short ),
				$page_link,
				htmlspecialchars( $user_title->getFullURL() )
			)->text() .
					'<div class="item">
						<a href="' . $sportsNetworkURL . "\" rel=\"nofollow\">
							{$network_image}
							\"" . htmlspecialchars( $row->us_text ) . "\"
						</a>
					</div>";

			$this->activityLines[] = [
				'type' => 'network_update',
				'timestamp' => $unixTS,
				'data' => $html,
			];
		}
	}

	public function getEdits() {
		$this->setEdits();
		return $this->items;
	}

	public function getVotes() {
		$this->setVotes();
		return $this->items;
	}

	public function getComments() {
		$this->setComments();
		return $this->items;
	}

	public function getGiftsSent() {
		$this->setGiftsSent();
		return $this->items;
	}

	public function getGiftsRec() {
		$this->setGiftsRec();
		return $this->items;
	}

	public function getSystemGiftsRec() {
		$this->setSystemGiftsRec();
		return $this->items;
	}

	public function getRelationships() {
		$this->setRelationships();
		return $this->items;
	}

	public function getSystemMessages() {
		$this->setSystemMessages();
		return $this->items;
	}

	public function getMessagesSent() {
		$this->setMessagesSent();
		return $this->items;
	}

	public function getNetworkUpdates() {
		$this->setNetworkUpdates();
		return $this->items;
	}

	public function getActivityList() {
		if ( $this->show_edits ) {
			$this->setEdits();
		}
		if ( $this->show_votes ) {
			$this->setVotes();
		}
		if ( $this->show_comments ) {
			$this->setComments();
		}
		if ( $this->show_gifts_sent ) {
			$this->setGiftsSent();
		}
		if ( $this->show_gifts_rec ) {
			$this->setGiftsRec();
		}
		if ( $this->show_relationships ) {
			$this->setRelationships();
		}
		if ( $this->show_system_messages ) {
			$this->getSystemMessages();
		}
		if ( $this->show_system_gifts ) {
			$this->getSystemGiftsRec();
		}
		if ( $this->show_messages_sent ) {
			$this->getMessagesSent();
		}
		if ( $this->show_network_updates ) {
			$this->getNetworkUpdates();
		}

		if ( $this->items ) {
			usort( $this->items, [ 'UserActivity', 'sortItems' ] );
		}
		return $this->items;
	}

	public function getActivityListGrouped() {
		$this->getActivityList();

		if ( $this->show_edits ) {
			$this->simplifyPageActivity( 'edit' );
		}
		if ( $this->show_comments ) {
			$this->simplifyPageActivity( 'comment' );
		}
		if ( $this->show_relationships ) {
			$this->simplifyPageActivity( 'friend' );
		}
		if ( $this->show_relationships ) {
			$this->simplifyPageActivity( 'foe' );
		}
		if ( $this->show_messages_sent ) {
			$this->simplifyPageActivity( 'user_message' );
		}

		if ( !isset( $this->activityLines ) ) {
			$this->activityLines = [];
		}

		if ( isset( $this->activityLines ) && is_array( $this->activityLines ) ) {
			usort( $this->activityLines, [ 'UserActivity', 'sortItems' ] );
		}

		return $this->activityLines;
	}

	/**
	 * @param string $type Activity type, such as 'friend' or 'foe' or 'edit'
	 * @param bool $has_page True by default
	 */
	function simplifyPageActivity( $type, $has_page = true ) {
		global $wgLang;

		if ( !isset( $this->items_grouped[$type] ) || !is_array( $this->items_grouped[$type] ) ) {
			return '';
		}

		foreach ( $this->items_grouped[$type] as $page_name => $page_data ) {
			$users = '';
			$pages = '';

			if ( $type == 'friend' || $type == 'foe' || $type == 'user_message' ) {
				$page_title = Title::newFromText( $page_name, NS_USER );
			} else {
				$page_title = Title::newFromText( $page_name );
			}

			$count_users = count( $page_data['users'] );
			$user_index = 0;
			$pages_count = 0;

			// Init empty variable to be used later on for GENDER processing
			// if the event is only for one user.
			$userNameForGender = '';

			foreach ( $page_data['users'] as $user_name => $action ) {
				if ( $page_data['timestamp'] < $this->three_days_ago ) {
					continue;
				}

				$count_actions = count( $action );

				if ( $has_page && !isset( $this->displayed[$type][$page_name] ) ) {
					$this->displayed[$type][$page_name] = 1;

					$pages .= ' <a href="' . htmlspecialchars( $page_title->getFullURL() ) . "\">" . htmlspecialchars( $page_name ) . "</a>";
					if ( $count_users == 1 && $count_actions > 1 ) {
						$pages .= wfMessage( 'word-separator' )->escaped();
						$pages .= wfMessage( 'parentheses' )->rawParams( wfMessage(
							// For grep: useractivity-group-edit, useractivity-group-comment,
							// useractivity-group-user_message, useractivity-group-friend
							"useractivity-group-{$type}",
							$count_actions,
							$user_name
						)->escaped() )->escaped();
					}
					$pages_count++;
				}

				// Single user on this action,
				// see if we can stack any other singles
				if ( $count_users == 1 ) {
					$userNameForGender = $user_name;
					foreach ( $this->items_grouped[$type] as $page_name2 => $page_data2 ) {
						if ( !isset( $this->displayed[$type][$page_name2] ) &&
							count( $page_data2['users'] ) == 1
						) {
							foreach ( $page_data2['users'] as $user_name2 => $action2 ) {
								if ( $user_name2 == $user_name && $pages_count < 5 ) {
									$count_actions2 = count( $action2 );

									if (
										$type == 'friend' ||
										$type == 'foe' ||
										$type == 'user_message'
									) {
										$page_title2 = Title::newFromText( $page_name2, NS_USER );
									} else {
										$page_title2 = Title::newFromText( $page_name2 );
									}

									if ( $pages ) {
										$pages .= ', ';
									}
									if ( $page_title2 instanceof Title ) {
										$pages .= ' <a href="' . htmlspecialchars( $page_title2->getFullURL() ) . '">' . htmlspecialchars( $page_name2 ) . '</a>';
									}
									if ( $count_actions2 > 1 ) {
										$pages .= wfMessage( 'word-separator' )->escaped();
										$pages .= wfMessage( 'parentheses' )->rawParams( wfMessage(
											// For grep: useractivity-group-edit, useractivity-group-comment,
											// useractivity-group-user_message, useractivity-group-friend
											"useractivity-group-{$type}",
											$count_actions2,
											$user_name
										)->escaped() )->escaped();
									}
									$pages_count++;

									$this->displayed[$type][$page_name2] = 1;
								}
							}
						}
					}
				}

				$user_index++;

				if ( $users && $count_users > 2 ) {
					$users .= wfMessage( 'comma-separator' )->escaped();
				}
				if ( $user_index == $count_users && $count_users > 1 ) {
					$users .= wfMessage( 'and' )->escaped();
				}

				$user_title = Title::makeTitle( NS_USER, $user_name );
				$user_name_short = htmlspecialchars( $wgLang->truncateForVisual( $user_name, 15 ) );

				$safeTitle = htmlspecialchars( $user_title->getText() );
				$users .= ' <b><a href="' . htmlspecialchars( $user_title->getFullURL() ) . "\" title=\"{$safeTitle}\">{$user_name_short}</a></b>";
			}
			if ( $pages || $has_page == false ) {
				$this->activityLines[] = [
					'type' => $type,
					'timestamp' => $page_data['timestamp'],
					// For grep: useractivity-edit, useractivity-foe, useractivity-friend,
					// useractivity-gift, useractivity-user_message, useractivity-comment
					'data' => wfMessage( "useractivity-{$type}" )->rawParams(
						$users, $count_users, $pages, $pages_count,
						// $userNameForGender is not sanitized, but this parameter
						// is expected to be used for gender only
						$userNameForGender
					)->escaped()
				];
			}
		}
	}

	/**
	 * Get the correct icon for the given activity type.
	 *
	 * @param string $type Activity type, such as 'edit' or 'friend' (etc.)
	 * @return string Image file name (images are located inSocialProfile's
	 * images/ directory)
	 */
	static function getTypeIcon( $type ) {
		switch ( $type ) {
			case 'edit':
				return 'editIcon.gif';
			case 'vote':
				return 'voteIcon.gif';
			case 'comment':
				return 'comment.gif';
			case 'gift-sent':
				return 'icon_package.gif';
			case 'gift-rec':
				return 'icon_package_get.gif';
			case 'friend':
				return 'addedFriendIcon.png';
			case 'foe':
				return 'addedFoeIcon.png';
			case 'system_message':
				return 'challengeIcon.png';
			case 'system_gift':
				return 'awardIcon.png';
			case 'user_message':
				return 'emailIcon.gif';
			case 'network_update':
				return 'note.gif';
		}
	}

	/**
	 * "Fixes" a comment (such as a recent changes edit summary) by converting
	 * certain characters (such as the ampersand) into their encoded
	 * equivalents and, if necessary, truncates the comment
	 *
	 * @param string $comment Comment to "fix"
	 * @return string "Fixed" comment
	 */
	function fixItemComment( $comment ) {
		global $wgLang;
		if ( !$comment ) {
			return '';
		}
		$preview = $wgLang->truncateForVisual( $comment, 75 );
		return htmlspecialchars( $preview );
	}

	/**
	 * Compares the timestamps of two given objects to decide how to sort them.
	 * Called by getActivityList() and getActivityListGrouped().
	 *
	 * @param object $x
	 * @param object $y
	 * @return int 0 if the timestamps are the same, -1 if $x's timestamp
	 * is greater than $y's, else 1
	 */
	private static function sortItems( $x, $y ) {
		if ( $x['timestamp'] == $y['timestamp'] ) {
			return 0;
		} elseif ( $x['timestamp'] > $y['timestamp'] ) {
			return -1;
		} else {
			return 1;
		}
	}
}
