<?php

namespace ApiDaemon;

use PDO;
use PDOException; // Correctly imports the global PDOException class
use ApiDaemon\Log;  // Imports your Log helper class


class DB {
	private static $_instances =[];
	private $_pdo, $_query, $_error = false, $_results, $_resultsArray, $_count = 0, $_lastId, $_queryCount=0;

    private function __construct($name = 'mysql') {
        // Determine the prefix for the environment variables based on the connection name.
        // For the default 'mysql' connection, we use 'DB_'.
        $prefix = ($name === 'mysql') ? 'DB_' : strtoupper($name) . '_DB_';

        // Get connection details from environment variables.
        $host = $_ENV[$prefix . 'HOST'] ?? '';
        $dbname = $_ENV[$prefix . 'NAME'] ?? '';
        $user = $_ENV[$prefix . 'USER'] ?? '';
        $pass = $_ENV[$prefix . 'PASS'] ?? '';

        if (empty($host) || empty($dbname)) {
            die("Database configuration not found for connection: '{$name}'. Please check your .env file.");
        }
        try {
            $this->_pdo = new PDO(
                'mysql:host=' . $host . ';charset=utf8;dbname=' . $dbname,
                $user,
                $pass,
                [PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = ''"]
            );
        } catch(PDOException $e) {
            // In a real application, you would log this error instead of dying.
            Log::error("Database connection failed for '{$name}'.", ['error' => $e->getMessage()]);
            die("Database connection failed: " . $e->getMessage());
        }
    }
    public static function getInstance($name = 'mysql') {
        if (!isset(self::$_instances[$name])) {
            self::$_instances[$name] = new DB($name);
        }
        return self::$_instances[$name];
    }

    public function query($sql, $params = array()){
        $this->_queryCount++;
        $this->_error = false; // Reset error state

        if ($this->_query = $this->_pdo->prepare($sql)) {
            if (!empty($params)) {
                $x = 1;
                foreach ($params as $param) {
                    $this->_query->bindValue($x, $param);
                    $x++;
                }
            }
            try {
                $this->_query->execute();
                $this->_results = $this->_query->fetchAll(PDO::FETCH_OBJ);
                $this->_resultsArray = json_decode(json_encode($this->_results), true);
                $this->_count = $this->_query->rowCount();
                $this->_lastId = $this->_pdo->lastInsertId();
            } catch (PDOException $e) {
                $this->_error = true;
                if ($e->getCode() !== '23000') {
                    Log::error("DB Query Error", ['sql' => $sql, 'error' => $e->getMessage()]);
                }
            }
        } else {
            $this->_error = true;
            Log::error("DB Prepare Error", ['sql' => $sql]);
        }
        return $this;
    }
    
    public function results($assoc = false){
        if($assoc) return $this->_resultsArray;
        return $this->_results;
    }


	public function findAll($table){
		return $this->action('SELECT *',$table);
	}

	public function findById($id,$table){
		return $this->action('SELECT *',$table,array('id','=',$id));
	}

	public function action($action, $table, $where = array()){
		$sql = "{$action} FROM {$table}";
		$value = '';
		if (count($where) === 3) {
			$operators = array('=', '>', '<', '>=', '<=');

			$field = $where[0];
			$operator = $where[1];
			$value = $where[2];

			if(in_array($operator, $operators)){
				$sql .= " WHERE {$field} {$operator} ?";
			}
		}
		if (!$this->query($sql, array($value))->error()) {
			return $this;
		}
		return false;
	}

	public function get($table, $where){
		return $this->action('SELECT *', $table, $where);
	}

	public function delete($table, $where){
		return $this->action('DELETE', $table, $where);
	}

	public function deleteById($table,$id){
		return $this->action('DELETE',$table,array('id','=',$id));
	}

	public function insert($table, $fields = array()){
		$keys = array_keys($fields);
		$values = null;
		$x = 1;

		foreach ($fields as $field) {
			$values .= "?";
			if ($x < count($fields)) {
				$values .= ', ';
			}
			$x++;
		}

		$sql = "INSERT INTO {$table} (`". implode('`,`', $keys)."`) VALUES ({$values})";
		
		if (!$this->query($sql, $fields)->error()) {
			return true;
		}
		return false;
	}

	public function update($table, $id, $fields){
		$set = '';
		$x = 1;

		foreach ($fields as $name => $value) {
			$set .= "{$name} = ?";
			if ($x < count($fields)) {
				$set .= ', ';
			}
			$x++;
		}

		$sql = "UPDATE {$table} SET {$set} WHERE id = {$id}";

		if (!$this->query($sql, $fields)->error()) {
			return true;
		}
		return false;
	}
	public function updateVehicle($table, $id, $fields){
		$set = '';
		$x = 1;

		foreach ($fields as $name => $value) {
			$set .= "{$name} = ?";
			if ($x < count($fields)) {
				$set .= ', ';
			}
			$x++;
		}

		$sql = "UPDATE {$table} SET {$set} WHERE VIN = {$id}";

		if (!$this->query($sql, $fields)->error()) {
			return true;
		}
		return false;
	}

	public function first(){
		return $this->results()[0];
	}

	public function count(){
		return $this->_count;
	}

	public function error(){
		return $this->_error;
	}

	public function lastId(){
		return $this->_lastId;
	}
	
	public function getQueryCount(){
		return $this->_queryCount;
	}	
	
}
