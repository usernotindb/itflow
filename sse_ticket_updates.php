<?php
// 1. Include necessary core files
// Only include essential files for SSE: config, functions, and session/login check
require_once 'config.php'; // Attempting root directory for config.php
require_once 'functions.php'; // Located in root directory
require_once 'includes/check_login.php'; // Located in includes/ directory

// Note: We are intentionally not including UI-related parts of inc_all.php
// like header.php, side_nav.php, etc., as this script is for SSE events only.

// 2. Set the required HTTP headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Useful for Nginx

// 3. Initialize a variable to store the ID of the last ticket sent.
$lastSentTicketId = 0;

// Fetch the latest ticket ID from the database to initialize $lastSentTicketId
// $mysqli is expected to be available from config.php (which is included via check_login.php->functions.php or directly)
if (isset($mysqli)) {
    $query = "SELECT MAX(ticket_id) as max_id FROM tickets";
    $result = mysqli_query($mysqli, $query);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $lastSentTicketId = (int)$row['max_id'];
    }
    if ($result) { // Free result if it was successful
        mysqli_free_result($result);
    }
} else {
    // Log error and send error event to client if DB connection is missing
    error_log("SSE_TICKET_UPDATES: \$mysqli database connection not found. Ensure config.php is loaded and defines \$mysqli.");
    echo "event: error\n";
    echo "data: Database connection not available. Cannot continue.\n\n";
    if (ob_get_level() > 0) { ob_end_flush(); }
    flush();
    exit; // Critical error, cannot proceed
}

// Permission Check: Ensure user has at least read access to tickets.
// $session_user_role, $session_is_admin are from includes/check_login.php
// lookupUserPermission() is from functions.php
// Assumes 'module_ticket' is the name for the tickets module in the 'modules' table.
$ticket_permission_level = 0;
if (isset($session_user_role)) {
    $ticket_permission_level = lookupUserPermission('module_ticket');
}

// Allow if admin, or if permission level is >= 1 (read)
if (!(isset($session_is_admin) && $session_is_admin === true) && $ticket_permission_level < 1) {
    error_log("SSE_TICKET_UPDATES: User ID " . ($session_user_id ?? 'Unknown') . " does not have read permission for tickets.");
    echo "event: error\n";
    echo "data: You do not have permission to view ticket updates.\n\n";
    if (ob_get_level() > 0) { ob_end_flush(); }
    flush();
    exit; // User does not have permission
}


// 4. Implement a loop that runs indefinitely (or until the client disconnects)
while (true) {
    // Check if the connection is still alive
    if (connection_aborted()) {
        // Client disconnected, gracefully terminate the script
        // Log this event or perform cleanup if necessary
        break;
    }

    // 5. Inside the loop:
    // $mysqli, $session_is_admin, $client_access_string are expected to be available.
    if (isset($mysqli)) {
        $sql = "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject,
                       c.client_id, c.client_name,
                       ct.contact_first_name, ct.contact_last_name,
                       t.ticket_priority, t.ticket_status_id,
                       ts.ticket_status_name, ts.ticket_status_color,
                       u_assigned.user_name as assigned_to_user_name,
                       t.assigned_to_user_id AS actual_assigned_to_user_id, -- Added this ID
                       u_created.user_name as created_by_user_name,
                       t.ticket_created_at, t.ticket_updated_at,
                       cat.category_name
                FROM tickets t
                LEFT JOIN clients c ON t.client_id = c.client_id
                LEFT JOIN contacts ct ON t.contact_id = ct.contact_id
                LEFT JOIN users u_assigned ON t.assigned_to_user_id = u_assigned.user_id
                LEFT JOIN users u_created ON t.user_id = u_created.user_id
                LEFT JOIN ticket_statuses ts ON t.ticket_status_id = ts.ticket_status_id
                LEFT JOIN categories cat ON t.category_id = cat.category_id
                WHERE t.ticket_id > " . $lastSentTicketId;

        // Apply client access permissions based on variables from includes/check_login.php
        if (isset($session_is_admin) && $session_is_admin === false && isset($client_access_string) && !empty($client_access_string)) {
            $sql .= " AND t.client_id IN ($client_access_string)";
        }
        // Note: If $session_is_admin is false AND $client_access_string is empty, the user will only see tickets
        // not associated with any client (if such tickets can exist and are not filtered out by other logic),
        // or tickets where t.user_id = $session_user_id or t.assigned_to_user_id = $session_user_id if that logic were added.
        // The current setup primarily relies on admin status or explicit client assignments for broad access.

        $sql .= " ORDER BY t.ticket_id ASC";

        $result = mysqli_query($mysqli, $sql);
        $ticketsFoundInLoop = false;

        if ($result) {
            while ($ticket = mysqli_fetch_assoc($result)) {
                $ticketsFoundInLoop = true;
                $contact_name = trim(($ticket['contact_first_name'] ?? '') . ' ' . ($ticket['contact_last_name'] ?? 'N/A'));
                $ticket_data = [
                    'ticket_id' => (int)$ticket['ticket_id'],
                    'ticket_prefix' => $ticket['ticket_prefix'],
                    'ticket_number' => $ticket['ticket_number'],
                    'ticket_subject' => $ticket['ticket_subject'],
                    'client_id' => (int)$ticket['client_id'],
                    'client_name' => $ticket['client_name'],
                    'contact_name' => $contact_name,
                    'ticket_priority' => $ticket['ticket_priority'],
                    'ticket_status_id' => (int)$ticket['ticket_status_id'],
                    'ticket_status_name' => $ticket['ticket_status_name'],
                    'ticket_status_color' => $ticket['ticket_status_color'],
                    'client_name' => $ticket['client_name'] ?? 'N/A',
                    'contact_name' => $contact_name, // Already handles N/A if both parts are empty
                    'ticket_priority' => $ticket['ticket_priority'],
                    'ticket_status_id' => (int)$ticket['ticket_status_id'],
                    'ticket_status_name' => $ticket['ticket_status_name'] ?? 'N/A',
                    'ticket_status_color' => $ticket['ticket_status_color'] ?? '#000000',
                    'assigned_to_user_name' => $ticket['assigned_to_user_name'] ?? 'Not Assigned', // Keep consistent default
                    'assigned_to_user_id' => !empty($ticket['actual_assigned_to_user_id']) ? (int)$ticket['actual_assigned_to_user_id'] : null,
                    'created_by_user_name' => $ticket['created_by_user_name'] ?? 'N/A',
                    'category_name' => $ticket['category_name'] ?? 'N/A',
                    'ticket_created_at' => $ticket['ticket_created_at'],
                    'ticket_created_at_time_ago' => !empty($ticket['ticket_created_at']) ? timeAgo($ticket['ticket_created_at']) : 'Never',
                    'ticket_updated_at' => $ticket['ticket_updated_at'],
                    'ticket_updated_at_time_ago' => !empty($ticket['ticket_updated_at']) ? timeAgo($ticket['ticket_updated_at']) : 'Never',
                    'url' => 'ticket.php?ticket_id=' . $ticket['ticket_id']
                ];
                $json_encoded_ticket_data = json_encode($ticket_data);

                echo "event: new_ticket\n";
                echo "id: " . $ticket['ticket_id'] . "\n";
                echo "data: " . $json_encoded_ticket_data . "\n\n";

                $lastSentTicketId = (int)$ticket['ticket_id'];
            }
            mysqli_free_result($result); // Free result set
        } else {
            // Log SQL error
            error_log("SSE_TICKET_UPDATES: SQL Error - " . mysqli_error($mysqli));
            // Optionally send an error to client or break, but continuous operation might be preferred if temporary
        }
    } else {
        // This case should be prevented by the initial DB connection check & exit
        error_log("SSE_TICKET_UPDATES: \$mysqli became unset during loop. This should not happen.");
        echo "event: error\n";
        echo "data: Database connection lost during operation.\n\n";
        break; // Exit loop if DB connection is lost
    }

    // Send a heartbeat comment ONLY if no new tickets were found and sent in this iteration.
    if (!$ticketsFoundInLoop) {
       echo ": heartbeat\n\n";
    }


    // Flush the output buffer to ensure data is sent to the client immediately.
    if (ob_get_level() > 0) {
        ob_end_flush(); // Use ob_end_flush if you started output buffering with ob_start()
    }
    flush();

    // Wait for a short interval (e.g., sleep(5);) before checking for new tickets again.
    sleep(5);
}

// Optional: Log script termination if not due to client disconnect
// error_log("SSE script terminated normally.");
?>
