<?php
require_once(__DIR__ . '/Database.php');
require_once(__DIR__ . '/Notifier.php');
require_once(__DIR__ . '/Unifi-API-client/Client.php');
require_once(__DIR__ . '/Unifi-API-client/config.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/CurlExtensionNotLoadedException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/CurlGeneralErrorException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/CurlTimeoutException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/EmailInvalidException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/InvalidBaseUrlException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/InvalidCurlMethodException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/InvalidSiteNameException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/JsonDecodeException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/LoginFailedException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/LoginRequiredException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/MethodDeprecatedException.php');
require_once(__DIR__ . '/Unifi-API-client/Exceptions/NotAUnifiOsConsoleException.php');
require_once(__DIR__ . '/../vendor/autoload.php');

// Environment configuration
$envKnownMacs = array_map('trim', explode(',', getenv('KNOWN_MACS') ?: ''));
$checkInterval = getenv('CHECK_INTERVAL') ?: 60;
$notificationService = getenv('NOTIFICATION_SERVICE') ?: 'Telegram';
$alwaysNotify = filter_var(getenv('ALWAYS_NOTIFY') ?: False, FILTER_VALIDATE_BOOLEAN);
$rememberNewDevices = filter_var(getenv('REMEMBER_NEW_DEVICES') ?: True, FILTER_VALIDATE_BOOLEAN);
$teleportNotifications = filter_var(getenv('TELEPORT_NOTIFICATIONS') ?: False, FILTER_VALIDATE_BOOLEAN);
$removeOldDevices = filter_var(getenv('REMOVE_OLD_DEVICES') ?: False, FILTER_VALIDATE_BOOLEAN);
$removeDelay = getenv('REMOVE_DELAY') ?: 0;
$debugMode = filter_var(getenv('UNIFI_API_DEBUG_MODE') ?: False, FILTER_VALIDATE_BOOLEAN);
$notifyOnError = filter_var(getenv('NOTIFY_ON_ERROR') ?: False, FILTER_VALIDATE_BOOLEAN);
$exitOnError = filter_var(getenv('EXIT_ON_ERROR') ?: False, FILTER_VALIDATE_BOOLEAN);
$databasePath = getenv('DATABASE_PATH') ?: '/data/';
$timezone = getenv('TIMEZONE') ?: 'America/Los_Angeles';

// Set local timezone
date_default_timezone_set($timezone);

// Validate critical environment configurations
if (!in_array($notificationService, ['Telegram', 'Ntfy', 'Pushover', 'Slack'])) {
    echo "Error: Invalid notification service specified. Please set NOTIFICATION_SERVICE to either 'Telegram', 'Nify', 'Pushover' or 'Slack'.\n";
    exit(1);
}

// Print configuration at startup
echo "----------------------------------------\n";
echo "Starting UniFi Client Check with the following configuration:\n";
echo "UniFi Controller URL: $controllerurl\n";
echo "Check Interval: $checkInterval seconds\n";
echo "Notification Service: $notificationService\n";
echo "Always Notify: " . ($alwaysNotify ? 'True' : 'False') . "\n";
echo "Remember New Devices: " . ($rememberNewDevices ? 'True' : 'False') . "\n";
echo "Teleport Notifications: " . ($teleportNotifications ? 'True' : 'False') . "\n";
echo "Remove Old Devices: " . ($removeOldDevices ? 'True' : 'False') . "\n";
if ($removeOldDevices) {
    echo "Remove Delay: $removeDelay seconds\n";
}
echo "Database Path: $databasePath\n";
echo "UniFi API Debug Mode: " . ($debugMode ? 'True' : 'False') . "\n";
echo "Notify On Error: " . ($notifyOnError ? 'True' : 'False') . "\n";
echo "Exit On Error: " . ($exitOnError ? 'True' : 'False') . "\n";
echo "Timezone: $timezone\n";   
echo "----------------------------------------\n";


// Initialize Database, Notifier, and UniFiClient
$database = new Database((str_ends_with($databasePath, '/') ? $databasePath : $databasePath . '/') . 'knownMacs.db');
$knownMacs = $database->loadKnownMacs($envKnownMacs);
$notifier = new Notifier(getenv('TELEGRAM_BOT_TOKEN'), getenv('TELEGRAM_CHAT_ID'), getenv('NTFY_URL'), getenv('PUSHOVER_TOKEN'), getenv('PUSHOVER_USER'), getenv('PUSHOVER_TITLE'), getenv('PUSHOVER_URL'), getenv('SLACK_WEBHOOK_URL'));

function createUnifiClient() {
    global $controlleruser, $controllerpassword, $controllerurl, $site_id, $controllerversion, $debugMode;
    $unifiClient = new UniFi_API\Client($controlleruser, $controllerpassword, $controllerurl, $site_id, $controllerversion);
    $unifiClient->set_debug($debugMode);
    $unifiClient->login();
    return $unifiClient;
}

try{
    // Initial connection to UniFi Controller
    $unifiClient = createUnifiClient();
} catch (Exception $e) {
    if ($notifyOnError){
        try {
            $notifier->sendNotification("Initial connection to UniFi Controller failed: " . $e->getMessage(), $notificationService);
        }
        catch (Exception $notifyEx) {
            echo date('Y-m-d H:i:s') . ": Failed to send error notification.\n";
        }
    }
    echo date('Y-m-d H:i:s') . ": Initial connection to UniFi Controller failed: " . $e->getMessage() . "\n";
    
    // if EXIT_ON_ERROR is true, exit
    if ($exitOnError){
        echo date('Y-m-d H:i:s') . ": Exiting due to EXIT_ON_ERROR being set to true.\n";
        exit(1);
    }
}

// Main loop
while (true) {
    try {
        // Adjust the API request based on TeleportNotifications flag
        if ($teleportNotifications) {
            $path = '/v2/api/site/default/clients/active';
            $method = 'GET';
            $clients = $unifiClient->custom_api_request($path, $method, null, 'array');
        } else {
            $clients = $unifiClient->list_clients();
        }
        
        $newDeviceFound = false; // Initialize flag to track new device detection

        if ($clients === false) {
            echo "Error: Failed to retrieve clients from the UniFi Controller. Retrying in 60 seconds...\n";
            sleep(60);
            $unifiClient->logout();
            $unifiClient = createUnifiClient();
            continue;
        }

        if (!is_array($clients)) {
            echo "Error in client data retrieval: Expected an array, received a different type. Attempting to reconnect to UniFi Controller...\n";
            sleep(60);
            $unifiClient->logout();
            $unifiClient = createUnifiClient();
            continue;
        }
	    
        if (empty($clients)) {
            echo "No devices currently connected to the network.\n";
            continue;
        }

        foreach ($clients as $client) {
            $isNewDevice = !in_array($client->mac ?? $client->id, $knownMacs);
            if ($isNewDevice) {
                echo "New device found. Sending a notification: " . $client->mac . "\n";
                $newDeviceFound = true;
            }

            if ($alwaysNotify || $isNewDevice) {
                if ($teleportNotifications && isset($client->type) && $client->type == 'TELEPORT') {
                    // Format message for Teleport device
                    $message = "Teleport device seen on network:\n";
                    $message .= "Name: " . ($client->name ?? 'Unknown') . "\n";
                    $message .= "IP Address: " . $client->ip . "\n";
                    $message .= "ID: " . $client->id . "\n";
                } else {
					$networkProperty = $teleportNotifications ? 'network_name' : 'network';
                    // Format message for regular device
                    if ($notificationService != 'Pushover') {
                        $message = "Device seen on network:\n";
                    } else {
                        $message = "";
                    }
                    $message .= "Device Name: " . ($client->name ?? 'Unknown') . "\n";
                    $message .= "IP Address: " . ($client->ip ?? 'Unassigned') . "\n";
                    $message .= "Hostname: " . ($client->hostname ?? 'N/A') . "\n";
                    $message .= "MAC Address: " . $client->mac . "\n";
                    $message .= "Connection Type: " . ($client->is_wired ? "Wired" : "Wireless") . "\n";
                    $message .= "Network: " . ($client->{$networkProperty} ?? 'N/A');
                }

                // Send notification
                $notifier->sendNotification($message, $notificationService);
                
                // Update known MACs or IDs for new devices
                if ($isNewDevice && $rememberNewDevices) {
                    $macOrId = ($teleportNotifications && isset($client->type) && $client->type == 'TELEPORT') ? $client->id : $client->mac;
                    $database->updateKnownMacs($macOrId, $client->name ?? 'Unknown', $client->hostname ?? 'N/A');
                    $knownMacs[] = $macOrId; // Update local cache
                }
            }
        }

        if (!$newDeviceFound) {
            echo date('Y-m-d H:i:s') . ": No new devices found on the network.\n";
        } 

        if ($removeOldDevices) {
            $database->removeOldMacs($clients, $removeDelay);
            $knownMacs = $database->loadKnownMacs($envKnownMacs); //reload local cache
        } 
        
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . ": An error occurred: " . $e->getMessage() . "\n";
        if ($notifyOnError) {
            try{
                $notifier->sendNotification("An error occurred: " . $e->getMessage(), $notificationService);
            }
            catch (Exception $notifyEx) {
                echo date('Y-m-d H:i:s') . ": Failed to send error notification.\n";
            }
        }
        
        // if EXIT_ON_ERROR is true, exit        
        if ($exitOnError){
            echo date('Y-m-d H:i:s') . ": Exiting due to EXIT_ON_ERROR being set to true.\n";
            exit(1);
        }
    
        // Attempt to reconnect to UniFi Controller
        try{
            echo date('Y-m-d H:i:s') . ": Reconnecting to UniFi Controller\n";
            $unifiClient->logout(); //logout before reconnecting
            $unifiClient->login(); //login again
        } catch (Exception $e) {
            // If reconnection fails, notify and continue
            echo date('Y-m-d H:i:s') . ": Failed to reconnect to UniFi Controller: " . $e->getMessage() . "\n";
            if ($notifyOnError){
                try{
                    $notifier->sendNotification("Failed to reconnect to UniFi Controller: " . $e->getMessage(), $notificationService);
                }
                catch (Exception $notifyEx) {
                    echo date('Y-m-d H:i:s') . ": Failed to send error notification.\n";
                }
            }
        }     
    }
    echo date('Y-m-d H:i:s') . ": Checking again in $checkInterval seconds...\n";
    sleep($checkInterval);
}
