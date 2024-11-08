<?php
// Database.php

class Database {
    private $dbPath;
    public $pdo;

    public function __construct($dbPath) {
        $this->dbPath = $dbPath;
        $this->connect();
    }

    private function connect() {
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS known_macs (id INTEGER PRIMARY KEY, mac_address TEXT UNIQUE, client_name TEXT, host_name TEXT, last_seen INTEGER)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY, date1 TEXT, event TEXT, mac_address TEXT, client_name TEXT, host_name TEXT)");
    }

    public function loadKnownMacs($envKnownMacs) {
        $knownMacs = array_flip($envKnownMacs); // Use MAC addresses as keys for easy lookup
        $result = $this->pdo->query("SELECT mac_address FROM known_macs");
        foreach ($result as $row) {
            $knownMacs[$row['mac_address']] = true; // Add or overwrite key
        }
        return array_keys($knownMacs); // Convert back to list
    }

    public function updateKnownMacs($mac, $client_name, $host_name) {
        // update known_macs
        $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO known_macs (mac_address, client_name, host_name) VALUES (?, ?, ?)");
        $stmt->execute([$mac, $client_name, $host_name]);
        // update events
        $stmt = $this->pdo->prepare("INSERT INTO events (date1, event, mac_address, client_name, host_name) VALUES (datetime('now','localtime'), 'added', ?, ?, ?)");
        $stmt->execute([$mac, $client_name, $host_name]);
    }

    public function removeOldMacs($clients, $delay) {
        $result = $this->pdo->query("SELECT mac_address, client_name, host_name, last_seen FROM known_macs");
        foreach ($result as $row) {
            $obj = array_column($clients, null, 'mac')[$row['mac_address']] ?? false;
            if (!$obj){
                if (is_null($row['last_seen'])) {
                    echo "Device disappeard. Not yet removing: " . $row['mac_address'] . ", " . $row['client_name'] . ", " . $row['host_name'] . "\n";
                    $stmt = $this->pdo->prepare("UPDATE known_macs SET last_seen = (SELECT UNIXEPOCH()) WHERE mac_address = :mac");
                    $stmt->execute(['mac' => $row['mac_address']]);
                }
                else {
                    $delete_mac = (($row['last_seen'] + $delay) < time()) ? true : false;
                    if ($delete_mac) {
                        echo "Removing device from database: " . $row['mac_address'] . ", " .  $row['client_name'] . ", " . $row['host_name'] . "\n";
                        $stmt = $this->pdo->prepare("DELETE FROM known_macs WHERE mac_address = :mac");
                        $stmt->execute(['mac' => $row['mac_address']]);
                        // update events
                        $stmt = $this->pdo->prepare("INSERT INTO events (date1, event, mac_address, client_name, host_name) VALUES (datetime('now','localtime'), 'removed', ?, ?, ?)");
                        $stmt->execute([$row['mac_address'], $row['client_name'], $row['host_name']]);
                    }
                }
            }
            else{
                // if client is on network, clear last_seen time
                $stmt = $this->pdo->prepare("UPDATE known_macs SET last_seen = NULL WHERE mac_address = :mac");
                $stmt->execute(['mac' => $row['mac_address']]);
            }
        }
    }
}