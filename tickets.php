<?php


// Default Column Sortby Filter
$sort = "ticket_number";
$order = "DESC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND ticket_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = '';
    $client_url = '';
}

// Perms
enforceUserPermission('module_support');

// Ticket status from GET
if (isset($_GET['status']) && is_array($_GET['status']) && !empty($_GET['status'])) {
    // Sanitize each element of the status array
    $sanitizedStatuses = array();
    foreach ($_GET['status'] as $status) {
        // Escape each status to prevent SQL injection
        $sanitizedStatuses[] = "'" . intval($status) . "'";
    }

    // Convert the sanitized statuses into a comma-separated string
    $sanitizedStatusesString = implode(",", $sanitizedStatuses);
    $ticket_status_snippet = "ticket_status IN ($sanitizedStatusesString)";

} else {

    // TODO: Convert this to use the status IDs
    if (isset($_GET['status']) && ($_GET['status']) == 'Closed') {
        $status = 'Closed';
        $ticket_status_snippet = "ticket_resolved_at IS NOT NULL";
    } else {
        // Default - Show open tickets
        $status = 'Open';
        $ticket_status_snippet = "ticket_resolved_at IS NULL";
    }
}

if (isset($_GET['billable']) && ($_GET['billable']) == '1') {
    if (isset($_GET['unbilled'])) {
        $billable = 1;
        $ticket_billable_snippet = "AND ticket_billable = 1 AND ticket_invoice_id = 0";
        $ticket_status_snippet = '1 = 1';
    }
} else {
    $billable = 0;
    $ticket_billable_snippet = '';
}

if (isset($_GET['category'])) {
    $category = sanitizeInput($_GET['category']);
    if ($category == 'empty') {
        $category_snippet = "AND ticket_category = 0 ";
    } elseif ($category == 'all') {
        $category_snippet = '';
    } else {
        $category_snippet = "AND ticket_category = " . $category;
    }
} else {
    $category_snippet = '';
}


// Ticket assignment status filter
// Default - any
$ticket_assigned_query = '';
$ticket_assigned_filter_id = '';
if (isset($_GET['assigned']) & !empty($_GET['assigned'])) {
    if ($_GET['assigned'] == 'unassigned') {
        $ticket_assigned_query = 'AND ticket_assigned_to = 0';
        $ticket_assigned_filter_id = 0;
    } else {
        $ticket_assigned_query = 'AND ticket_assigned_to = ' . intval($_GET['assigned']);
        $ticket_assigned_filter_id = intval($_GET['assigned']);
    }
} 

//Rebuild URL
$url_query_strings_sort = http_build_query(array_merge($_GET, array('sort' => $sort, 'order' => $order, 'status' => $status, 'assigned' => $ticket_assigned_filter_id)));

// Ticket client access snippet
$ticket_permission_snippet = '';
if (!empty($client_access_string)) {
    $ticket_permission_snippet = "AND ticket_client_id IN ($client_access_string)";
}

// Main ticket query:
$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM tickets
    LEFT JOIN clients ON ticket_client_id = client_id
    LEFT JOIN contacts ON ticket_contact_id = contact_id
    LEFT JOIN users ON ticket_assigned_to = user_id
    LEFT JOIN assets ON ticket_asset_id = asset_id
    LEFT JOIN locations ON ticket_location_id = location_id
    LEFT JOIN vendors ON ticket_vendor_id = vendor_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    LEFT JOIN categories ON ticket_category = category_id
    WHERE $ticket_status_snippet " . $ticket_assigned_query . "
    $category_snippet
    AND DATE(ticket_created_at) BETWEEN '$dtf' AND '$dtt'
    AND (CONCAT(ticket_prefix,ticket_number) LIKE '%$q%' OR client_name LIKE '%$q%' OR ticket_subject LIKE '%$q%' OR ticket_status_name LIKE '%$q%' OR ticket_priority LIKE '%$q%' OR user_name LIKE '%$q%' OR contact_name LIKE '%$q%' OR asset_name LIKE '%$q%' OR vendor_name LIKE '%$q%' OR ticket_vendor_ticket_number LIKE '%q%')
    $ticket_billable_snippet
    $ticket_permission_snippet
    $client_query
    ORDER BY
        CASE 
            WHEN '$sort' = 'ticket_priority' THEN
                CASE ticket_priority
                    WHEN 'High' THEN 1
                    WHEN 'Medium' THEN 2
                    WHEN 'Low' THEN 3
                    ELSE 4  -- Optional: for unexpected priority values
                END
            ELSE NULL
        END $order, 
        $sort $order  -- Apply normal sorting by $sort and $order
    LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

//Get Total tickets open
$sql_total_tickets_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_open FROM tickets WHERE ticket_resolved_at IS NULL $client_query $ticket_permission_snippet");
$row = mysqli_fetch_array($sql_total_tickets_open);
$total_tickets_open = intval($row['total_tickets_open']);

//Get Total tickets closed
$sql_total_tickets_closed = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_closed FROM tickets WHERE ticket_resolved_at IS NOT NULL $client_query $ticket_permission_snippet");
$row = mysqli_fetch_array($sql_total_tickets_closed);
$total_tickets_closed = intval($row['total_tickets_closed']);

//Get Unassigned tickets
$sql_total_tickets_unassigned = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_unassigned FROM tickets WHERE ticket_assigned_to = '0' AND ticket_resolved_at IS NULL $client_query $ticket_permission_snippet");
$row = mysqli_fetch_array($sql_total_tickets_unassigned);
$total_tickets_unassigned = intval($row['total_tickets_unassigned']);

//Get Total tickets assigned to me
$sql_total_tickets_assigned = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_assigned FROM tickets WHERE ticket_assigned_to = $session_user_id AND ticket_resolved_at IS NULL $client_query $ticket_permission_snippet");
$row = mysqli_fetch_array($sql_total_tickets_assigned);
$user_active_assigned_tickets = intval($row['total_tickets_assigned']);

$sql_categories = mysqli_query(
    $mysqli,
    "SELECT * FROM categories
    WHERE category_type = 'Ticket'
    ORDER BY category_name"
);



?>
    <style>
        .popover {
            max-width: 600px;
        }
    </style>
    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fa fa-fw fa-life-ring mr-2"></i>Tickets
                <small class="ml-3">
                    <a href="?<?php echo $client_url; ?>status=Open" class="text-light"><strong id="total-tickets-open-count"><?php echo $total_tickets_open; ?></strong> Open</a> |
                    <a href="?<?php echo $client_url; ?>status=Closed" class="text-light"><strong id="total-tickets-closed-count"><?php echo $total_tickets_closed; ?></strong> Closed</a>
                </small>
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#addTicketModal">
                        <i class="fas fa-plus mr-2"></i>New Ticket
                    </button>
                    <?php if ($num_rows[0] > 0) { ?>
                    <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-toggle="dropdown"></button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item text-dark" href="#" data-toggle="modal" data-target="#exportTicketModal">
                            <i class="fa fa-fw fa-download mr-2"></i>Export
                        </a>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div>
        <div class="card-body">
            <form autocomplete="off">
                <?php if ($client_url) { ?>
                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                <?php } ?>
                <div class="row">
                    <div class="col-sm-4">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Tickets">
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-8">
                        <div class="btn-group float-right">
                            <div class="btn-group">
                                <button class="btn btn-outline-dark dropdown-toggle" id="dropdownMenuButton" data-toggle="dropdown">
                                    <i class="fa fa-fw fa-eye mr-2"></i>View
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item " href="<?=htmlspecialchars('?' . http_build_query(array_merge($_GET, ['view' => 'list']))); ?>">List</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item " href="<?=htmlspecialchars('?' . http_build_query(array_merge($_GET, ['view' => 'compact']))); ?>">Compact List</a>
                                    <?php if ($status !== 'Closed') {?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item " href="<?=htmlspecialchars('?' . http_build_query(array_merge($_GET, ['view' => 'kanban']))); ?>">Kanban</a>
                                    <?php } ?>
                                </div>
                            </div>
                            <div class="btn-group">
                                <button class="btn btn-outline-dark dropdown-toggle" id="dropdownMenuButton" data-toggle="dropdown">
                                    <i class="fa fa-fw fa-layer-group mr-2"></i>Categories
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item " href="<?=htmlspecialchars('?' . http_build_query(array_merge($_GET, ['category' => 'all']))); ?>">All</a>
                                    <div class="dropdown-divider"></div>
                                    <?php
                                    while ($row = mysqli_fetch_array($sql_categories)) {
                                        $category_id = intval($row['category_id']);
                                        $category_name = nullable_htmlentities($row['category_name']);
                                        $category_color = nullable_htmlentities($row['category_color']);
                                    ?>
                                    <a class="dropdown-item" href="<?=htmlspecialchars('?' . http_build_query(array_merge($_GET, ['category' => $category_id]))); ?>"><?php echo $category_name ?></a>
                                    <div class="dropdown-divider"></div>
                                <?php } ?>
                                    <a class="dropdown-item " href="<?=htmlspecialchars('?' . http_build_query(array_merge($_GET, ['category' => 'empty']))); ?>">No Category</a>
                                </div>
                            </div>
                            <div class="btn-group">
                                <button class="btn btn-outline-dark dropdown-toggle" id="categoriesDropdownMenuButton" data-toggle="dropdown">
                                    <i class="fa fa-fw fa-envelope mr-2"></i>My Tickets
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="?<?php echo $client_url; ?>status=Open&assigned=<?php echo $session_user_id ?>">Active tickets (<strong id="user-active-assigned-tickets-count"><?php echo $user_active_assigned_tickets ?></strong>)</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item " href="?<?php echo $client_url; ?>status=Closed&assigned=<?php echo $session_user_id ?>">Closed tickets</a>
                                </div>
                            </div>
                            <a href="?<?php echo $client_url; ?>assigned=unassigned" class="btn btn-outline-danger">
                                <i class="fa fa-fw fa-exclamation-triangle mr-2"></i>Unassigned Tickets | <strong id="total-tickets-unassigned-count"> <?php echo $total_tickets_unassigned; ?></strong>
                            </a>

                            <?php if (lookupUserPermission("module_support") >= 2) { ?>
                                <div class="dropdown ml-2" id="bulkActionButton" hidden>
                                <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                                    <i class="fas fa-fw fa-layer-group mr-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#bulkAssignTicketModal">
                                        <i class="fas fa-fw fa-user-check mr-2"></i>Assign Tech
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#bulkEditCategoryTicketModal">
                                        <i class="fas fa-fw fa-layer-group mr-2"></i>Set Category
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#bulkEditPriorityTicketModal">
                                        <i class="fas fa-fw fa-thermometer-half mr-2"></i>Update Priority
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#bulkReplyTicketModal">
                                        <i class="fas fa-fw fa-paper-plane mr-2"></i>Bulk Update/Reply
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#bulkAssignTicketToProjectModal">
                                        <i class="fas fa-fw fa-project-diagram mr-2"></i>Add to Project
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#bulkMergeTicketModal">
                                        <i class="fas fa-fw fa-clone mr-2"></i>Merge
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="#" data-toggle="modal" data-target="#bulkCloseTicketsModal">
                                        <i class="fas fa-fw fa-check mr-2"></i>Resolve
                                    </a>
                                </div>
                            </div>
                            <?php } ?>

                        </div>

                    </div>
                </div>

                <div 
                    class="collapse 
                        <?php 
                        if (
                            !empty($_GET['dtf']) 
                            || (isset($_GET['canned_date']) && $_GET['canned_date'] !== "custom") 
                            || (isset($_GET['status']) && is_array($_GET['status']) 
                            || (isset($_GET['assigned']) && $_GET['assigned']
                        ))) 
                            { echo "show"; } 
                        ?>" 
                    id="advancedFilter"
                >
                    <div class="row">
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Canned Date</label>
                                <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                    <option <?php if ($_GET['canned_date'] == "custom") {
                                        echo "selected";
                                    } ?> value="custom">Custom
                                    </option>
                                    <option <?php if ($_GET['canned_date'] == "today") {
                                        echo "selected";
                                    } ?> value="today">Today
                                    </option>
                                    <option <?php if ($_GET['canned_date'] == "yesterday") {
                                        echo "selected";
                                    } ?> value="yesterday">Yesterday
                                    </option>
                                    <option <?php if ($_GET['canned_date'] == "thisweek") {
                                        echo "selected";
                                    } ?> value="thisweek">This Week
                                    </option>
                                    <option <?php if ($_GET['canned_date'] == "lastweek") {
                                        echo "selected";
                                    } ?> value="lastweek">Last Week
                                    </option>
                                    <option <?php if ($_GET['canned_date'] == "thismonth") {
                                        echo "selected";
                                    } ?> value="thismonth">This Month
                                    </option>
                                    <option <?php if ($_GET['canned_date'] == "lastmonth") {
                                        echo "selected";
                                    } ?> value="lastmonth">Last Month
                                    </option>
                                    <option <?php if ($_GET['canned_date'] == "thisyear") {
                                        echo "selected";
                                    } ?> value="thisyear">This Year
                                    </option>
                                    <option <?php if ($_GET['canned_date'] == "lastyear") {
                                        echo "selected";
                                    } ?> value="lastyear">Last Year
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Date From</label>
                                <input onchange="this.form.submit()" type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Date To</label>
                                <input onchange="this.form.submit()" type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Ticket Status</label>
                                <select onchange="this.form.submit()" class="form-control select2" name="status[]" data-placeholder="Select Status" multiple>

                                        <?php $sql_ticket_status = mysqli_query($mysqli, "SELECT * FROM ticket_statuses WHERE ticket_status_active = 1");
                                        while ($row = mysqli_fetch_array($sql_ticket_status)) {
                                            $ticket_status_id = intval($row['ticket_status_id']);
                                            $ticket_status_name = nullable_htmlentities($row['ticket_status_name']); ?>

                                            <option value="<?php echo $ticket_status_id ?>" <?php if (isset($_GET['status']) && is_array($_GET['status']) && in_array($ticket_status_id, $_GET['status'])) { echo 'selected'; } ?>> <?php echo $ticket_status_name ?> </option>

                                        <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Assigned to</label>
                                <select onchange="this.form.submit()" class="form-control select2" name="assigned">
                                    <option value="" <?php if ($ticket_assigned_filter_id == "") { echo "selected"; } ?>>Any</option>
                                    <option value="unassigned" <?php if ($ticket_assigned_filter_id == "0") { echo "selected"; } ?>>Unassigned</option>

                                    <?php
                                    $sql_assign_to = mysqli_query($mysqli, "SELECT * FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
                                    while ($row = mysqli_fetch_array($sql_assign_to)) {
                                        $user_id = intval($row['user_id']);
                                        $user_name = nullable_htmlentities($row['user_name']);
                                        ?>
                                        <option <?php if ($ticket_assigned_filter_id == $user_id) { echo "selected"; } ?> value="<?php echo $user_id; ?>"><?php echo $user_name; ?></option>
                                        <?php
                                    }
                                    ?>

                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php

if (isset($_GET["view"])) {
    if ($_GET["view"] == "list") {
        require_once "tickets_list.php";
    } elseif ($_GET["view"] == "compact") {
        require_once "tickets_compact.php";
    } elseif ($_GET["view"] == "kanban") {
        require_once "tickets_kanban.php";
    }
} else {
    // here we have to get default view setting
    if ($config_ticket_default_view === 0) {
        require_once "tickets_list.php";
    } elseif ($config_ticket_default_view === 1) {
        require_once "tickets_compact.php";
    } elseif ($config_ticket_default_view === 2) {
        require_once "tickets_kanban.php";
    } else {
        require_once "tickets_list.php";
    }
}

?>

<script src="js/bulk_actions.js"></script>

<?php
// Expose the default view configuration to JavaScript
$js_default_view = 'list'; // Default to list
if (isset($config_ticket_default_view)) {
    if ($config_ticket_default_view === 1) {
        $js_default_view = 'compact';
    } elseif ($config_ticket_default_view === 2) {
        $js_default_view = 'kanban';
    }
}
echo "<script>window.DEFAULT_TICKET_VIEW = '" . htmlspecialchars($js_default_view, ENT_QUOTES, 'UTF-8') . "';</script>";
echo "<script>window.CURRENT_USER_IS_CLIENT_VIEW = " . (isset($client_url) && !empty($client_url) ? 'true' : 'false') . ";</script>";
echo "<script>window.SESSION_USER_ID = " . json_encode(isset($session_user_id) ? $session_user_id : null) . ";</script>";
// Note: For matching assigned_to_user_name, exposing session_user_name would be better, but requires $session_name to be consistently set.
// For now, JS will have to rely on ID if SSE payload for assigned_to_user_id is added later, or implement name matching carefully.
?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        console.log("Attempting to connect to SSE for ticket updates...");
        const evtSource = new EventSource("sse_ticket_updates.php?rand=" + Math.random());

        evtSource.onopen = function() {
            console.log("SSE connection opened.");
        };

        evtSource.addEventListener("new_ticket", function(event) {
            try {
                const ticketData = JSON.parse(event.data);
                console.log("New ticket received:", ticketData);
                addNewTicketToUI(ticketData);
                updateTicketCounters(ticketData); // Call the new counter function
            } catch (e) {
                console.error("Error parsing new ticket data:", e);
                console.log("Received raw data:", event.data);
            }
        });

        evtSource.addEventListener("error", function(event) {
            console.error("EventSource failed:", event);
            if (evtSource.readyState == EventSource.CLOSED) {
                console.log("SSE connection was closed.");
            }
        });

        evtSource.onmessage = function(event) {
            if (event.data.startsWith(": heartbeat")) {
                console.log("SSE Heartbeat received");
            } else if (event.data.startsWith("event: error")) {
                 console.error("Received custom error event from server:", event.data);
            } else if (event.data && event.data.trim() !== "") {
                console.log("SSE generic message:", event.data);
            }
        };

        window.addEventListener('beforeunload', function() {
            if (evtSource) {
                evtSource.close();
                console.log("SSE connection closed due to page unload.");
            }
        });

        function getCurrentView() {
            const urlParams = new URLSearchParams(window.location.search);
            const viewParam = urlParams.get('view');
            if (viewParam) return viewParam;
            return window.DEFAULT_TICKET_VIEW || 'list';
        }

        function addNewTicketToUI(ticketData) {
            const currentView = getCurrentView();
            console.log("Current view detected:", currentView);

            switch (currentView) {
                case 'list':
                    renderTicketInListView(ticketData);
                    break;
                case 'compact':
                    renderTicketInCompactView(ticketData);
                    break;
                case 'kanban':
                    if (ticketData.ticket_status_name && ticketData.ticket_status_name.toLowerCase() !== 'closed' && ticketData.ticket_status_name.toLowerCase() !== 'resolved') {
                        renderTicketInKanbanView(ticketData);
                    } else {
                        console.log("New ticket is closed/resolved, not adding to Kanban view:", ticketData.ticket_status_name);
                    }
                    break;
                default:
                    console.warn("Unknown view or view not requiring real-time updates:", currentView, "- defaulting to list view if list container exists.");
                    if (document.querySelector('#ticket-list-view table.table-striped tbody')) {
                        renderTicketInListView(ticketData);
                    }
                    break;
            }
        }

        function getPriorityColor(priority) {
            if (!priority) return 'secondary';
            switch (priority.toLowerCase()) {
                case 'high': return 'danger';
                case 'medium': return 'warning';
                case 'low': return 'info';
                default: return 'secondary';
            }
        }

        function escapeHTML(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/[&<>"']/g, function (match) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                }[match];
            });
        }

        function renderTicketInListView(ticketData) {
            const tableBody = document.querySelector('#ticket-list-view table.table-striped tbody');
            if (!tableBody) {
                if (getCurrentView() === 'list') console.error("List view: Table body ('#ticket-list-view table.table-striped tbody') not found.");
                return;
            }

            const newRow = document.createElement('tr');
            newRow.classList.add('text-bold');
            const isClientView = window.CURRENT_USER_IS_CLIENT_VIEW;
            const lastResponseUser = escapeHTML(ticketData.assigned_to_user_name || ticketData.created_by_user_name || 'N/A');

            newRow.innerHTML = `
                <td><div class="form-check"><input class="form-check-input bulk-select" type="checkbox" name="ticket_ids[]" value="${escapeHTML(ticketData.ticket_id)}"></div></td>
                <td><a href="${escapeHTML(ticketData.url)}"><span class="badge badge-pill badge-secondary p-3">${escapeHTML(ticketData.ticket_prefix)}${escapeHTML(ticketData.ticket_number)}</span></a></td>
                <td><a href="${escapeHTML(ticketData.url)}">${escapeHTML(ticketData.ticket_subject)}</a></td>
                <td>${isClientView ? `<div>${escapeHTML(ticketData.contact_name)}</div>` : `<a href="clients.php?client_id=${escapeHTML(ticketData.client_id)}"><strong>${escapeHTML(ticketData.client_name)}</strong></a><div>${escapeHTML(ticketData.contact_name)}</div>`}</td>
                <td><a href="#"><span class='p-2 badge badge-pill badge-${getPriorityColor(ticketData.ticket_priority)}'>${escapeHTML(ticketData.ticket_priority)}</span></a></td>
                <td><span class='badge badge-pill text-light p-2' style="background-color: ${escapeHTML(ticketData.ticket_status_color)};">${escapeHTML(ticketData.ticket_status_name)}</span></td>
                <td><a href="#">${escapeHTML(ticketData.assigned_to_user_name) || 'Not Assigned'}</a></td>
                <td><div title="${escapeHTML(ticketData.ticket_updated_at)}">${escapeHTML(ticketData.ticket_updated_at_time_ago)}</div><div>${lastResponseUser}</div></td>
                <td><div title="${escapeHTML(ticketData.ticket_created_at)}">${escapeHTML(ticketData.ticket_created_at_time_ago)}</div></td>
            `;

            tableBody.insertBefore(newRow, tableBody.firstChild);
            highlightAndManageEmptyState(newRow, tableBody, '#ticket-list-view table.table-striped thead');
        }

        function renderTicketInCompactView(ticketData) {
            const tableBody = document.querySelector('#ticket-compact-view table.table-striped tbody');
            if (!tableBody) {
                 if (getCurrentView() === 'compact') console.error("Compact view: Table body ('#ticket-compact-view table.table-striped tbody') not found.");
                return;
            }

            const newRow = document.createElement('tr');
            newRow.classList.add('text-bold');
            const isClientView = window.CURRENT_USER_IS_CLIENT_VIEW;

            // Compact view does not display timeAgo fields in rows, structure remains the same.
            newRow.innerHTML = `
                <td><div class="form-check"><input class="form-check-input bulk-select" type="checkbox" name="ticket_ids[]" value="${escapeHTML(ticketData.ticket_id)}"></div></td>
                <td>
                    <div class="mt-1"><span class='badge badge-${getPriorityColor(ticketData.ticket_priority)}'>${escapeHTML(ticketData.ticket_priority)}</span></div>
                    <a href="${escapeHTML(ticketData.url)}">${escapeHTML(ticketData.ticket_subject)}</a>
                </td>
                <td>${isClientView ? `<div>${escapeHTML(ticketData.contact_name)}</div>` : `<a href="clients.php?client_id=${escapeHTML(ticketData.client_id)}"><strong>${escapeHTML(ticketData.client_name)}</strong></a><div>${escapeHTML(ticketData.contact_name)}</div>`}</td>
                <td><span class='badge text-light p-2' style="background-color: ${escapeHTML(ticketData.ticket_status_color)};">${escapeHTML(ticketData.ticket_status_name)}</span></td>
                <td><a href="#">${escapeHTML(ticketData.assigned_to_user_name) || 'Not Assigned'}</a></td>
            `;

            tableBody.insertBefore(newRow, tableBody.firstChild);
            highlightAndManageEmptyState(newRow, tableBody, '#ticket-compact-view table.table-striped thead');
        }

        function renderTicketInKanbanView(ticketData) {
            const kanbanColumnContent = document.querySelector(`#kanban-board .kanban-column[data-status-id="${ticketData.ticket_status_id}"] .kanban-status`);
            if (!kanbanColumnContent) {
                if (getCurrentView() === 'kanban') console.warn(`Kanban column for status ID ${ticketData.ticket_status_id} not found! Ticket: "${escapeHTML(ticketData.ticket_subject)}". This ticket will not be displayed in Kanban view in real-time.`);
                return;
            }

            const newCard = document.createElement('div');
            newCard.classList.add('task', 'grab-cursor');
            newCard.setAttribute('data-ticket-id', ticketData.ticket_id);
            newCard.setAttribute('data-ticket-status-id', ticketData.ticket_status_id);
            newCard.ondblclick = function() { window.location.href = ticketData.url; };

            const clientDisplay = window.CURRENT_USER_IS_CLIENT_VIEW ? escapeHTML(ticketData.contact_name) : `${escapeHTML(ticketData.client_name)}${ticketData.contact_name ? ' - ' + escapeHTML(ticketData.contact_name) : ''}`;

            newCard.innerHTML = `
                <span class='badge badge-${getPriorityColor(ticketData.ticket_priority)}'>${escapeHTML(ticketData.ticket_priority)}</span>
                <span class='badge badge-secondary'>${escapeHTML(ticketData.category_name)}</span>
                <br>
                <b>${clientDisplay}</b>
                <br>
                <i class="fa fa-fw fa fa-life-ring text-secondary mr-2"></i>${escapeHTML(ticketData.ticket_subject)}
                <br>
                <i class="fas fa-fw fa-user mr-2 text-secondary"></i>${escapeHTML(ticketData.assigned_to_user_name) || 'N/A'}
                <br>
                <small class="text-muted">Created: ${escapeHTML(ticketData.ticket_created_at_time_ago)}</small>
                <br>
                <small class="text-muted">Updated: ${escapeHTML(ticketData.ticket_updated_at_time_ago)}</small>
            `;

            kanbanColumnContent.insertBefore(newCard, kanbanColumnContent.firstChild);

            const placeholder = kanbanColumnContent.querySelector('.empty-placeholder');
            if (placeholder) {
                placeholder.remove();
            }

            highlightAndManageEmptyState(newCard, null, null);
        }

        function highlightAndManageEmptyState(newElement, tableBodyOrParentContainer, tableHeadSelector) {
            if (newElement) {
                newElement.style.backgroundColor = '#FFFFE0';
                setTimeout(() => {
                    if (newElement) newElement.style.backgroundColor = '';
                }, 3000);
            }

            if (tableBodyOrParentContainer) {
                const noItemsRow = tableBodyOrParentContainer.querySelector('tr td[colspan]');
                if (noItemsRow && (noItemsRow.textContent.includes("No tickets found") || noItemsRow.textContent.includes("No items found"))) {
                    noItemsRow.parentElement.remove();
                }
            }

            if (tableHeadSelector) {
                 const tableHead = document.querySelector(tableHeadSelector);
                 if (tableHead && tableHead.classList.contains('d-none')) {
                     tableHead.classList.remove('d-none');
                 }
            }
        }

        function updateTicketCounters(ticketData) {
            // Helper to update a counter
            function incrementCounter(elementId) {
                const element = document.getElementById(elementId);
                if (element) {
                    let currentCount = parseInt(element.textContent, 10);
                    if (!isNaN(currentCount)) {
                        element.textContent = currentCount + 1;
                    }
                } else {
                    console.warn(`Counter element with ID '${elementId}' not found.`);
                }
            }

            const isTicketOpen = ticketData.ticket_status_name &&
                                 ticketData.ticket_status_name.toLowerCase() !== 'closed' &&
                                 ticketData.ticket_status_name.toLowerCase() !== 'resolved';

            if (isTicketOpen) {
                incrementCounter('total-tickets-open-count');

                // Check for 'assigned_to_user_id' in payload. If not present, this part won't work.
                // It was not explicitly in the reported payload of sse_ticket_updates.php
                if (ticketData.assigned_to_user_id === undefined) {
                    console.warn("SSE payload missing 'assigned_to_user_id'. 'My Active Tickets' and 'Unassigned' counters might be inaccurate.");
                }

                if (ticketData.assigned_to_user_id === null || ticketData.assigned_to_user_id === 0 || ticketData.assigned_to_user_id === "0") {
                     // Also check name as a fallback, though ID is preferred
                    if (ticketData.assigned_to_user_name === 'Not Assigned' || !ticketData.assigned_to_user_name) {
                         incrementCounter('total-tickets-unassigned-count');
                    }
                }

                if (window.SESSION_USER_ID && ticketData.assigned_to_user_id === window.SESSION_USER_ID) {
                    incrementCounter('user-active-assigned-tickets-count');
                }
            }
            // No need to increment 'total-tickets-closed-count' for new tickets via SSE
        }

    });
</script>

<?php
require_once "modals/ticket_add_modal.php";
require_once "modals/ticket_export_modal.php";
require_once "includes/footer.php";