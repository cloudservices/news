<?php
/**
* ownCloud - News app
*
* @author Alessandro Cosentino
* Copyright (c) 2012 - Alessandro Cosentino <cosenal@gmail.com>
*
* This file is licensed under the Affero General Public License version 3 or later.
* See the COPYING-README file
*
*/

namespace OCA\News;

/**
 * This class maps a feed to an entry in the feeds table of the database.
 */
class FeedMapper {

	const tableName = '*PREFIX*news_feeds';
	private $userid;

	public function __construct($userid = null){
		if ($userid !== null) {
			$this->userid = $userid;
		}
		$this->userid = \OCP\USER::getUser();
	}

	/**
	 * @brief
	 * @param row a row from the feeds table of the database
	 * @returns an object of the class OC_News_Feed
	 */
	public function fromRow($row){
	}

	/**
	 * @brief
	 * @param userid
	 * @returns
	 */
	public function findAll(){
		$query = 'SELECT * FROM ' . self::tableName;
		$params = array();
		if( $this->userid ){
			$query = $query.' WHERE user_id = ?';
			$params[] = $this->userid;
		}

		$stmt = \OCP\DB::prepare( $query );
		$result = $stmt->execute( $params );
		$feeds = array();
		while ($row = $result->fetchRow()) {
			$url = $row['url'];
			$id = $row['id'];
			$folderid = $row['folder_id'];
			$userid = $row['user_id'];
			$feeds[] = array("url" => $url, "id" => $id, "folderid" => $folderid, 'userid' => $userid );
		}

		return $feeds;
	}

	/**
	 * @brief Retrieve a feed from the database
	 * @param id The id of the feed in the database table.
	 * @returns
	 */
	public function findById($id){
		$stmt = \OCP\DB::prepare('SELECT * FROM ' . self::tableName . ' WHERE id = ?');
		$result = $stmt->execute(array($id));
		if(!$row = $result->fetchRow())
			return null;

		$url = $row['url'];
		$title = htmlspecialchars_decode($row['title']);
		$feed = new Feed($url, $title, null, $id);
		return $feed;
	}

	/**
	 * @brief Retrieve all the feeds contained in the folder $folderid
	 * @param folderid The id of the folder in the database table.
	 * @returns a list of feeds
	 */
	public function findByFolderId($folderid){
		$stmt = \OCP\DB::prepare('SELECT * FROM ' . self::tableName . ' WHERE user_id = ? AND folder_id = ?');
		$result = $stmt->execute(array($this->userid, $folderid));
		$feeds = array();
		while ($row = $result->fetchRow()) {
			$url = $row['url'];
			$title = htmlspecialchars_decode($row['title']);
			$id = $row['id'];
			$feed = new Feed($url, $title, null, $id);
			$favicon = $row['favicon_link'];
			$feed->setFavicon($favicon);
			$feeds[] = $feed;
		}
		return $feeds;
	}


	/**
	 * @brief Retrieve a feed and all its items from the database
	 * @param id The id of the feed in the database table.
	 * @returns an instance of OC_News_Feed
	 */
	public function findWithItems($id){
		$stmt = \OCP\DB::prepare('SELECT * FROM ' . self::tableName . ' WHERE id = ?');
		$result = $stmt->execute(array($id));
		$row = $result->fetchRow();
		$url = $row['url'];
		$title = htmlspecialchars_decode($row['title']);
		$feed = new Feed($url, $title, null,$id);
		$favicon = $row['favicon_link'];
		$feed->setFavicon($favicon);
		$itemMapper = new ItemMapper();
		$items = $itemMapper->findAll($id);
		$feed->setItems($items);

		return $feed;
	}

	/**
	 * @brief Find the id of a feed and all its items from the database
	 * @param url url of the feed
	 * @return id of the feed corresponding to the url passed as parameters
	 *	null - if there is no such feed
	 */
	public function findIdFromUrl($url){
		$stmt = \OCP\DB::prepare('SELECT * FROM ' . self::tableName . ' WHERE url = ?');
		$result = $stmt->execute(array($url));
		$row = $result->fetchRow();
		$id = null;
		if ($row != null){
			$id = $row['id'];
		}
		return $id;
	}

	public function mostRecent(){
		//FIXME: does something like SELECT TOP 1 * exists in pear/mdb2 ??
		$stmt = \OCP\DB::prepare('SELECT * FROM ' . self::tableName . ' ORDER BY lastmodified');
		$result = $stmt->execute();
		$row = $result->fetchRow();
		$id = null;
		if ($row != null){
			$id = $row['id'];
		}
		return $id;
	}

	/**
	 * @brief Save the feed and all its items into the database
	 * @param feed the feed to be saved
	 * @returns The id of the feed in the database table.
	 */
	 //TODO: handle error case
	public function save(Feed $feed, $folderid){
		$CONFIG_DBTYPE = \OCP\Config::getSystemValue( "dbtype", "sqlite" );
		if( $CONFIG_DBTYPE == 'sqlite' or $CONFIG_DBTYPE == 'sqlite3' ){
			$_ut = "strftime('%s','now')";
		} elseif($CONFIG_DBTYPE == 'pgsql') {
			$_ut = 'date_part(\'epoch\',now())::integer';
		} else {
			$_ut = "UNIX_TIMESTAMP()";
		}

		$title = $feed->getTitle();
		$url = htmlspecialchars_decode($feed->getUrl());

		if(empty($title)) {
			$l = \OC_L10N::get('news');
			$title = $l->t('no title');
		}

		//FIXME: Detect when feed contains already a database id
		$feedid =  $this->findIdFromUrl($url);
		if ($feedid == null){
			$query = \OCP\DB::prepare("
				INSERT INTO " . self::tableName .
				"(url, title, favicon_link, folder_id, user_id, added, lastmodified)
				VALUES (?, ?, ?, ?, ?, $_ut, $_ut)
				");

			$params=array(
				$url,
				$title,
				$feed->getFavicon(),
				$folderid,
				$this->userid
			);
			$query->execute($params);

			$feedid = \OCP\DB::insertid(self::tableName);
		}
		$feed->setId($feedid);

		$itemMapper = new ItemMapper();

		$items = $feed->getItems();
		foreach($items as $item){
			$itemMapper->save($item, $feedid);
		}

		return $feedid;
	}

	public function deleteById($id){
		if ($id == null) {
			return false;
		}
		$stmt = \OCP\DB::prepare('DELETE FROM ' . self::tableName .' WHERE id = ? AND user_id = ?');

		$result = $stmt->execute(array($id, $this->userid));

		$itemMapper = new ItemMapper();
		//TODO: handle the value that the execute returns
		$itemMapper->deleteAll($id);

		return true;
	}
	public function delete(Feed $feed){
		$id = $feed->getId();
		return deleteById($id);
	}

	public function deleteAll($folderid){
		if ($folderid == null) {
			return false;
		}

		$stmt = \OCP\DB::prepare('SELECT id FROM ' . self::tableName . ' WHERE folder_id = ? AND user_id = ?');

		$result = $stmt->execute(array($folderid, $this->userid));
		while ($row = $result->fetchRow()) {
			if(!self::deleteById($row['id']))
				return false;
		}

		return true;
	}
}
