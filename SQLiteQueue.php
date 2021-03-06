<?php

class SQLiteQueue_Exception  extends Exception { }
class SQLiteQueue {

    protected $file_db        = null;
    protected $file_db_lock   = null;
    protected $type           = null;
    protected $dbh            = null;
    protected $usetransaction = true;
    protected $lock_fp        = null;

    public function __construct($file_db = null, $type = 'lifo') {
        // where is the queue database ?
        if (!$file_db) {
            $this->file_db = dirname(__FILE__).'/queue.db';
        } else {
            $this->file_db = $file_db;
        }
        $this->file_db_lock = $file_db.'.lock';

        // FIFO or LIFO queue ?
        $this->type = $type;
        $types = array('lifo', 'fifo');
        if (!in_array($this->type, $types)) {
            throw new SQLiteQueue_Exception('Unknown queue type. Only '.implode(' or ', $types).' are valid types.');
        }

        // if used on old php, transactions doesn't work (tested on debian lenny)
        if (version_compare(PHP_VERSION, '5.3.3') < 0) {
            $this->usetransaction = false;
            $this->file_db = $this->file_db.'.notrans';
        }
    }

    public function __destruct() {
        if (!$this->usetransaction) {
            @unlink($this->file_db_lock);
            return;
        } else {
            // to be sure the database used space is optimized
            if ($this->dbh) {
                $this->dbh->exec('VACUUM');
            }

            // to be sure that PDO instance is destroyed
            unset($this->dbh);
            $this->dbh = null;
        }
    }
    
    protected function lockQueue()
    {
        $this->lock_fp = fopen($this->file_db_lock, "w");
        flock($this->lock_fp, LOCK_EX);
    }
    
    protected function unlockQueue()
    {
        flock($this->lock_fp, LOCK_UN);
    }
    
    public function getQueueFile()
    {
        return $this->file_db;
    }

    protected function initQueue()
    {
        if (!$this->usetransaction) {
            touch($this->file_db);
        } else {
            if (!$this->dbh) {
                $this->dbh = new PDO('sqlite:'.$this->file_db);
                $this->dbh->exec('CREATE TABLE IF NOT EXISTS queue(id INTEGER PRIMARY KEY AUTOINCREMENT, date TIMESTAMP NOT NULL default CURRENT_TIMESTAMP, item BLOB)');
            }
        }
    }

    /**
     * Add an element to the queue
     */
    public function offer($item)
    {
        if (!$this->usetransaction) {
            $this->lockQueue();
        }

        $this->initQueue();

        // convert $item to string
        $item = serialize($item);

        if (!$this->usetransaction) {
            $item = base64_encode($item);
            file_put_contents($this->file_db, "\n".$item, FILE_APPEND | LOCK_EX);
            $ret = true;
        } else {
            $stmt = $this->dbh->prepare('INSERT INTO queue (item) VALUES (:item)');
            $stmt->bindParam(':item', $item, PDO::PARAM_STR);
            $ret = $stmt->execute();
        }

        if (!$this->usetransaction) {
            $this->unlockQueue();
        }

        return $ret;
    }

    /**
     * Return and remove an element from the queue
     */
    public function poll()
    {
        if (!$this->usetransaction) {
            $this->lockQueue();
        }
        
        $this->initQueue();

        
        if (!$this->usetransaction) {
            $items = explode("\n", file_get_contents($this->file_db));
            if (!$items[0]) array_shift($items);
            if (count($items) > 0) {
                $item = ($this->type == 'lifo') ? array_shift($items) : array_pop($items);
                $item = unserialize(base64_decode($item));
                file_put_contents($this->file_db, implode("\n", $items), LOCK_EX);
                $this->unlockQueue();
                return $item;
            } else {
                $this->unlockQueue();
                return NULL;
            }
        } else {
            // get item using transaction to avoid concurrency problems
            $this->dbh->exec('BEGIN EXCLUSIVE TRANSACTION');
            $stmt_del = $this->dbh->prepare('DELETE FROM queue WHERE id = :id');
            $stmt_sel = $this->dbh->query('SELECT id,item FROM queue ORDER BY id '.($this->type == 'lifo' ? 'ASC' : 'DESC').' LIMIT 1');
            // workaround a bug : sometimes I got a SQLite "database schema has changed"
            // strangely playing this query twice solve the problem
            if (!$stmt_sel) {
                $stmt_sel = $this->dbh->query('SELECT id,item FROM queue ORDER BY id '.($this->type == 'lifo' ? 'ASC' : 'DESC').' LIMIT 1');
            }
            if ($stmt_sel and $result = $stmt_sel->fetch(PDO::FETCH_ASSOC)) {
                // destroy item from the queue and return it
                $stmt_del->bindParam(':id', $result['id'], PDO::PARAM_INT);
                $stmt_del->execute();
                $this->dbh->exec('COMMIT');
                return unserialize($result['item']);
            } else {
                trigger_error('SQLiteQueue error: '.implode(' - ', $this->dbh->errorInfo()), E_USER_WARNING);
                $this->dbh->exec('ROLLBACK');
                return NULL;
            }
        }
        
    }

    /**
     * Check if the queue is empty
     */
    public function isEmpty()
    {
        return $this->countItem() == 0;
    }

    /**
     * Count number of items in the queue
     */
    public function countItem()
    {
        if (!$this->usetransaction) {
            $this->lockQueue();
        }
        
        $this->initQueue();
        
        if (!$this->usetransaction) {
            $items = explode("\n", file_get_contents($this->file_db));
            if (!$items[0]) array_shift($items);
            $ret = count($items);
        } else {
            // get the total number of items
            $stmt = $this->dbh->query('SELECT count(id) as nb FROM queue');
            if (!$stmt) return 0;
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $ret = isset($result['nb']) ? (integer)$result['nb'] : 0;
        }
        
        if (!$this->usetransaction) {
            $this->unlockQueue();
        }
        
        return $ret;
    }

}
