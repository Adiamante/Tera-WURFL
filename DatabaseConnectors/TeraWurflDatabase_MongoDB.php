<?php
/**
 * Tera_WURFL - PHP MySQL driven WURFL
 *
 * Tera-WURFL was written by Steve Kamerman, and is based on the
 * Java WURFL Evolution package by Luca Passani and WURFL PHP Tools by Andrea Trassati.
 * This version uses a MySQL database to store the entire WURFL file, multiple patch
 * files, and a persistent caching mechanism to provide extreme performance increases.
 *
 * @package TeraWurflDatabase
 * @author Steve Kamerman <stevekamerman AT gmail.com>
 * @author Simon Harris <tw AT pointbeing.net>
 * @version Stable 2.1.3 $Date: 2010/07/29 20:36:29
 * @license http://www.mozilla.org/MPL/ MPL Vesion 1.1
 */

/**
 * Local function to load this database connector
 *
 * @return TeraWurflDatabase an object that must extend the TeraWurflDatabase abstract class
 */
function getTeraWurflDatabaseConnnector(){ return new TeraWurflDatabase_MongoDB(); }

/**
 * Provides connectivity from Tera-WURFL to MongoDB
 * @package TeraWurflDatabase
 */
class TeraWurflDatabase_MongoDB extends TeraWurflDatabase {

	/**
	 * @var array
	 */
	public $errors;

	/**
	 * @var boolean
	 */
	public $db_implements_ris = true;

	/**
	 * @var boolean
	 * @todo See if this can be implemented
	 */
	public $db_implements_ld = false;

	/**
	 * @var int
	 */
	public $numQueries = 0;

	/**
	 * @var boolean
	 */
	public $connected = false;

	/**
	 * @var Mongo
	 */
	protected $mongo;

	/**
	 * @var MongoDB
	 */
	protected $dbcon;
	
	/**
	 * @var string
	 */
	protected static $MERGE;
	
	/**
	 * @var MongoCollection
	 */
	protected $mergecoll;
	
	/**
	 * @var int
	 */
	protected static $PREALLOC_SIZE = 31457280;
	
	public function __construct(){
		parent::__construct();
		self::$MERGE = TeraWurflConfig::$TABLE_PREFIX.'Merge';
	}
	
	// Device Table Functions (device, hybrid, patch) --------------------------


	/**
	 * @param string $wurflID
	 * @throws Exception
	 * @return array The device capabilities
	 */
	public function getDeviceFromID($wurflID) {
		$tofind = array(
					'deviceID' => $wurflID,
		);

		$device = $this->mergecoll->findOne($tofind);
		$this->numQueries++;

		if (is_null($device)) {
			// is this really an Exception? Cloned from MySQL5 driver
			throw new Exception("Tried to lookup an invalid WURFL Device ID: $wurflID");
		}

		return $device['capabilities'];
	}


	/**
	 * @param string $tablename
	 * @return array
	 */
	public function getFullDeviceList($tablename){
		$matcher = $this->getMatcherNameFromTable($tablename);
		$res = $this->mergecoll->find(array("matcher"=>$matcher));
		$this->numQueries++;

		$data = array();
		foreach ($res as $device) {
			$data[$device['deviceID']] = $device['user_agent'];
		}
		return $data;
	}


	/**
	 * Exact match by user agent string
	 *
	 * @param string $userAgent
	 */
	public function getDeviceFromUA($userAgent) {
		$tofind = array(
					'user_agent' => $userAgent,
		);
		$data = $this->mergecoll->findOne($tofind);
		$this->numQueries++;

		if (is_null($data)) {
			return false;
		}

		return $data['deviceID'];
	}


	/**
	 * RIS == Reduction in String (reduce string one char at a time)
	 *
	 * @param string $userAgent
	 * @param int $tolerance
	 * @param UserAgentMatcher $matcher
	 * @return string A TW Device ID
	 */
	public function getDeviceFromUA_RIS($userAgent, $tolerance, UserAgentMatcher $matcher) {

		$toexec = 'function(ua, tolerance, matcher) { return performRis(ua, tolerance, matcher) }';
		$args   = array($userAgent, $tolerance, $matcher->tableSuffix());

		$this->numQueries++;
		$response = $this->dbcon->execute($toexec, $args);
		if ( !empty($response['ok']) && $response['ok'] == 1 && !empty($response['retval'])) {
			return $response['retval'];
		}
		return WurflConstants::$GENERIC;
	}

	protected function getMatcherNameFromTable($table){
		$parts = explode('_', $table);
		$matcher = array_pop($parts);
		return $matcher;
	}
	/**
	 * @param array $tables
	 */
	public function loadDevices(&$tables) {

		$insert_errors = array();
		$insertcache   = array();
		$insertedrows  = 0;

		$this->createProcedures();
		// insert records into a new temp table until we know everything is OK
		$temptable = self::$MERGE . self::$DB_TEMP_EXT;
		$this->_dropCollectionIfExists($temptable);
		// create this collection manually since it is fixed
		$this->dbcon->command(array(
			"create" => $temptable,
			"size" => self::$PREALLOC_SIZE,
//			"capped" => false,
//			"autoIndexId" => false
		));
		$collection = $this->dbcon->selectCollection($temptable);

		foreach ($tables as $table => $devices) {
			$matcher = $this->getMatcherNameFromTable($table);
			$matcherbatch = array();
			foreach ($devices as $device) {
				$matcherbatch[] = array(
								'deviceID'           => $device['id'],
								'user_agent'         => $device['user_agent'],
								'fall_back'          => $device['fall_back'],
								'actual_device_root' => (isset($device['actual_device_root']) ) ? $device['actual_device_root'] : '',
								'matcher'			 => $matcher,
								'capabilities'       => $device,
				);
				$insertedrows++;
			}
			try{
				$collection->batchInsert($matcherbatch);
				$this->numQueries++;

			} catch (Exception $e) {
				$insert_errors[] = 'DB server reported error on id "' . $device['id'] . '": ' . $e->getMessage();
			}

			if (count($insert_errors) > 0) {

				// Roll back changes, and leave the temp table in the DB for manual inspection
				$this->errors = array_merge($this->errors, $insert_errors);
				return false;
			}
		}
		// Commit changes
		$this->_dropCollectionIfExists(self::$MERGE);
		$this->_renameCollection($temptable, self::$MERGE);
		// Enforce Indecies
		$this->mergecoll->ensureIndex(array('deviceID' => 1), array("unique"=>true,"dropDups"=>true,"background"=>true,"safe"=>false));
		$this->mergecoll->ensureIndex(array('user_agent' => 1), array("unique"=>true,"dropDups"=>true,"background"=>true,"safe"=>false));
		$this->mergecoll->ensureIndex(array('matcher' => 1), array("unique"=>false,"dropDups"=>false,"background"=>true,"safe"=>false));
		return true;
	}
	
	public function createSettingsTable(){
		$name = TeraWurflConfig::$TABLE_PREFIX.'Settings';
		if($this->collectionExists($name)){
			$this->_createCollection($name);
			$this->mergecoll->ensureIndex(array('id' => 1), array("unique"=>true,"dropDups"=>true,"background"=>false,"safe"=>true));
		}
	}
	// Cache Table Functions ---------------------------------------------------


	/**
	 * Drops, creates and indexes the cache table
	 */
	public function createCacheTable(){
		$this->_recreateCollection(TeraWurflConfig::$TABLE_PREFIX.'Cache');
		$this->mergecoll->ensureIndex(array('user_agent' => 1), array("unique"=>true,"dropDups"=>true,"background"=>true,"safe"=>false));
	}


	/**
	 * @param string $userAgent
	 * @return array Should return (bool) false or the device array
	 */
	public function getDeviceFromCache($userAgent) {

		$tofind = array(
						'user_agent' => $userAgent,
		);

		try {
			$cachecoll = $this->dbcon->selectCollection(TeraWurflConfig::$TABLE_PREFIX.'Cache');
			$device = $cachecoll->findOne($tofind);
			$this->numQueries++;

			if ( !is_null($device) ) {
				return $device['cache_data'];
			}

		} catch(Exception $e) {
			$this->errors[] = $e->__toString() . ', caught in ' . __CLASS__ . '::' . __METHOD__ . '()';
		}
		return false;
	}


	/**
	 * @param string $userAgent
	 * @param array $device
	 * @return boolean Whether the insert was successful
	 */
	public function saveDeviceInCache($userAgent, $device) {

		$toinsert = array(
						'user_agent' => $userAgent,
						'cache_data' => $device,
		);

		try {
			$cachecoll = $this->dbcon->selectCollection(TeraWurflConfig::$TABLE_PREFIX.'Cache');
			$cachecoll->insert($toinsert, array('safe' => true));
			$this->numQueries++;
			return true;

		} catch(Exception $e) {
			$this->errors[] = $e->__toString() . ', caught in ' . __CLASS__ . '::' . __METHOD__ . '()';
		}
		return false;
	}


	/**
	 * @return boolean
	 */
	public function rebuildCacheTable() {

		// Use this instance to rebuild the cache and to facilitate logging
		$rebuilder = new TeraWurfl();

		$cachetable = TeraWurflConfig::$TABLE_PREFIX.'Cache';
		$temptable  = TeraWurflConfig::$TABLE_PREFIX.'Cache' . self::$DB_TEMP_EXT;

		$this->_dropCollectionIfExists($temptable);
		$this->_renameCollection($cachetable, $temptable);
		$this->createCacheTable();

		$tempcoll  = $this->dbcon->selectCollection($temptable);
		$cachecoll = $this->dbcon->selectCollection($cachetable);

		/* @var $fromcache MongoCursor */
		$fromcache = $tempcoll->find(array(),array("user_agent" => 1));
		$this->numQueries++;

		// migrate cached items from old cache
		if (0 == $fromcache->count()) {
			// No records in cache table == nothing to rebuild
			$this->_dropCollectionIfExists($temptable);
			$rebuilder->toLog('Rebuilt cache table, existing table was empty - this is very unusual.', LOG_WARNING, __FUNCTION__);
			return true;
		}

		foreach ($fromcache as $item) {

			// Just looking the device up will force it to be cached
			$rebuilder->getDeviceCapabilitiesFromAgent($item['user_agent']);

			// Reset the number of queries since we're not going to re-instantiate the object
			$this->numQueries += $rebuilder->db->numQueries;
			$rebuilder->db->numQueries = 0;
		}

		$this->_dropCollectionIfExists($temptable);
		$rebuilder->toLog('Rebuilt cache table.', LOG_NOTICE, __FUNCTION__);
		return true;
	}


	// Supporting DB functions -------------------------------------------------


	/**
	 * TODO: Fix this function - it currently reports the client version
	 */
	public function getServerVersion(){
		return Mongo::VERSION;
	}


	/**
	 * Runs any stored procs needed to set up db
	 */
	public function createProcedures() {

		// clear the db.system.js collection
		$collection = $this->dbcon->selectCollection('system.js');
		$this->numQueries++;
		$collection->remove(array("_id"=>"performRis"));
		$merge = self::$MERGE;
		$performRis =<<<EOL
function performRis(ua, tolerance, matcher) {
    var curlen = ua.length;
    var curua;

    while (curlen >= tolerance) {

		var toMatch = ua.substr(0, curlen);
		toMatch     = toMatch.replace(/[-[\]{}()*+?.,\\^$|#]/g, "\\\\$&");
		var matchReg   = new RegExp('^' + toMatch);
        var device = db.$merge.findOne({matcher:matcher, user_agent: matchReg},{deviceID:1});

       	if(device != null){
                return device.deviceID;
       	}
        curlen--;
    }
}
EOL;
		$this->numQueries++;
		$collection->save(
			array('_id' => 'performRis','value' => new MongoCode($performRis)),
			array('safe' => true)
		);
	}


	/**
	 * Establishes connection to database (does not check for DB sanity)
	 *
	 * @return boolean
	 */
	public function connect() {

		$this->numQueries++;

		try {
			$this->mongo = new Mongo(TeraWurflConfig::$DB_HOST);
			$this->dbcon = $this->mongo->selectDB(TeraWurflConfig::$DB_SCHEMA);

			if (!empty(TeraWurflConfig::$DB_USER) && !empty(TeraWurflConfig::$DB_PASS)) {
				$this->dbcon->authenticate(TeraWurflConfig::$DB_USER, TeraWurflConfig::$DB_PASS);
			}

		} catch(Exception $e) {
			$this->errors[] = $e->__toString() . ', caught in ' . __CLASS__ . '::' . __METHOD__ . '()';
			$this->connected = $e->getCode();
			return false;
		}
		$this->mergecoll = $this->dbcon->selectCollection(self::$MERGE);
		$this->connected = true;
		return true;
	}
	
	public function updateSetting($key,$value){
		$collection = $this->dbcon->selectCollection(TeraWurflConfig::$TABLE_PREFIX.'Settings');
		$collection->insert(array('id'=>$key,'value'=>$value),array('safe'=>true, 'upsert'=>true));
		$this->numQueries++;
	}

	public function getSetting($key){
		$collection = $this->dbcon->selectCollection(TeraWurflConfig::$TABLE_PREFIX.'Settings');
		$record = $collection->findOne(array('id'=>$key),array('value'));
		if(is_null($record)) return null;
		return $record['value'];
		$this->numQueries++;
	}


	/**
	 * Prepare raw text for use in queries (adding quotes if necessary)
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function SQLPrep($value) {
		return (string) $value;
	}


	/**
	 * @return array
	 */
	public function getTableList() {

		$collections = $this->dbcon->listCollections();

		$output = array();

		foreach ($collections as $coll) {
			$output[] = $coll->getName();
		}
		return $output;
	}
	public function collectionExists($name){
		$cols = $this->getTableList();
		return in_array($name,$cols);
	}

	/**
	 * @param string $table
	 * @return array
	 */
	public function getTableStats($table) {
		$stats = array();
		if(!$this->collectionExists($table)) return $stats;
		$rawstats = $this->dbcon->command(array('collStats' => $table));
		$stats['rows'] = $rawstats['count'];
		if ($table = TeraWurflConfig::$TABLE_PREFIX.'Merge') {
			$collection = $this->dbcon->selectCollection($table);
			$tofind = array(
						'actual_device_root' => 1,
			);
			$res = $collection->find($tofind);
			$stats['actual_devices'] = $res->count();
		}

		$stats['bytesize'] = $rawstats['storageSize'];
		return $stats;
	}

	/**
	 * @return array
	 */
	public function getCachedUserAgents() {

		$cachecoll = $this->dbcon->selectCollection(TeraWurflConfig::$TABLE_PREFIX.'Cache');

		$cached = $cachecoll->find(array(), array('user_agent'));
		$cached->sort(array('user_agent'));

		$uas = array();
		foreach ($cached as $device) {
			$uas[] = $device['user_agent'];
		}
		return $uas;
	}


	// Low-level collection management methods ---------------------------------


	/**
	 * @param string $collectionname
	 * @return boolean Whether the operation was successful
	 */
	protected function _recreateCollection($collectionname) {

		try {
			$this->_dropCollectionIfExists($collectionname);
			$this->_createCollection($collectionname);
			return true;
		} catch (Exception $e) {
			$this->errors[] = $e->__toString() . ', caught in ' . __CLASS__ . '::' . __METHOD__ . '()';
		}
		return false;
	}


	/**
	 * @param string $collectionname
	 * @return boolean Whether the operation was successful
	 */
	protected function _dropCollectionIfExists($collectionname) {

		try {
			// NOTE: the MongoCollection::dropCollection() method leaks memory, do not use
			if($this->collectionExists($collectionname)){
				$col = $this->dbcon->selectCollection($collectionname);
				$col->drop();
			}
			$this->numQueries++;
			return true;
		} catch (Exception $e) {
			$this->errors[] = $e->__toString() . ', caught in ' . __CLASS__ . '::' . __METHOD__ . '()';
		}
		return false;
	}


	/**
	 * @param string $collectionname
	 * @return boolean Whether the operation was successful
	 */
	protected function _createCollection($collectionname) {

		try {
			$this->dbcon->createCollection($collectionname);
			$this->numQueries++;
			return true;
		} catch (Exception $e) {
			$this->errors[] = $e->__toString() . ', caught in ' . __CLASS__ . '::' . __METHOD__ . '()';
		}
		return false;
	}


	/**
	 * @var string $from
	 * @var string $to
	 * @return boolean
	 */
	protected function _renameCollection($from, $to) {

		$admindb = $this->mongo->admin;
		$dbname  = TeraWurflConfig::$DB_SCHEMA;

		$admindb->command(array(
			    'renameCollection' => $dbname . '.' . $from,
			    'to'               => $dbname . '.' . $to,
		));
		$this->numQueries++;
	}

	// methods pending implementation ------------------------------------------


	// LD == Levesthein Distance
	public function getDeviceFromUA_LD($userAgent, $tolerance, UserAgentMatcher $matcher) {
		throw new Exception("Error: this function (LD) is not yet implemented in MongoDB");
	}

	// methods enforced by parent class, but never called ----------------------
	public function getMatcherTableList(){return array();}
	public function createGenericDeviceTable($tablename) {}
	public function getActualDeviceAncestor($wurflID) {}
	public function clearTable($tablename) {}
	public function createIndexTable(){}
}

