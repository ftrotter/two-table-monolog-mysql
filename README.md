two-table-monolog-mysql
=============

This is a fork of the monolog-mysql project to implement two important features that I need: 

* Specify the database, and not just the table(s) on setup. 
* Logs to two different tables, one for the messages and another for the context arrays.

# Installation
monolog-mysql is available via composer. Just add the following line to your required section in composer.json and do a `php composer.phar update`.

```
"ftrotter/two-table-monolog-mysql": ">0.0.1"
```

# Usage
Just use it as any other Monolog Handler, push it to the stack of your Monolog Logger instance. The Handler however needs some parameters:

- **$pdo** PDO Instance of your database. Pass along the PDO instantiation of your database connection with your database selected.
- **$database** The name of the database where the logs should be stored
- **$message_table** The table name where the message logs should be stored
- **$context_table** The table name where the context logs should be stored
- **$level** can be any of the standard Monolog logging levels. Use Monologs statically defined contexts. _Defaults to Logger::DEBUG_
- **$bubble** _Defaults to true_

# Examples
Given that $pdo is your database instance, you could use the class as follows:

```php
//Import class
use TwoMySQLHandler\TwoMySQLHandler;

//Create MysqlHandler
$mySQLHandler = new TwoMySQLHandler($pdo,"log_db", "log_message", "log_context", array('username', 'userid'), \Monolog\Logger::DEBUG);

$context = ['not_sure', 'what_goes_here']; //not clear to me how this works

//Create logger
$logger = new \Monolog\Logger($context);
$logger->pushHandler($mySQLHandler);

//Now you can use the logger, and further attach additional information
$logger->addWarning("This is a great message, woohoo!", array('username'  => 'John Doe', 'userid'  => 245));
```

# License
This tool is free software and is distributed under the MIT license. Please have a look at the LICENSE file for further information.
