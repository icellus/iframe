<?php

namespace sdk\libs;

use sdk\config\Config;
use PDO;
use Katzgrau\KLogger\Logger;
use Exception;
use Throwable;
use InvalidArgumentException;

/**
 * mysql 操作类
 * @author  chenwei
 * @package common_lib
 */
class NewMysqlHelper extends Singleton
{

	/**
	 * The default PDO connection options.
	 * @var array
	 */
	protected $options = [
		PDO::ATTR_TIMEOUT    => 10,
		PDO::ATTR_PERSISTENT => true,
		PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_AUTOCOMMIT => 1,
	];

	/**
	 * 当前 pdo connection 实例
	 * @var \PDO
	 */
	private $pdo = null;

	/**
	 * @var
	 */
	public $connections;

	/**
	 * @var string
	 */
	public $defaultConnection = 'mysql';

	/**
	 * mysql 最后连接时间，对于长连接需要考虑超时的问题
	 * @var int
	 */
	private $last_connect_time = 0;

	/**
	 * @var
	 */
	private $logger;

	/**
	 * @var int
	 */
	private $cur_use_master = 1;// 1当前使用主进行操作 0 当前使用从进行操作
	/**
	 * @var int
	 */
	private $all_use_master = 0;//1 所有的操作都使用主 0 主从分离
	/**
	 * @var array
	 */
	private $sql_log = [];

	/**
	 * 构造
	 * todo 事物机制不可用
	 * todo 主从机制的实现
	 */
	function __construct ()
	{

	}

	/**
	 * @param $db
	 *
	 * @throws \Exception
	 * @throws \Throwable
	 */
	public function selectDB ($db)
	{
		$this->query("use $db;");
	}

	/**
	 * @return array
	 */
	public function getAllConnections ()
	{
		return $this->connections;
	}

	/**
	 * @param       $sql
	 * @param array $vars
	 */
	private function addSqlLog ($sql, $vars = [])
	{
		if (count($this->sql_log) > 50) {
			array_shift($this->sql_log);
		}
		array_push($this->sql_log, [$sql, $vars]);
	}

	/**
	 * @return array
	 */
	public function getSqlLog ()
	{
		return $this->sql_log;
	}

	/**
	 * 获取行数
	 * 不建议使用，请使用 count语句代替
	 *
	 * @param string $sql
	 * @param array  $input_parameters 变量绑定
	 *
	 * @return int
	 * @throws \Throwable
	 */
	public function getRows ($sql, array $input_parameters = [])
	{
		try {
			$PDOStatement = $this->connection()->pdo->prepare($sql);
			$PDOStatement->execute($input_parameters);
			$efnum = $PDOStatement->rowCount();
		} catch (\PDOException $e) {
			throw $e;
		}

		return intval($efnum);
	}

	/**
	 * 执行增、删、改操作
	 *
	 * @param string $sql
	 * @param array  $input_parameters 变量绑定
	 *
	 * @return number
	 * @throws \Throwable
	 */
	private function query ($sql, array $input_parameters = [])
	{
		try {
			$this->addSqlLog($sql, $input_parameters);
			$PDOStatement = $this->connection()->pdo->prepare($sql);
			$PDOStatement->execute($input_parameters);
			$efnum = $PDOStatement->rowCount();
		} catch (\PDOException $e) {
			$log_path     = SDK_PATH . "/../log/sql/";
			$this->logger = new Logger($log_path, Config::logLevel());
			$this->logger->error("sql query:" . $sql . "\tvars:" . json_encode($input_parameters) . "\terror" . $e->getCode() . ":" . $e->getMessage());

			throw $e;
		}

		return $efnum;
	}

	/**
	 * @param       $sql
	 * @param int   $lastid
	 * @param array $input_parameters
	 *
	 * @return number|string
	 * @throws \Throwable
	 */
	public function insert ($sql, $lastid = 0, array $input_parameters = [])
	{
		if ($lastid == 1) {
			$this->query($sql, $input_parameters);

			return $id = $this->connection()->pdo->lastinsertid();
		}

		return $this->query($sql, $input_parameters);
	}

	/**
	 * 插入数组
	 *
	 * @param  [type] $table [description]
	 * @param  array $data [description]
	 *
	 * @return bool|number [type]        [description]
	 * @throws \Throwable
	 */
	public function insertAtrr ($table, array $data)
	{
		if (empty($data)) {
			return false;
		}
		$sql          = "describe $table";
		$filed_list   = $this->getAll($sql);
		$field_string = '';
		$value_string = '';
		$params       = [];
		foreach ($data as $key => $val) {
			foreach ($filed_list as $row) {
				if ($row['Field'] == $key) {
					$params[ ":_PRE_" . $key ] = $val;
					$field_string              .= ',`' . $key . '`';
					$value_string              .= ', :_PRE_' . $key;
				}
			}
		}
		$field_string = trim($field_string, ',');
		$value_string = trim($value_string, ',');
		if (empty($field_string) || empty($value_string) || empty($params)) {
			return false;
		}
		$sql = "insert into $table ($field_string) values($value_string)";
		$id  = $this->insert($sql, 1, $params);

		return $id;
	}

	/**
	 * 更新数组
	 *
	 * @param        $table
	 * @param        $data
	 * @param string $condition
	 * @param array  $input_parameters
	 *
	 * @return bool|number [type]        [description]
	 * @throws \Throwable
	 */
	public function updateAtrr ($table, $data, $condition = '', $input_parameters = [])
	{
		if (empty($data)) {
			return false;
		}
		$sql          = "describe $table";
		$filed_list   = $this->getAll($sql);
		$set_string   = '';
		$where_string = '';
		$params       = [];
		foreach ($data as $key => $val) {
			foreach ($filed_list as $row) {
				if ($row['Field'] == $key) {
					if ($row['Key'] == 'PRI' && !$condition) {
						$where_string              = "`{$key}` = :_PRE_{$key}";
						$params[ ":_PRE_" . $key ] = $val;
					} else {
						$set_string                .= "`{$key}` = :_PRE_{$key},";
						$params[ ":_PRE_" . $key ] = $val;
					}
				}
			}
		}
		$set_string = trim($set_string, ',');
		if ($condition) {
			$where_string = $condition;
			$params       = array_merge($params, $input_parameters);
		}
		if (empty($set_string) || empty($where_string) || empty($params)) {
			return false;
		}
		$sql = "update {$table} set {$set_string} where {$where_string} limit 1";

		$ret = $this->update($sql, $params);

		return $ret;
	}

	/**
	 * @param       $sql
	 * @param array $input_parameters
	 *
	 * @return number
	 * @throws \Throwable
	 */
	public function update ($sql, array $input_parameters = [])
	{
		return $this->query($sql, $input_parameters);
	}

	/**
	 * @param       $sql
	 * @param array $input_parameters
	 *
	 * @return number
	 * @throws \Throwable
	 */
	public function delete ($sql, array $input_parameters = [])
	{
		return $this->query($sql, $input_parameters);
	}

	/**
	 * 创建数据表
	 *
	 * @param $sql
	 *
	 * @return number
	 * @throws \Throwable
	 */
	public function createTable ($sql)
	{
		return $this->query($sql);
	}

	/**
	 * 获取单条记录
	 *
	 * @param string $sql
	 * @param array  $input_parameters 变量绑定
	 *
	 * @return array
	 * @throws \Throwable
	 */
	public function getOne ($sql, array $input_parameters = [])
	{
		if (!preg_match("/limit/i", $sql)) {
			$sql = preg_replace("/[,;]$/i", '', trim($sql)) . " limit 1 ";
		}

		$res = [];
		try {
			$PDOStatement = $this->connection()->pdo->prepare($sql);
			$PDOStatement->execute($input_parameters);
			$return = $PDOStatement->fetch(PDO::FETCH_ASSOC);
			$return && $res = $return;
		} catch (\PDOException $e) {
			$log_path     = SDK_PATH . "/../log/sql/";
			$this->logger = new Logger($log_path, Config::logLevel());
			$this->logger->error("sql getOne:" . $sql . "\tvars:" . json_encode($input_parameters) . "\terror" . $e->getCode() . ":" . $e->getMessage());
			throw $e;
		}

		return $res;
	}

	/**
	 * 查询整个表的数据，返回结果数组
	 *
	 * @param string $sql
	 * @param array  $input_parameters 变量绑定
	 *
	 * @return array
	 * @throws \Throwable
	 */
	public function getAll ($sql, array $input_parameters = [])
	{
		try {
			$PDOStatement = $this->connection()->pdo->prepare($sql);
			$PDOStatement->execute($input_parameters);
			$res = $PDOStatement->fetchAll(PDO::FETCH_ASSOC);
		} catch (\PDOException $e) {
			$log_path     = SDK_PATH . "/../log/sql/";
			$this->logger = new Logger($log_path, Config::logLevel());
			$this->logger->error("sql getAll:" . $sql . "\tvars:" . json_encode($input_parameters) . "\terror" . $e->getCode() . ":" . $e->getMessage());
			throw $e;
		}

		return $res;
	}

	/**
	 * 获取最后插入的id
	 *
	 * @return null|string
	 * @throws \Throwable
	 */
	public function getLastId ()
	{
		$id = $this->connection()->pdo->lastInsertId();

		if (!empty($id)) {
			return $id;
		}

		return null;
	}

	/**
	 * Get the default connection name.
	 * @return string
	 */
	public function getDefaultConnection ()
	{
		return $this->defaultConnection;
	}

	/**
	 * Get a database connection instance.
	 *
	 * @param  string $name
	 *
	 * @return \sdk\libs\NewMysqlHelper
	 * @throws \Throwable
	 */
	public function connection ($name = null)
	{
		$name = $name ? : $this->getDefaultConnection();

		// 如果该连接未创建，取其配置创建
		if (!isset($this->connections[ $name ])) {
			$this->connections[ $name ] = $this->configure(
				$this->makeConnection($name)
			);
		}

		return $this;
	}

	/**
	 * Prepare the database connection instance.
	 *
	 * @param \PDO $connection
	 *
	 * @return \sdk\libs\NewMysqlHelper
	 */
	protected function configure (PDO $connection)
	{

		$this->pdo = $connection;

		return $this;
		// todo 封装，重连机制
		/*// 当前pdo实例
		$connection = $connection;

		// 重连机制
		$connection->setReconnector(function($connection) {
			$this->reconnect($connection->getName());
		});

		return $connection;*/
	}

	/**
	 * Set the reconnect instance on the connection.
	 *
	 * @param  callable $reconnector
	 *
	 * @return $this
	 */
	public function setReconnector (callable $reconnector)
	{
		$this->reconnector = $reconnector;

		return $this;
	}

	/**
	 * Make the database connection instance.
	 *
	 * @param  string $name
	 *
	 * @return \PDO
	 * @throws \Throwable
	 */
	protected function makeConnection ($name)
	{
		$config = $this->configuration($name);

		return $connector = $this->connect($config);
	}

	/**
	 * Get the configuration for a connection.
	 *
	 * @param  string $name
	 *
	 * @return array
	 * @throws \InvalidArgumentException
	 * @throws \Exception
	 */
	protected function configuration ($name)
	{
		$name = $name ? : $this->getDefaultConnection();

		// 从配置文件中读取相应的配置
		// todo 配置文件加载机制
		// todo 校验配置文件是否有误
		$connections = Config::load_config_file(
			CONFIG_FILE,
			"can't find config file!!! filename:" . CONFIG_FILE
		);

		if (array_key_exists($name, $connections)) {
			throw new InvalidArgumentException("Database [{$name}] not configured.");
		}

		return $connections[ $name ];
	}

	/**
	 * @param array $config
	 *
	 * @return \PDO
	 * @throws \Throwable
	 */
	private function connect (array $config)
	{
		$host = $config['host'] ?? null;

		$dsn = isset($port) ? "mysql:host={$host};port={$port};" : "mysql:host={$host}";

		$this->createConnection($dsn, $config);

		if (!empty($config['database'])) {
			$this->selectDB($config['database']);
		}

		// 设置字符集及编码方式
		$this->configureEncoding($config);

		// 设置时区
		$this->configureTimezone($config);

		return $this->pdo;
	}

	/**
	 * Create a new PDO connection.
	 *
	 * @param        $dsn
	 * @param  array $config
	 *
	 * @return void
	 * @throws \Throwable
	 */
	private function createConnection ($dsn, array $config)
	{
		list($username, $password) = [
			$config['username'] ?? null, $config['password'] ?? null,
		];

		$options = $this->getOptions();

		try {
			$this->connection        = @new PDO($dsn, $username, $password, $options);
			$info                    = [
				$dsn, $username, $password, $this->options, time(),
			];
			$this->connection->info  = $info;
			$this->last_connect_time = time();
		} catch (\PDOException $e) {
			$this->tryAgainIfCausedByLostConnection(
				$e, $dsn, $config
			);
		}
	}

	/**
	 * Handle an exception that occurred during connect execution.
	 *
	 * @param  \Throwable $e
	 * @param  string     $dsn
	 * @param array       $config
	 *
	 * @return void
	 * @throws \Throwable
	 */
	private function tryAgainIfCausedByLostConnection (Throwable $e, $dsn, array $config)
	{
		$log_path     = SDK_PATH . "/../log/sql/" . PJNAME . '/';
		$this->logger = new Logger($log_path, Config::logLevel());
		$this->logger->error("connect error:" . $dsn . "\terror" . $e->getCode() . ":" . $e->getMessage());

		if (stripos($e->getMessage(), 'server has gone away') !== false) {
			$this->createConnection($dsn, $config);

			return;
		}

		throw $e;
	}

	/**
	 * Set the connection character set and collation.
	 *
	 * @param  array $config
	 *
	 * @return \PDO |void
	 */
	protected function configureEncoding (array $config)
	{
		if (!isset($config['charset'])) {
			return;
		}

		$collation = isset($config['collation']) ? " collate '{$config['collation']}'" : '';
		$this->pdo->prepare(
			"set names '{$config['charset']}'" . $collation
		)->execute();
	}

	/**
	 * Set the timezone on the connection.
	 *
	 * @param  array $config
	 *
	 * @return void
	 */
	protected function configureTimezone (array $config)
	{
		if (isset($config['timezone'])) {
			$this->pdo->prepare('set time_zone="' . $config['timezone'] . '"')->execute();
		}
	}

	/**
	 * Get the default PDO connection options.
	 * @return array
	 */
	public function getOptions ()
	{
		return $this->options;
	}

	/**
	 * Set the default PDO connection options.
	 *
	 * @param  array $options
	 *
	 * @return void
	 */
	public function setOptions (array $options)
	{
		$this->options = $options;
	}

	/**
	 * 开启所有操作都从master走
	 * 一定要在执行完后运行 disableAllMaster
	 * @return void
	 */
	public function enableAllMaster ()
	{
		$this->all_use_master = 1;
	}

	/**
	 * 禁用　所有操作都从master走，回到正常的读写分离模式
	 * @return void
	 */
	public function disableAllMaster ()
	{
		$this->all_use_master = 0;
	}

	/**
	 * 开始事务 注意此处会隐式调用enableAllMaster
	 * @return bool
	 * @throws \Exception
	 */
	public function beginTransaction ()
	{
		$this->all_use_master = 1;//开启事务的全走master
		$this->connection->beginTransaction();
		$this->connection->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);

		return true;
	}

	/**
	 * 提交事务  注意此处会隐式调用disableAllMaster
	 */
	public function commit ()
	{
		$this->connection->commit();
		$this->connection->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);

		$this->all_use_master = 0;//提交后禁用全走master

		return true;
	}

	/**
	 * 回滚事务  注意此处会隐式调用disableAllMaster
	 *
	 * @param string $connection
	 *
	 * @return bool
	 */
	public function rollback ($connection = "")
	{
		$tmp_connection = $this->connection;
		if (!empty($connection)) {
			$this->connection = $connection;
		}

		$this->connection->rollback();
		$this->connection->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);

		$this->all_use_master = 0;//提交后禁用全走master
		$this->connection     = $tmp_connection;

		return true;
	}

	/**
	 * Executes callback provided in a transaction.
	 *
	 * @param callable $callback a valid PHP callback that performs the job. Accepts connection instance as parameter.
	 *
	 * @throws Exception
	 * @return mixed result of callback function
	 */
	public function transaction (callable $callback)
	{
		$this->beginTransaction();
		try {
			$result = call_user_func($callback, $this);
			$this->commit();
		} catch (\PDOException $e) {
			$this->rollback();
			throw $e;
		}

		return $result;
	}

	/**
	 * @param string $defaultConnection
	 */
	public function setDefaultConnection (string $defaultConnection): void
	{
		$this->defaultConnection = $defaultConnection;
	}

}
