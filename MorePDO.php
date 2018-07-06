<?php
/**
 * Class extending PDO with those additions :
 * Connects to the database at the first real query rather than when the object is created (lazy connection)
 * Adds the method "run()" to do in one command what is usually done with a "prepare()" followed by an "execute()"
 * Adds the method "disconnect()" that does kill the connection from itself (KILL CONNECTION CONNECTION_ID()) (MariaDB/MySQL specific)
 * Adds the method "ping()" that does test if the connection is still working ; tries to reconnect if it did a graceful timeout (MariaDB/MySQL specific)
 * Adds the $timeoutReconnect variable to set the delay to wait before trying to reconnect in case of timeout/disconnection of the server (MariaDB/MySQL specific)
 * Automatically executes "ping()" before executing queries if the last "ping()" has been ran more than 4 seconds ago
 * Hides the database password on "connect()" backtraces
 * Default options are set to PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => False, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
 * Outputs queries before execution if the constant DEBUG is True
 * Inspired from https://github.com/andychase/gab/blob/master/models/PDOLazyConnector.php, https://phpdelusions.net/pdo/pdo_wrapper and https://github.com/senasi/lazy-pdo
 */
class MorePDO extends PDO {
	protected $dsn;
	protected $username;
	protected $password;
	protected $options;
	protected $dbh;

	/**
	 * @var boolean True if PDO was initialized
	 */
	protected $initialized = False;

	/**
	 * @var array Storage for attributes which are set before initializing connection
	 */
	protected $attributes = [];

	/**
	* @var timestamp of the last ping() on the database
	*/
	protected $lastPing;

	/**
	* @var int The delay in seconds before trying to reconnect after a timeout/disconnection from the server has been detected by the ping() function
	*/
	public $timeoutReconnect = 20;

	/*
	 * Does not connect to the database at the creation of the object like a normal PDO object but only at the first query, so the constructor only set parameters for the connection but does not do the connection which is done by initialize() when needed
	*/
	public function __construct($dsn, $username = NULL, $password = NULL, $options = []) {
		$default_options = [
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => False,
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		];

		$this->options = array_replace($default_options, $options);
		$this->dsn = $dsn;
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * Init PDO (does the connection to the database server) once, if not already initialized
	 * PDOException is wrapped to hide the database password from the backtrace output in case of an exception
	 *
	 * @return True if the connection is opened
	 */
	protected function initialize() {
		// In case there would be an exception, $this->options is protected and thus not directly available
		if(isset($this->attributes[PDO::ATTR_ERRMODE])) {
			// The PDO::ATTR_ERRMODE has been changed after the creation of the object using $this->setAttribute(), this is the value that will be used
			$PDOerrMode = $this->attributes[PDO::ATTR_ERRMODE];
		} else {
			$PDOerrMode = $this->options[PDO::ATTR_ERRMODE];
		}

		if (!$this->initialized) {
			try {
				// Creates the PDO object : Does the connection to the database
				parent::__construct($this->dsn, $this->username, $this->password, $this->options);
				$this->initialized = True;
				$this->lastPing = time();

				foreach ($this->attributes as $key => $value) {
					parent::setAttribute($key, $value);
				}
			} catch(PDOException $e) {
				// In case of an exception, it mimics what PDOException would do but does hide the database password from the backtrace

				// If PDO::ERRMODE_EXCEPTION is used : a WARNING message is triggered instead of a ERROR message with a normal PDOException (E_USER_ERROR would stop the script execution before the backtrace is shown)
				trigger_error("Uncaught exception 'PDOException' with message '".$e->getMessage()."'", E_USER_WARNING);

				// Removes the current directory from the path for files that are in the same directory as __FILE__
				$trace = str_replace(__DIR__."/", "", $e->getTraceAsString());
				// Replaces the database password on the trace with '...'
				$trace = preg_replace("/(PDO->__construct\('[^']+', '[^']+', ')[^']+(',)/", '$1...$2', $trace);
				error_log($trace);

				// If PDO::ERRMODE_EXCEPTION is set, an exception triggers a fatal error
				if($PDOerrMode == PDO::ERRMODE_EXCEPTION) exit(255);
				return False;
			}
		} else {
			// Re-check if the server is still connected if the last check has been done more than 4 seconds ago
			if(time() - $this->lastPing > 4) {
				$this->ping();
			}
		}
		return $this->initialized;
	}

	/**
	 * Check if the connection is still open and working, try to reconnect if a timeout occured
	 *
	 * @return True if the connection is opened and working
	 */
	public function ping() {
		$this->lastPing = time();
		try {
			if(!$this->query("DO 1")) {
				// If the initialization or this query has failed, the server is not available
				return False;
			}
		} catch (PDOException $e) {
			// wait_timeout or server restarted since last check ; this error code/message is specific to MySQL, will always throw a PDOException for other drivers
			if(parent::getAttribute(PDO::ATTR_DRIVER_NAME) != "mysql" || $e->getCode() != 'HY000' || !stristr($e->getMessage(), 'server has gone away')) {
				// Its not a timeout, throwing the error
				throw $e;
				return False;
			} else {
				// Its a timeout, trying to reconnect after $this->timeoutReconnect seconds
				if(!is_numeric($this->timeoutReconnect) || $this->timeoutReconnect < 0) {
					trigger_error("Invalid value for timeoutReconnect (".$this->timeoutReconnect."), forcing a 30 seconds value !", E_USER_WARNING);
					$this->timeoutReconnect = 30;
				}
				trigger_error($e->getMessage()." => Trying to reconnect in ".$this->timeoutReconnect." seconds", E_USER_WARNING);
				error_log(str_replace(__DIR__."/", "", $e->getTraceAsString()));
				sleep($this->timeoutReconnect);
				$this->initialized = False;

				// Returns False if the initialization has failed or True if it succeeded
				return $this->initialize();
			}
		}
		return True;
	}

	/**
	 * Disconnects from the database server without having to destroy the object (only works for MySQL)
	 * Will automatically reconnect on the next query
	 *
	 * @return True is the connection has been killed
	 */
	public function disconnect() {
		// No need to do anything if no connection were made to a server or the connection already closed
		if($this->initialized) {
			if(parent::getAttribute(PDO::ATTR_DRIVER_NAME) == "mysql") {
				// PDO::ATTR_ERRMODE will be set to SILENT as killing the connection would trigger a warning/error if its not the case

				// The ATTR_ERRMODE value is saved so it will be applied back after the query has been executed
				if(isset($this->attributes[PDO::ATTR_ERRMODE])) {
					// The PDO::ATTR_ERRMODE has been changed after the creation of the object using $this->setAttribute(), this is the value that will be used
					$PDOerrMode = $this->attributes[PDO::ATTR_ERRMODE];
				} else {
					$PDOerrMode = $this->options[PDO::ATTR_ERRMODE];
				}

				parent::setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
				$this->exec("KILL CONNECTION CONNECTION_ID()");
				// The value is set back to its original value
				parent::setAttribute(PDO::ATTR_ERRMODE, $PDOerrMode);
				$this->initialized = False;
				return True;
			} else {
				trigger_error("disconnect() is only implemented for the mysql driver");
			}
		}
		return False;
	}

	public function close() {
		return $this->disconnect();
	}

	/**
	 * Does the prepare() and execute() in a single command
	 *
	 * @param string $statement
	 * @param array $driver_options
	 * @return \PDOStatement
	 */
	public function run($statement, $args = NULL) {
		if(defined("DEBUG")) {
			if(isset($args))	echo "DEBUG: MorePDO->run(".trim($statement).", ".implode(", ", $args).")\n";
			else			echo "DEBUG: MorePDO->run(".trim($statement).", NULL)\n";
		}
		if (!$args) {
			return $this->query($statement);
		}
		if($stmt = $this->prepare($statement)) {
			if(!$stmt->execute($args)) {
				error_log("Error during execution of query '$statement' !");
			}
			return $stmt;
		} else {
			error_log("Error during preparation of query '$statement', execution aborted !");
			// To avoid fatal errors if executing/preparing an incorrect query while PDO::ERRMODE_SILENT or WARNING is set and PDOStatement methods are called on it (ex: $dbh->run("BAD QUERY")->fetch();)
			return new PDOStatement();
		}
	}

	// Overloaded PDO methods

	/**
	 * Initiates a transaction
	 *
	 * @return boolean
	 */
	public function beginTransaction() {
		if(!$this->initialize()) return False;
		return parent::beginTransaction();
	}

	/**
	 * Commits a transaction
	 *
	 * @return boolean
	 */
	public function commit() {
		if(defined("DEBUG")) { echo "DEBUG: MorePDO->".__FUNCTION__."(".trim($statement).", ".implode(", ", $args).")\n"; }
		if(!$this->initialize()) return False;
		return parent::commit();
	}

	/**
	 * Fetch the SQLSTATE associated with the last operation on the database handle
	 *
	 * @return mixed
	 */
	public function errorCode() {
		$this->initialize();
		return parent::errorCode();
	}

	/**
	 * Fetch extended error information associated with the last operation on the database handle
	 *
	 * @return array
	 */
	public function errorInfo() {
		$this->initialize();
		return parent::errorInfo();
	}

	/**
	 * Execute an SQL statement and return the number of affected rows
	 *
	 * @param string $statement
	 * @return int
	 */
	public function exec($statement) {
		if(defined("DEBUG")) { echo "DEBUG: MorePDO->".__FUNCTION__."(".trim($statement).")\n"; }
		if(!$this->initialize()) return False;
		return parent::exec($statement);
	}

	/**
	 * Retrieve a database connection attribute
	 *
	 * @param int $attribute
	 * @return mixed
	 */
	public function getAttribute($attribute) {
		if(!$this->initialize()) return False;
		return parent::getAttribute($attribute);
	}

	// not needed - static function
	// public static function getAvailableDrivers()
	// {
	// }

	/**
	 * Checks if inside a transaction
	 *
	 * @return boolean
	 */
	public function inTransaction() {
		if(!$this->initialize()) return False;
		return parent::inTransaction();
	}

	/**
	 * Returns the ID of the last inserted row or sequence value
	 *
	 * @param string $name
	 * @return string
	 */
	public function lastInsertId($name = null) {
		if(!$this->initialize()) return False;
		return parent::lastInsertId($name);
	}

	/**
	 * Prepares a statement for execution and returns a statement object
	 *
	 * @param string $statement
	 * @param array $driver_options
	 * @return \PDOStatement
	 */
	public function prepare($statement, $driver_options = []) {
		if(!$this->initialize()) return False;
		return parent::prepare($statement, $driver_options);
	}

	/**
	 * Executes an SQL statement, returning a result set as a PDOStatement object
	 *
	 * @param string $statement
	 * @return \PDOStatement
	 */
	public function query($statement) {
		if(defined("DEBUG")) { echo "DEBUG: MorePDO->".__FUNCTION__."(".trim($statement).")\n"; }
		if(!$this->initialize()) return False;
		return call_user_func_array('parent::query', func_get_args());
	}

	/**
	 * Rolls back a transaction
	 *
	 * @return boolean
	 */
	public function rollback() {
		if(!$this->initialize()) return False;
		return parent::rollBack();
	}

	/**
	 * Set an attribute
	 *
	 * @param int $attribute
	 * @param mixed $value
	 * @return boolean
	 */
	public function setAttribute($attribute, $value) {
		$this->attributes[$attribute] = $value;
		if ($this->initialized) {
			return parent::setAttribute($attribute, $value);
		} else {
			return True;
		}
	}
}


/**
 * mysqli_*() functions emulation using (More)PDO : For a quick and easy transition from mysql(i)_*() functions to (More)PDO with mostly function name substitution and no complex code rewrite
 * Known limitation : Functions such as $mysqli->fetch_*() cannot be directly converted to $pdo->fetch_*(), it must either be replaced by PDO_fetch_*($dbh) or by $dbh->fetch(PDO::FETCH_*)
 */

// mysqli_query() emulation for smoother scripts transition, works as mysqli_query() but returns a PDOStatement object
function PDO_query($dbh, $query) {
	if(!($dbh instanceof PDO)) trigger_error("Connection handle is not a PDO object !", E_USER_ERROR);

	return $dbh->query($query);
}

// mysqli_fetch_array() emulation for smoother scripts transition, works as mysqli_fetch_array() but takes a PDOStatement object as argument
function PDO_fetch_array($PDOStatement, $type = MYSQLI_BOTH) {
	if($type == MYSQLI_BOTH) {
		return $PDOStatement->fetch(PDO::FETCH_BOTH);
	} elseif($type == MYSQLI_ASSOC) {
		return $PDOStatement->fetch(PDO::FETCH_ASSOC);
	} elseif($type == MYSQLI_NUM) {
		return $PDOStatement->fetch(PDO::FETCH_NUM);
	} else {
		trigger_error("Invalid result type passed !", E_USER_WARNING);
		return $PDOStatement->fetch(PDO::FETCH_BOTH);
	}
}

// mysqli_fetch_assoc() emulation for smoother scripts transition, works as mysqli_fetch_assoc() but takes a PDOStatement object as argument
function PDO_fetch_assoc($PDOStatement) {
	return $PDOStatement->fetch(PDO::FETCH_ASSOC);
}

// mysqli_fetch_row() emulation for smoother scripts transition, works as mysqli_fetch_row() but takes a PDOStatement object as argument
function PDO_fetch_row($PDOStatement) {
	return $PDOStatement->fetch(PDO::FETCH_NUM);
}

// mysqli_fetch_object() emulation for smoother scripts transition, works as mysqli_fetch_object() but takes a PDOStatement object as argument
function PDO_fetch_object($PDOStatement) {
	return $PDOStatement->fetch(PDO::FETCH_OBJ);
}

// mysqli_num_rows() emulation for smoother scripts transition, works as mysqli_num_rows() but takes a PDOStatement object as argument
function PDO_num_rows($PDOStatement) {
	return $PDOStatement->rowCount();
}

// mysqli_affected_rows() emulation for smoother scripts transition, works as mysqli_affected_rows() but takes a PDOStatement object as argument
function PDO_affected_rows($dbh) {
	return $dbh->query("SELECT FOUND_ROWS();")->fetchColumn();
}

// mysqli_insert_id() emulation for smoother scripts transition, works as mysqli_insert_id() but takes a PDOStatement object as argument
function PDO_insert_id($PDOStatement) {
	return $PDOStatement->lastInsertId();
}

// mysqli_close() emulation, calls MorePDO::disconnect()
function PDO_close($dbh) {
	if(!($dbh instanceof MorePDO)) {
		trigger_error("Only MorePDO object can gracefully close connection to server with this function.", E_USER_WARNING);
		return False;
	} else {
		return $dbh->disconnect();
	}
}
?>
