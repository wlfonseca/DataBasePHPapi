<?php

class DB_API {

	public $dbs = array();
	public $db = null;
	public $dbh = null;
	public $query = array();
	static $instance;
	public $ttl = 30;
	public $cache = array();
	public $connections = array();

	function __construct() {

		self::$instance = &$this;

	}

	/** 
	 * Returns static reference to the class instance
	 */
	public static function &get_instance() {

		return self::$instance;

	}

	/**
	 * Register a new dataset
	 * @param string $name the dataset name
	 * @param array $args the dataset properties
	 */
	function register_db( $name = null, $args = array() ) {

		$defaults = array(
			'name' => null,
			'username' => 'root',
			'password' => 'root',
			'server' => 'localhost',
			'port' => 3306,
			'type' => 'mysql',
			'table_blacklist' => array(),
			'column_blacklist' => array(),
			'ttl' => $this->ttl,
		);

		$args = shortcode_atts( $defaults, $args );
		$name = $this->slugify( $name );

		$this->dbs[$name] = (object) $args;

	}
	
function json_validate($string)
{
    // decode the JSON data
    $result = json_decode($string);

    // switch and check possible JSON errors
    switch (json_last_error()) {
        case JSON_ERROR_NONE:
            $error = ''; // JSON is valid // No error has occurred
            break;
        case JSON_ERROR_DEPTH:
            $error = 'The maximum stack depth has been exceeded.';
            break;
        case JSON_ERROR_STATE_MISMATCH:
            $error = 'Invalid or malformed JSON.';
            break;
        case JSON_ERROR_CTRL_CHAR:
            $error = 'Control character error, possibly incorrectly encoded.';
            break;
        case JSON_ERROR_SYNTAX:
            $error = 'Syntax error, malformed JSON.';
            break;
        // PHP >= 5.3.3
        case JSON_ERROR_UTF8:
            $error = 'Malformed UTF-8 characters, possibly incorrectly encoded.';
            break;
        // PHP >= 5.5.0
        case JSON_ERROR_RECURSION:
            $error = 'One or more recursive references in the value to be encoded.';
            break;
        // PHP >= 5.5.0
        case JSON_ERROR_INF_OR_NAN:
            $error = 'One or more NAN or INF values in the value to be encoded.';
            break;
        case JSON_ERROR_UNSUPPORTED_TYPE:
            $error = 'A value of a type that cannot be encoded was given.';
            break;
        default:
            $error = 'Unknown JSON error occured.';
            break;
    }

    if ($error !== '') {
        // throw the Exception or exit // or whatever :)
        exit($error);
    }

    // everything is OK
    return $result;
}
	

	/**
	 * Retrieves a database and its properties
	 * @param string $db the DB slug (optional)
	 * @return array the database property array
	 */
	function get_db( $db = null ) {

		if ( $db == null && !is_null($this->db) ) {
			return $this->db;
		}

		if ( is_object( $db ) ) {
			$db = $db->name;
		}
		
		
		if ( !array_key_exists( $db, $this->dbs ) ) {
			$this->error( 'Invalid Database', 404 );
		}

		return $this->dbs[$db];

	}

	/**
	 * Sets the current database
	 * @param string $db the db slug
	 * @return bool success/fail
	 */
	function set_db( $db = null ) {

		$db = $this->get_db( $db );

		if ( !$db ) {
			return false;
		}
		
		$this->db = $db;

		return true;
		
	}


	/**
	 * Modifies a string to remove all non-ASCII characters and spaces.
	 * http://snipplr.com/view.php?codeview&id=22741
	 */
	function slugify( $text ) {

		// replace non-alphanumeric characters with a hyphen
		$text = preg_replace('~[^\\pL\d]+~u', '-', $text);

		// trim off any trailing or leading hyphens
		$text = trim($text, '-');

		// transliterate from UTF-8 to ASCII
		if (function_exists('iconv')) {
			$text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
		}

		// lowercase
		$text = strtolower($text);

		// remove unwanted characters
		$text = preg_replace('~[^-\w]+~', '', $text);

		// ensure that this slug is unique
		$i=1;
		while ( array_key_exists( $text, $this->dbs ) ) {
			$text .= "-$i";
			$i++;
		}

		return $text;
	}

	/**
	 * Parses rewrite and actual query var and sanitizes
	 * @return array the query arg array
	 * @param $query (optional) a query other than the current query string
	 */
	function parse_query( $query = null ) {

		if ( $query == null ) {
			$query = $_SERVER['QUERY_STRING'];
		}

		parse_str( $query, $parts );

		$defaults = array(
			'db' => null,
			'table' => null,
			'order_by' => null,
			'direction' => 'ASC',
			'column' => null,
			'value' => null,
			'limit' => null,
			'format' => 'json',
			'json' => null,
			'callback' =>  null,
		);

		$parts = shortcode_atts( $defaults, $parts );
		//var_dump()

		if ( $parts['db'] == null ) {
			$this->error( 'Must select a database', 400 );
		}

		if ( $parts['table'] == null ) {
			$this->error( 'Must select a table', 400 );
		}

		$db = $this->get_db( $parts['db'] );

		if ( in_array( $parts['table'], $db->table_blacklist ) ) {
			$this->error( 'Invalid table', 404 );
		}

		if ( !in_array( $parts['direction'], array( 'ASC', 'DESC' ) ) ) {
			$parts['direction'] = null;
		}

		if ( !in_array( $parts['format'], array( 'html', 'xml', 'json' ) ) ) {
			$parts['format'] = null;
		}

		return $parts;

	}

	/**
	 * Establish a database connection
	 * @param string $db the database slug
	 * @return object the PDO object
	 * @todo support port #s and test on each database
	 */
	function &connect( $db ) {
		
		// check for existing connection
		if ( isset( $this->connections[$db] ) ) {
			return $this->connections[$db];
		}
			
		$db = $this->get_db( $db );

		try {
			if ($db->type == 'mysql') {
				$dbh = new PDO( "mysql:host={$db->server};dbname={$db->name}", $db->username, $db->password , array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8") );
			}
			elseif ($db->type == 'pgsql') {
				$dbh = new PDO( "pgsql:host={$db->server};dbname={$db->name}", $db->username, $db->password );
			}
			elseif ($db->type == 'mssql') {
				$dbh = new PDO( "sqlsrv:Server={$db->server};Database={$db->name}", $db->username, $db->password );
			}
			elseif ($db->type == 'sqlite') {
				$dbh = new PDO( "sqlite:/{$db->name}" );
			}
			elseif ($db->type == 'oracle') {
				$dbh = new PDO( "oci:dbname={$db->name}" );
			}
			elseif ($db->type == 'ibm') {
				// May require a specified port number as per http://php.net/manual/en/ref.pdo-ibm.connection.php.
				$dbh = new PDO( "ibm:DRIVER={IBM DB2 ODBC DRIVER};DATABASE={$db->name};HOSTNAME={$db->server};PROTOCOL=TCPIP;", $db->username, $db->password );
			}
			elseif ( ($db->type == 'firebird') || ($db->type == 'interbase') ){
				$dbh = new PDO( "firebird:dbname={$db->name};host={$db->server}" );
			}
			elseif ($db->type == '4D') {
				$dbh = new PDO( "4D:host={$db->server}", $db->username, $db->password );
			}
			elseif ($db->type == 'informix') {
				$dbh = new PDO( "informix:host={$db->server}; database={$db->name}; server={$db->server}", $db->username, $db->password );
			}
			else {
				$this->error('Unknown database type.');
			}
			$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch(PDOException $e) {
			$this->error( $e );
		}

		// cache
		$this->connections[$db->type] = &$dbh;
		
		return $dbh;

	}

	/**
	 * Verify a table exists, used to sanitize queries
	 * @param string $query_table the table being queried
	 * @param string $db the database to check
	 * @param return bool true if table exists, otherwise false
	 */
	function verify_table( $query_table, $db = null ) {
		
		$tables = $this->cache_get( $this->get_db( $db )->name . '_tables' );
		
		if ( !$tables  ) {
		
			$dbh = &$this->connect( $db );
			try { 
				$stmt = $dbh->query( 'SHOW TABLES' );
			} catch( PDOException $e ) {
				$this->error( $e );
			}
			
			$tables = array();
			while ( $table = $stmt->fetch() ) {
				$tables[] = $table[0];
			}
		
		}
		
		return in_array( $query_table, $tables );
		
	}

	/**
	 * Returns an array of all columns in a table
	 * @param string $table the table to check
	 * @param string $db the database to check
	 * @return array an array of the column names
	 */
	function get_columns( $table, $db = null ) {

		if ( !$this->verify_table( $table ) ) {
			return false;
		}
			
		$key = $this->get_db( $db )->name . '.' . $table . '_columns';
		
		if ( $cache = $this->cache_get( $key ) ) {
			return $cache;
		}
			
		$dbh = &$this->connect( $db );
		
		try {
			$q = $dbh->prepare( "DESCRIBE $table" );
			$q->execute();
			$columns = $q->fetchAll(PDO::FETCH_COLUMN);
		} catch( PDOException $e ) {
			$this->error( $e );
		}
		
		$this->cache_set( $key, $columns, $this->get_db( $db )->ttl );
		return $columns;
	}

	/**
	 * Verify a column exists
	 * @param string $column the column to check
	 * @param string $table the table to check
	 * @param string $db (optional) the db to check
	 * @retrun bool true if exists, otherwise false
	 */
	function verify_column( $column, $table, $db = null ) {

		$columns = $this->get_columns( $table, $db );
		return in_array( $column, $columns );

	}

	/**
	 * Returns the first column in a table
	 * @param string $table the table 
	 * @param string $db the datbase slug
	 * @return string the column name
	 */
	function get_first_column( $table, $db = null ) {

		return reset( $this->get_columns( $table, $db ) );

	}

	/**
	 * Build and execute the main database query
	 * @param array $query the database query ASSUMES SANITIZED
	 * @return array an array of results
	 */
	function query( $query, $db = null ) {
	    
	   //   echo '<pre>';
    //         var_dump($query);
           
	   // echo '</pre>';
	
	   $string= $query["json"];            
       if (trim($string) <> ''){
           $json_string =json_decode($string);
           $query_insert['table']='portal_log_bloqueio';
    
           foreach( $json_string as $key => $value ) {
                   
               $query_insert[$key]=$value ;
                   
            }
                
    	    $insert = $this->insert($query_insert);
    	    
       }
       
       

		try {
		    
		    

			$dbh = &$this->connect( $db );

			// sanitize table name
			if ( !$this->verify_table( $query['table'] ) ) {
				$this->error( 'Invalid Table', 404 );
			}

		  $sql = 'SELECT * FROM ' . $query['table'];

			if (is_array($query['column']) ){
			    
			    $sql .= " WHERE `{$query['table']}`.`{$query['column'][0]}` = '{$query['value'][0]}'";
			    
			    for ($i = 1; $i <= count($query['column']) -1; $i++) {
		    	    $sql .= " AND `{$query['table']}`.`{$query['column'][$i]}` = '{$query['value'][$i]}'";
    		    }
    		    
    		    
			}
		
			else 
			{
			    	// santize column name
			    	
			    	
    			if ( $query['column'] ) {
    				if ( !$this->verify_column( $query['column'], 
    				      
    				    $query['table'] ) ) {
    				  //$this->error( 'Away Parameter column', 404 );
    					$query['column'] = null;
    				}
    				
    				if ( $query['value'] && $query['column'] == null ) {
    				$query['column'] = $this->get_first_column( $query['table'] );
    			}
			
			    
			    
			    	$sql .= " WHERE `{$query['table']}`.`{$query['column']}` = '{$query['value']}'";
    				
    		    }
			
    			
			    
			}

			if ( $query['order_by'] && $query['direction'] ) {

				if ( !$this->verify_column( $query['order_by'], $query['table'] ) ) {
					return false;
				}

				$sql .= " ORDER BY `{$query['table']}`.`{$query['order_by']}` {$query['direction']}";

			}

			if ( $query['limit'] ) {
				$sql .= " LIMIT " . (int) $query['limit'];
			}

			$sth = $dbh->prepare( $sql );
		    //var_dump($sql);
		   //die();
			$sth->bindParam( ':value', $query['value'] );
			$sth->execute();

			$results = $sth->fetchAll( PDO::FETCH_OBJ );
			$results = $this->sanitize_results( $results );

		} catch( PDOException $e ) {
			$this->error( $e );
		}
		
		$this->cache_set( $key, $results, $this->get_db( $db )->ttl );
		
		return $results;

	}
		/**
	 * Build and execute the main database query
	 * @param array $query the database query ASSUMES SANITIZED
	 * @return array an array of results
	 */
	function insert( $query, $db = null ) {
		try {
			$dbh = &$this->connect( $db );
			// sanitize table name
			if ( !$this->verify_table( $query['table'] ) ) {
				$this->error( 'Invalid Table', 404 );
			}

		      foreach( $query as $key => $value ) {
                if (($key <> 'db') && ($key <> 'table')) {
                        $values .= ','."'".$value."'";
                        $columns .= ','.$key;
                 }
            
            }
            
            $sql = 'INSERT INTO ' . $query['table'].' ('.substr($columns, 1).') '.' values '. ' ('.substr($values, 1).'); ' ;
        
        	$sth = $dbh->prepare( $sql );
			$sth->bindParam( ':value', $query['value'] );
	
			$sth->execute();

		
		} catch( PDOException $e ) {
			$this->error( $e );
		}
		
		$this->cache_set( $key, $results, $this->get_db( $db )->ttl );
		
		return $results;

	}

	
function delete( $query, $db = null ) {
		$key = md5( serialize( $query ) . $this->get_db( $db )->name );
		if ( $cache = $this->cache_get( $key ) ) {
			return $cache;
		}

		try {

			$dbh = &$this->connect( $db );

			// sanitize table name
			if ( !$this->verify_table( $query['table'] ) ) {
				$this->error( 'Invalid Table', 404 );
			}

            foreach( $query as $key => $value ) {
                if (($key <> 'db') && ($key <> 'table') ) {
                    if (trim($value) == '') {
                        $results['RESULT'] = 'ERROR';
                        $results['TABLE'] = 'Url Inválida na requisição.';
                        $this->error( json_encode($results), 404 );
                    }
                    if (trim($key == 'json')) {
                      $dados = json_decode($value, true);
                      $sql = '';
                      $multivalues = '';
                      foreach ($dados as $index => $val) {
                        $values = '';
                        $columns = '';
                        $covel = '';
                        $first = true;
                        foreach ($val as $i => $var) {
                          if (!$first) { 
                            $covel .= ' AND ';
                          }
                          $covel .= $i.' = '."'".$var."'";    
                          $columns .= ',' . $i;
                          $values .= ',' . "'" . $var . "'";
                          $first = false;
                        }
                         $multivalues .= ','.'('.substr($values, 1).')';
                      }
                    }
                }
                
            }
            $sql = 'DELETE FROM ' . $query['table'].' WHERE '.$covel;
			$sth = $dbh->prepare( $sql );
			$sth->bindParam( ':value', $query['value'] );
			$sth->execute();
		} catch( PDOException $e ) {
			$this->error( $e );
		}
		$this->cache_set( $key, $results, $this->get_db( $db )->ttl );
		return $results;
	}	
	
function replacejson( $query, $db = null ) {
      if ($query['table'] == 'produtosabaixoestoqueminimo'){
      }
      $key = md5( serialize( $query ) . $this->get_db( $db )->name );
		try {
		      $dbh = &$this->connect( $db );
              $values = '';
              $columns = '';
              foreach( $query as $key => $value ) {
                if (($key <> 'db') && ($key <> 'table') ) {
                    if (trim($value) == '') {
                        $results['RESULT'] = 'ERROR';
                        $results['TABLE'] = 'Url Inválida na requisição.';
                        $this->error( json_encode($results), 404 );
                    }
                    if (trim($key == 'json')) {
                      $dados = json_decode($value, true);
                      $sql = '';
                      $multivalues = '';
                      foreach ($dados as $index => $val) {
                        $values = '';
                        $columns = '';
                        $covel = '';
                        foreach ($val as $i => $var) {
                          $covel .= ','.$i.' = '."'".$var."'";    
                          $values .= ',' . "'" . $var . "'";
                          $columns .= ',' . $i;
                        }
                         $multivalues .= ','.'('.substr($values, 1).')';
                      }
                    }
                 }
      }
      //$sql = 'INSERT INTO ' . $query['table'].' ('.substr($columns, 1).') '.' values '. ' '.substr($multivalues, 1).' ON DUPLICATE KEY UPDATE '.substr($covel,1) ;
        
        $sql = 'REPLACE INTO ' . $query['table'].' ('.substr($columns, 1).') '.' values '. ' '.substr($multivalues, 1);
                $sth = $dbh->prepare( utf8_decode($sql) );
               $sth->execute();
                $results = json_encode('SUCCESS '.$sql);
     // $dbh->commit();
		}
    catch( PDOException $e ) {
       // $dbh->query("ROLLBACK;");
			$this->error( $e );
		}
		
		//$dbh->setAttribute(PDO::ATTR_AUTOCOMMIT,1);
		return $results . $sql;
  }

		/**
	 * Build and execute the main database query
	 * @param array $query the database query ASSUMES SANITIZED
	 * @return array an array of results
	 */
  function upsertjson( $query, $db = null ) {
        
      if ($query['table'] == 'produtosabaixoestoqueminimo'){
     
      }

      $key = md5( serialize( $query ) . $this->get_db( $db )->name );


		try {

			$dbh = &$this->connect( $db );

			// sanitize table name
	//		if ( !$this->verify_table( $query['table'] ) ) {
			//	$this->error( 'Invalid Table', 404 );
		//	}

      $values = '';
      $columns = '';

     // $dbh->setAttribute(PDO::ATTR_AUTOCOMMIT,0);  
       // $dbh->beginTransaction();
      foreach( $query as $key => $value ) {
          
        if (($key <> 'db') && ($key <> 'table') ) {
            if (trim($value) == '') {
                $results['RESULT'] = 'ERROR';
                $results['TABLE'] = 'URL Inválida na requisição.';
                $this->error( json_encode($results), 404 );
            }
            if (trim($key == 'json')) {
              $dados = json_decode($value, true);
              $sql = '';
              $multivalues = '';
              foreach ($dados as $index => $val) {
                $values = '';
                $columns = '';
                $covel = '';
                
                foreach ($val as $i => $var) {
                  $covel .= ','.$i.' = '."'".str_replace(",",".",$var)."'";    
                  $values .= ',' . "'" . str_replace(",",".",$var) . "'";
                  $columns .= ',' . $i;
                }
                    $multivalues .= ','.'('.substr($values, 1).')';
              
              }
                
            }
            
         }
      }
      //$sql = 'INSERT INTO ' . $query['table'].' ('.substr($columns, 1).') '.' values '. ' '.substr($multivalues, 1).' ON DUPLICATE KEY UPDATE '.substr($covel,1) ;
        
        $sql = 'REPLACE INTO ' . $query['table'].' ('.substr($columns, 1).') '.' values '. ' '.substr($multivalues, 1);
               
                $sth = $dbh->prepare( utf8_decode($sql) );
                $sth->execute();
                $results['RESULT'] = 'SUCESS';
                $results['TABLE'] = $query['table'];
      //$dbh->query("COMMIT;");
     // $dbh->commit();
		}
    catch( PDOException $e ) {
       // $dbh->query("ROLLBACK;");
		//	$this->error( $e );
			$results['RESULT'] = 'ERROR '.$e;
            $results['TABLE'] = $query['table'];
		}
		
		//$dbh->setAttribute(PDO::ATTR_AUTOCOMMIT,1);
		return json_encode($results);
  }



	function insertorreplace( $query, $db = null ) {
	    if ($query['table'] == 'produtosabaixoestoqueminimo'){
          die('Acesso negado.');
      }

		$key = md5( serialize( $query ) . $this->get_db( $db )->name );
		
		if ( $cache = $this->cache_get( $key ) ) {
			return $cache;
		}

		try {

			$dbh = &$this->connect( $db );

			// sanitize table name
			if ( !$this->verify_table( $query['table'] ) ) {
				$results['RESULT'] = 'ERROR';
                $results['TABLE'] = 'Invalid Table.';
                $this->error( json_encode($results), 404 );
			}
                $values = '';
                $columns = '';
                $covel = '';
                $dbh->setAttribute(PDO::ATTR_AUTOCOMMIT,0); 
              foreach( $query as $key => $value ) {
                    if (($key <> 'db') && ($key <> 'table') ) {
                        if (trim($value) == '') {
                            $this->error( 'URL Inválida na requisição.', 404 );
                        } 
                            $covel .= ','.$key.' = '."'".$value."'";
                            $values .= ','."'".$value."'";
                            $columns .= ','.$key;
                     }
                }
        if (trim($values) != ''){
              $sql = 'INSERT INTO ' . $query['table'].' ('.substr($columns, 1).') '.' values '. ' ('.substr($values, 1).') ON DUPLICATE KEY UPDATE '.substr($covel,1) ;
             
          $sth = $dbh->prepare( utf8_decode($sql) );
          sleep(1);
          $sth->execute();
          $results = json_encode('SUCCESSO '.$sql);
        }
        else{
         $results['RESULT'] = 'ERROR';
                $results['TABLE'] = 'Dados Inválidos na requisição.';
                $this->error( json_encode($results), 404 );  
        }
 
        $dbh->query("COMMIT;");

		} catch( PDOException $e ) {
		    $dbh->query("ROLLBACK;");
			$results['RESULT'] = 'ERROR';
                $results['TABLE'] = $e;
                $this->error( json_encode($results), 404 );
		}
		
		$dbh->setAttribute(PDO::ATTR_AUTOCOMMIT,1);
		
		return $results;

	}
	/**
	 * Remove any blacklisted columns from the data set.
	 */
	function sanitize_results( $results, $db = null ) {

		$db = $this->get_db( $db );

		if ( empty( $db->column_blacklist ) ) {
			return $results;
		}

		foreach ( $results as $ID => $result ) {
			foreach ( $db->column_blacklist as $column ) {
				unset( $results[ $ID ] -> $column );
			}

		}

		return $results;

	}

	/**
	 * Halt the program with an "Internal server error" and the specified message.
	 * @param string|obj $error the error or a (PDO) exception object
	 * @param int $code (optional) the error code with which to respond
	 */
	function error( $error, $code = '500' ) {
	  
	  if ( is_object( $error ) && method_exists( $error, 'get_message' ) ) {
	    $error = $error->get_message();
	  }
	
		http_response_code( $code );
		die( $error );
		return false;

	}

	/**
	 * Output JSON encoded data.
	 * @todo Support JSONP, with callback filtering.
	 */
	function render_json( $data, $query ) {
        header('Content-type: application/json');
		$output = json_encode( $data );
		
		// Prepare a JSONP callback.
		$callback = $this->jsonp_callback_filter( $query['callback'] );

		// Only send back JSONP if that's appropriate for the request.
		if ( $callback ) {
			echo "{$callback}($output);";
			return;
		}

		// If not JSONP, send back the data.
		echo $output;

	}
	
	/**
	 * Prevent malicious callbacks from being used in JSONP requests.
	 */
	function jsonp_callback_filter( $callback ) {

		// As per <http://stackoverflow.com/a/10900911/1082542>.
		if ( preg_match( '/[^0-9a-zA-Z\$_]|^(abstract|boolean|break|byte|case|catch|char|class|const|continue|debugger|default|delete|do|double|else|enum|export|extends|false|final|finally|float|for|function|goto|if|implements|import|in|instanceof|int|interface|long|native|new|null|package|private|protected|public|return|short|static|super|switch|synchronized|this|throw|throws|transient|true|try|typeof|var|volatile|void|while|with|NaN|Infinity|undefined)$/', $callback) ) {
			return false;
		}

		return $callback;

	}

	/**
	 * Output data as an HTML table.
	 */
	function render_html( $data ) {

  	require_once( dirname( __FILE__ ) . '/bootstrap/header.html' );

  	//err out if no results
		if ( empty( $data ) ) {
		  $results['RESULT'] = 'ERROR';
                $results['TABLE'] = 'No Results Found.';
                $this->error( json_encode($results), 404 );
		  return;
		}
		
		//page title
		echo "<h1>Results</h1>";
		
		//render table headings
		echo "<table class='table table-striped'>\n<thead>\n<tr>\n";

		foreach ( array_keys( get_object_vars( reset( $data ) ) ) as $heading ) {
  		echo "\t<th>$heading</th>\n";
		}
		
		echo "</tr>\n</thead>\n";
		
		//loop data and render
		foreach ( $data as $row ) {
  		
  		echo "<tr>\n";
  		
  		foreach ( $row as $cell ) {
    		
    		echo "\t<td>$cell</td>\n";
    		
  		}
  		
  		echo "</tr>";
  		
		}
		
		echo "</table>";
		
  	require_once( dirname( __FILE__ ) . '/bootstrap/footer.html' );		
		
	}

	/**
	 * Output data as XML.
	 */
	function render_xml( $data ) {

		header ("Content-Type:text/xml");  
		$xml = new SimpleXMLElement( '<results></results>' );
		$xml = $this->object_to_xml( $data, $xml );
		echo $this->tidy_xml( $xml );
		
	}

	/**
	 * Recusively travserses through an array to propegate SimpleXML objects
	 * @param array $array the array to parse
	 * @param object $xml the Simple XML object (must be at least a single empty node)
	 * @return object the Simple XML object (with array objects added)
	 */
	function object_to_xml( $array, $xml ) {
	
		//array of keys that will be treated as attributes, not children
		$attributes = array( 'id' );
	
		//recursively loop through each item
		foreach ( $array as $key => $value ) {
	
			//if this is a numbered array,
			//grab the parent node to determine the node name
			if ( is_numeric( $key ) )
				$key = 'result';
	
			//if this is an attribute, treat as an attribute
			if ( in_array( $key, $attributes ) ) {
				$xml->addAttribute( $key, $value );
	
				//if this value is an object or array, add a child node and treat recursively
			} else if ( is_object( $value ) || is_array( $value ) ) {
					$child = $xml->addChild(  $key );
					$child = $this->object_to_xml( $value, $child );
	
					//simple key/value child pair
				} else {
				$xml->addChild( $key, $value );
			}
	
		}
	
		return $xml;
	
	}
	
	/**
	 * Clean up XML domdocument formatting and return as string
	 */
	function tidy_xml( $xml ) {
  	
	   $dom = new DOMDocument();
	   $dom->preserveWhiteSpace = false;
	   $dom->formatOutput = true;
	   $dom->loadXML( $xml->asXML() );
	   return $dom->saveXML();
  	
	}

	/**
	 * Retrieve data from Alternative PHP Cache (APC).
	 */
	function cache_get( $key ) {
		
		if ( !extension_loaded('apc') || (ini_get('apc.enabled') != 1) ) {
			if ( isset( $this->cache[ $key ] ) ) {
				return $this->cache[ $key ];
			}
		}
		else {
			return apc_fetch( $key );
		}

		return false;

	}

	/**
	 * Store data in Alternative PHP Cache (APC).
	 */
	function cache_set( $key, $value, $ttl = null ) {

		if ( $ttl == null ) {
			$ttl = ( isset( $this->db->ttl) ) ? $this->db->ttl : $this->ttl;
		}

		$key = 'db_api_' . $key;

		if ( extension_loaded('apc') && (ini_get('apc.enabled') == 1) ) {
			return apc_store( $key, $value, $ttl );
		}

		$this->cache[$key] = $value;


	}


}


$db_api = new DB_API();
