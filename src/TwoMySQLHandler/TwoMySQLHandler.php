<?php

namespace TwoMySQLHandler;

use Monolog\Logger;
use Monolog\Handler\AbstractProcessingHandler;
use PDO;
use PDOStatement;

/**
 * This class is a handler for Monolog, which can be used
 * to write records to two MySQL tables
 *
 * Class TwoMySQLHandler
 * @package ftrotter\TwoMysqlHandler
 */
class TwoMySQLHandler extends AbstractProcessingHandler
{

    /**
     * @var bool defines whether the MySQL connection is been initialized
     */
    private $initialized = false;

    /**
     * @var PDO pdo object of database connection
     */
    protected $pdo;

    /**
     * @var PDOStatement statement to insert a new message record
     */
    private $statement;

    /**
     * @var PDOStatement statement to insert a new context record
     */
    private $context_statement;

    /**
     * @var string the table to store the log messages
     */
    private $message_table = 'log_message';
	
    /**
     * @var string the table to store the log context
     */
    private $context_table = 'log_context';
	
    /**
     * @var string the database to store the logs in
     */
    private $log_database = 'log_db';
	

    /**
     * @var array default fields that are stored in db
     */
    private $defaultfields = array('id', 'channel', 'level', 'message', 'created_at');

    /**
     * @var string[] additional fields to be stored in the database
     *
     * For each field $field, an additional context field with the name $field
     * is expected along the message, and further the database needs to have these fields
     * as the values are stored in the column name $field.
     */
    private $additionalFields = array();

    /**
     * @var array
     */
    private $fields           = array();


    /**
     * Constructor of this class, sets the PDO and calls parent constructor
     *
     * @param PDO $pdo                  PDO Connector for the database
     * @param bool $table               Table in the database to store the logs in
     * @param array $additionalFields   Additional Context Parameters to store in database
     * @param bool|int $level           Debug level which this handler should store
     * @param bool $bubble
     */
    public function __construct(
        PDO $pdo = null,
	$log_db,
        $message_table,
	$context_table,
        $additionalFields = array(),
        $level = Logger::DEBUG,
        $bubble = true
    ) {
       if (!is_null($pdo)) {
            $this->pdo = $pdo;
        }
        $this->message_table = $message_table;
        $this->context_table = $context_table;
        $this->log_database = $log_db;
        $this->additionalFields = $additionalFields;
        parent::__construct($level, $bubble);
    }

    /**
     * Initializes this handler by creating the table if it not exists
     */
    private function initialize()
    {
     
       $create_message_table_sql = "
CREATE TABLE IF NOT EXISTS `$this->log_database`.`$this->message_table` (
	id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY, 
	channel VARCHAR(255), 
	level INTEGER, 
	message LONGTEXT, 
	created_at datetime DEFAULT CURRENT_TIMESTAMP(),
	INDEX(channel), 
	INDEX(level), 
	INDEX(created_at)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;  ";

       $this->pdo->exec($create_message_table_sql);

	$create_context_table_sql = "
CREATE TABLE IF NOT EXISTS `$this->log_database`.`$this->context_table` (
	id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY, 
	message_id BIGINT(20), 
	context_key VARCHAR(255),
	context_value LONGTEXT,
	INDEX(message_id),
	INDEX(context_key)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;	
";


        $this->pdo->exec( $create_context_table_sql );

        //Read out actual columns
        $actualFields = array();
        $rs = $this->pdo->query('SELECT * FROM `'.$this->log_database.'`.`'.$this->message_table.'` LIMIT 0');
        for ($i = 0; $i < $rs->columnCount(); $i++) {
            $col = $rs->getColumnMeta($i);
            $actualFields[] = $col['name'];
        }

        //Calculate changed entries
        $removedColumns = array_diff(
            $actualFields,
            $this->additionalFields,
            $this->defaultfields
        );
        $addedColumns = array_diff($this->additionalFields, $actualFields);

        //Remove columns
        if (!empty($removedColumns)) {
            foreach ($removedColumns as $c) {
                $this->pdo->exec('ALTER TABLE `'.$this->log_database.'`.`'.$this->message_table.'` DROP `'.$c.'`;');
            }
        }

        //Add columns
        if (!empty($addedColumns)) {
            foreach ($addedColumns as $c) {
                $this->pdo->exec('ALTER TABLE `'.$this->log_database.'`.`'.$this->message_table.'` add `'.$c.'` TEXT NULL DEFAULT NULL;');
            }
        }

        // merge default and additional field to one array
        $this->defaultfields = array_merge($this->defaultfields, $this->additionalFields);

        $this->initialized = true;
    }

    /**
     * Prepare the sql statment depending on the fields that should be written to the database
     */
    private function prepareStatement()
    {
        //Prepare statement
        $columns = "";
        $fields  = "";
        foreach ($this->fields as $key => $f) {
            if ($f == 'id') {
                continue;
            }
            if ($key == 1) {
                $columns .= "$f";
                $fields .= ":$f";
                continue;
            }

            $columns .= ", $f";
            $fields .= ", :$f";
        }

	$sql_to_prepare = 'INSERT INTO `'.$this->log_database.'`.`' . $this->message_table . '` (' . $columns . ') VALUES (' . $fields . ')';
	
        $this->statement = $this->pdo->prepare(
		$sql_to_prepare
        );
    }


    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  $record[]
     * @return void
     */
    protected function write(array $record)
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        /**
         * reset $fields with default values
         */
        $this->fields = $this->defaultfields;

	$created_at = date('Y-m-d H:i:s');

        //'context' contains the array
        $contentArray = [
                                        'channel' => $record['channel'],
                                        'level' => $record['level'],
                                        'message' => $record['message'],
                                        'created_at' => $created_at,
			];                         


        $this->prepareStatement();

        //Fill content array with "null" values if not provided

        $this->statement->execute($contentArray);

	$message_id = $this->pdo->lastInsertId(); //should I be using a 'name' argument? I think so...

	
	foreach($record['context'] as $context_key =>  $context_value){
		$this->saveContextRow($message_id, $context_key, $context_value);
	}		

    }

	protected function saveContextRow($message_id, $context_key, $context_value){

		if(is_null($this->context_statement)){

			$sql_to_prepare = "
INSERT INTO `$this->log_database`.`$this->context_table` (
	message_id, context_key, context_value 
	) VALUES (
	:message_id, :context_key, :context_value
)";
	
      	  		$this->context_statement = $this->pdo->prepare(
				$sql_to_prepare
        		);
		} 

		//no matter what here we should have the prepared statement ready..
		//most of the time it will just be this..
		$this->context_statement->execute(['message_id' => $message_id, 'context_key' => $context_key, 'context_value' => $context_value]);
	
	
	}

}
