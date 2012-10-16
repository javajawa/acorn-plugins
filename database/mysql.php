<?php
namespace Acorn\Mysql;

/**
 * 	database.php
 * 	Holds the database class.
 * 	Database
 *
 * 	Updated 2012.07.17 to use MySQLi over MySQL.
 */
class Database
{
	/**
	 * @var \mysqli
	 */
	protected $mysqli;
	protected $nsProcedure;
	protected $nsEntities;

	public function __construct($host, $user, $pass, $db, $procedureNamespace = '', $entityNamesapce = '')
	{
		$this->mysqli = @new \mysqli($host, $user, $pass, $db);

		if (0 !== $this->mysqli->connect_errno)
			throw new DatabaseConnectionException($this->mysqli->connect_error, $this->mysqli->connect_errno);

		if ('\\' !== substr($entityNamesapce, -1))
			$entityNamesapce .= '\\';

		$this->nsEntities  = $entityNamesapce;
		$this->nsProcedure = $procedureNamespace;
	}

	public function __call($name, $arguments)
	{
		$name = $this->nsProcedure . $name;
		array_unshift($arguments, $name);
		return call_user_func_array(array(&$this, 'storedProcedure'), $arguments);
	}

	public function getError()
	{
		return $this->mysqli->error . $this->mysqli->get_warnings();
	}

	public function storedProcedure($procedure, array $params = array(), $entityClass = null)
	{
		// Create the statement
		if (0 === count($params))
			$statement = sprintf('call %s()', $procedure);
		else
			$statement = sprintf('call %s(%s%s)', $procedure, str_repeat('?, ', count($params) - 1), '?');

		$statement = $this->mysqli->prepare($statement);

		if (0 < count($params))
		{
			// Generate the type info for paramters
			$types = '';
			foreach ($params as $i => &$p)
			{
				if (is_integer($p) || is_bool($p))
					$types .= 'i';
				else if (is_double($p))
					$types .= 'd';
				else if (is_string($p))
					$types .= 's';
				else if (is_null($p))
					$types .= 'i'; // Nulls can be of any type really, but int will do
				else
					throw new \Exception('Unable to bind param of type ' . ('object' === gettype($p) ? get_class($p) : gettype($p)) . ' at index ' . $i);
			}

			// Bind the parameters
			array_unshift($params, $types);
			call_user_func_array(array(&$statement, 'bind_param'), $this->makeReferenced($params));
		}

		// Execute the statement
		$success = $statement->execute();
		if (false === $success)
		{
			throw new DatabaseException($statement->error, $statement->errno, $statement->sqlstate, $procedure, $params, null);
		}

		if (false === empty($entityClass) && '\\' !== substr($entityClass, 0, 1))
			$entityClass = $this->nsEntities . $entityClass;

		$result = $statement->get_result();

		if (is_bool($result))
			return $success;

		$result = new Result($result, $entityClass);
		return $result;
	}

	private function makeReferenced(array $arr)
	{
		$refs = array();

		foreach($arr as $key => $value)
	        $refs[$key] = &$arr[$key];

	    return $refs;
		(object)$value; // Unused variable
	}

	/**
	 * Empty static functinon for loading this file for use of the Entity
	 * classes when the database is not itself used.
	 */
	public static function init()
	{
	}
}

class Result implements \Countable, \Iterator, \ArrayAccess
{
	/**
	 * @var \mysqli_result
	 */
	protected $statement;
	/**
	 * @var int
	 */
	protected $rows;
	protected $class;

	protected $currentRow;
	protected $currentObject;

	public function __construct(\mysqli_result &$statement, $class = null)
	{
		$this->statement = $statement;
		$this->rows = $statement->num_rows;
		$this->class = $class === null ? 'stdClass' : $class;
		$this->rewind();
	}

	public function count()
	{
		return $this->rows;
	}

	public function current()
	{
		return $this->currentObject;
	}

	public function key()
	{
		return $this->currentRow;
	}

	public function next()
	{
		++$this->currentRow;
		$this->currentObject = $this->statement->fetch_object($this->class);
	}

	public function rewind()
	{
		$this->statement->data_seek(0);
		$this->currentRow = -1;
		$this->next();
	}

	public function valid()
	{
		return (null !== $this->currentObject);
	}

	public function offsetExists($offset)
	{
		return (0 <= $offset && $offset < $this->rows);
	}

	public function offsetGet($offset)
	{
		$this->statement->data_seek($offset);
		$object = $this->statement->fetch_object($this->class);
		$this->statement->data_seek($this->currentRow);
		return $object;
	}

	public function offsetSet($offset, $value)
	{
		throw new \Exception('Database result in non-writeable');
		(int)$offset;(object)$value; // Unused variables
	}

	public function offsetUnset($offset)
	{
		throw new \Exception('Database result in non-writeable');
		(int)$offset; // unused variable
	}

	public function singleton()
	{
		if (1 === $this->rows)
			return $this[0];

		return null;
	}
}

class DatabaseException extends \Exception
{
	protected $procedure;
	protected $paramters;
	protected $sqlStatus;

	public function __construct($message, $code, $state, $procedure, array $paramters, $previous)
	{
		parent::__construct($message, $code, $previous);
		$this->procedure = $procedure;
		$this->paramters = $paramters;
		$this->sqlStatus = $state;
	}
}

class DatabaseConnectionException extends DatabaseException
{
	public function __construct($message, $code)
	{
		parent::__construct($message, $code, 0, 'mysqli_connect', array(), null);
	}
}

abstract class Entity
{
	public function __get($name)
	{
		if (property_exists($this, $name))
		{
			return $this->$name;
		}
		throw new \Exception(sprintf('Property %s does not exist in Entity %s', $name, get_class($this)));
	}

	public function __isset($name)
	{
		return property_exists($this, $name);
	}

	public function __unset($name)
	{
		if (property_exists($this, $name))
			$this->$name = null;
	}
}

abstract class MutableEntity extends Entity
{
	public function __set($name, $value)
	{
		if (property_exists($this, $name))
			$this->$name = $value;
		else
			throw new \Exception(sprintf('Property %s does not exist in Entity %s', $name, get_class($this)));
	}
}

