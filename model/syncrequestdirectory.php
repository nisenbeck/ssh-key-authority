<?php
/**
* Class for reading/writing to the list of SyncRequest objects in the database.
*/
class SyncRequestDirectory extends DBDirectory {
	/**
	* Store query as a prepared statement.
	*/
	private $sync_list_stmt;

	/**
	* Create the new sync request in the database.
	* @param SyncRequest $req object to add
	*/
	public function add_sync_request(SyncRequest $req) {
		// If a request for this account is already being processed, the running sync can have read
		// the data before this change. Set the request to pending again, so that it runs once more.
		$stmt = $this->database->prepare("INSERT INTO sync_request SET server_id = ?, account_name = ? ON DUPLICATE KEY UPDATE processing = 0");
		$stmt->bind_param('ds', $req->server_id, $req->account_name);
		$stmt->execute();
		$req->id = $stmt->insert_id;
		$stmt->close();
	}

	/**
	* Delete the sync request from the database, unless add_sync_request() set it to pending again.
	* @param SyncRequest $req object to delete
	*/
	public function delete_sync_request(SyncRequest $req) {
		$stmt = $this->database->prepare("DELETE FROM sync_request WHERE id = ? AND processing = 1");
		$stmt->bind_param('s', $req->id);
		$stmt->execute();
		$stmt->close();
	}

	/**
	* Set all sync requests to pending. Use only in the sync daemon, at its start and at its end: then
	* requests that are marked as in progress belong to syncs that did not finish.
	*/
	public function reset_sync_requests_in_progress() {
		$stmt = $this->database->prepare("UPDATE sync_request SET processing = 0");
		$stmt->execute();
		$stmt->close();
	}

	/**
	* List the sync requests stored in the database that are not being processed yet.
	* @return array of SyncRequest objects
	*/
	public function list_pending_sync_requests() {
		if(!isset($this->sync_list_stmt)) {
			$this->sync_list_stmt = $this->database->prepare("SELECT * FROM sync_request WHERE processing = 0 ORDER BY id");
		}
		$this->sync_list_stmt->execute();
		$result = $this->sync_list_stmt->get_result();
		$reqs = array();
		while($row = $result->fetch_assoc()) {
			$reqs[] = new SyncRequest($row['id'], $row);
		}
		return $reqs;
	}

	/**
	* List the sync requests stored in the database that are not being processed yet.
	* @return array of SyncRequest objects
	*/
	public function count_pending_sync_requests() {
		$this->sync_list_stmt = $this->database->prepare("SELECT COUNT(*) as total FROM sync_request WHERE processing = 0 ORDER BY id");
		$this->sync_list_stmt->execute();
		$result = $this->sync_list_stmt->get_result();
		$reqs = 0;
		while($row = $result->fetch_assoc()) {
			$reqs = $row['total'];
		}
		return $reqs;
	}
}

class SyncRequestNotFoundException extends Exception {}
