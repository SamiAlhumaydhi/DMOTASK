<?php
require 'vendor/autoload.php'; // Ensure this is pointing to the correct location

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "DMO";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// Function to handle CSV upload and insert data into the database
function importCSV($conn) {
    $file = $_FILES['file']['tmp_name'];
    $handle = fopen($file, "r");

    // Check if file is UTF-8 encoded
    $bom = fread($handle, 3);
    if ($bom != "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $header = fgetcsv($handle, 1000, ",");
    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
        // Parse and convert dates
        $communication_date = date("Y-m-d", strtotime($row[8]));
        $expected_completion_date = date("Y-m-d", strtotime($row[9]));
        $last_update_date = date("Y-m-d", strtotime($row[11]));
        
        // Insert data into the database
        $sql = "INSERT INTO tasks (entity_name, task_type, task_title, office_responsibility, status, priority, bank_responsibility, communication_date, expected_completion_date, action, last_update_date, notes, actual_completion_date, email_title) VALUES (
            '".mysqli_real_escape_string($conn, $row[1])."',
            '".mysqli_real_escape_string($conn, $row[2])."',
            '".mysqli_real_escape_string($conn, $row[3])."',
            '".mysqli_real_escape_string($conn, $row[4])."',
            '".mysqli_real_escape_string($conn, $row[5])."',
            '".mysqli_real_escape_string($conn, $row[6])."',
            '".mysqli_real_escape_string($conn, $row[7])."',
            '".mysqli_real_escape_string($conn, $communication_date)."',
            '".mysqli_real_escape_string($conn, $expected_completion_date)."',
            '".mysqli_real_escape_string($conn, $row[10])."',
            '".mysqli_real_escape_string($conn, $last_update_date)."',
            '".mysqli_real_escape_string($conn, $row[12])."',
            '".mysqli_real_escape_string($conn, $row[13])."',
            '".mysqli_real_escape_string($conn, $row[14])."'
        )";
        $conn->query($sql);
    }
    fclose($handle);
}

// Handle file upload
if (isset($_POST['import'])) {
    importCSV($conn);
    echo "<script>alert('Import successful');</script>";
}

// Handle data update
if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $column = $_POST['column'];
    $newValue = $_POST['value'];
    $user = 'System'; // You can replace this with actual user identification if needed

    // Fetch the old value
    $sql = "SELECT $column FROM tasks WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($oldValue);
    $stmt->fetch();
    $stmt->close();

    // Update the value
    $sql = "UPDATE tasks SET $column = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $newValue, $id);
    $stmt->execute();
    $stmt->close();

    // Log the update
    $sql = "INSERT INTO update_logs (task_id, column_name, old_value, new_value, updated_by) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('issss', $id, $column, $oldValue, $newValue, $user);
    $stmt->execute();
    $stmt->close();
}

// Handle fetching logs
if (isset($_POST['fetch_logs'])) {
    $sql = "SELECT * FROM update_logs ORDER BY updated_at DESC";
    $result = $conn->query($sql);

    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    echo json_encode($logs);
    exit();
}

// Handle export to Excel
if (isset($_POST['export'])) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Tasks');

    // Add headers
    $headers = ['ID', 'Entity Name', 'Task Type', 'Task Title', 'Office Responsibility', 'Status', 'Priority', 'Bank Responsibility', 'Communication Date', 'Expected Completion Date', 'Action', 'Last Update Date', 'Notes', 'Actual Completion Date', 'Email Title'];
    $sheet->fromArray($headers, NULL, 'A1');

    // Fetch data
    $sql = "SELECT 
                id, entity_name, task_type, task_title, office_responsibility, status, priority, bank_responsibility,
                DATE_FORMAT(communication_date, '%Y-%m-%d') as communication_date,
                DATE_FORMAT(expected_completion_date, '%Y-%m-%d') as expected_completion_date,
                action, DATE_FORMAT(last_update_date, '%Y-%m-%d') as last_update_date,
                notes, actual_completion_date, email_title
            FROM tasks";
    $result = $conn->query($sql);

    // Add data to sheet
    $rowNumber = 2; // Start from row 2
    while ($row = $result->fetch_assoc()) {
        $sheet->fromArray($row, NULL, 'A' . $rowNumber);
        $rowNumber++;
    }

    // Save to file
    $writer = new Xlsx($spreadsheet);
    $filename = 'tasks_export.xlsx';
    $writer->save($filename);

    // Serve the file to the user
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    readfile($filename);

    // Clean up
    unlink($filename);
    exit();
}

// Fetch data with formatted dates
$sql = "SELECT 
            id, entity_name, task_type, task_title, office_responsibility, status, priority, bank_responsibility,
            DATE_FORMAT(communication_date, '%Y-%m-%d') as communication_date,
            DATE_FORMAT(expected_completion_date, '%Y-%m-%d') as expected_completion_date,
            action, DATE_FORMAT(last_update_date, '%Y-%m-%d') as last_update_date,
            notes, actual_completion_date, email_title
        FROM tasks";
$result = $conn->query($sql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DMO Dashboard</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-image: url('background.jpg');
            background-attachment: fixed;
            background-size: cover;
            background-repeat: no-repeat;
        }

        .container {
            background-color: rgb(241, 236, 236);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 4px rgba(37, 36, 36, 0.966);
            width: 100%;
            max-width: 1500px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            user-select: none;
        }

        @media (max-width: 768px) {
            .container {
                width: 90%;
                max-width: 400px;
            }
        }

        h1 {
            font-size: 25px;
            margin-top: 10px;
            margin-bottom: 20px;
        }

        hr {
            width: 100%;
            border: 1px solid #ccc;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            padding: 10px;
            border: 1px solid #ccc;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
        }

        button {
            background-color: rgb(155, 15, 15);
            color: white;
            font-size: 20px;
            width: 100%;
            height: 50px;
            border: 1.5px solid #3d3d3d;
            cursor: pointer;
            border-radius: 10px;
            margin-top: 20px;
        }

        button:hover {
            background-color: rgb(107, 2, 2);
        }

        #upload-form {
            margin-top: 20px;
            margin-bottom: 20px;
        }

        textarea {
            width: 100%;
            height: 100px;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgb(0,0,0);
            background-color: rgba(0,0,0,0.4);
            padding-top: 60px;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>DMO Dashboard</h1>
        <hr>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Entity Name</th>
                    <th>Task Type</th>
                    <th>Task Title</th>
                    <th>Office Responsibility</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>Bank Responsibility</th>
                    <th>Communication Date</th>
                    <th>Expected Completion Date</th>
                    <th>Action</th>
                    <th>Last Update Date</th>
                    <th>Notes</th>
                    <th>Actual Completion Date</th>
                    <th>Email Title</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                <tr data-id="<?php echo $row['id']; ?>">
                    <td><?php echo $row['id']; ?></td>
                    <td class="editable" data-column="entity_name"><?php echo htmlspecialchars($row['entity_name']); ?></td>
                    <td class="editable" data-column="task_type"><?php echo htmlspecialchars($row['task_type']); ?></td>
                    <td class="editable" data-column="task_title"><?php echo htmlspecialchars($row['task_title']); ?></td>
                    <td class="editable" data-column="office_responsibility"><?php echo htmlspecialchars($row['office_responsibility']); ?></td>
                    <td class="editable" data-column="status"><?php echo htmlspecialchars($row['status']); ?></td>
                    <td class="editable" data-column="priority"><?php echo htmlspecialchars($row['priority']); ?></td>
                    <td class="editable" data-column="bank_responsibility"><?php echo htmlspecialchars($row['bank_responsibility']); ?></td>
                    <td class="editable" data-column="communication_date"><?php echo htmlspecialchars($row['communication_date']); ?></td>
                    <td class="editable" data-column="expected_completion_date"><?php echo htmlspecialchars($row['expected_completion_date']); ?></td>
                    <td class="editable" data-column="action"><?php echo htmlspecialchars($row['action']); ?></td>
                    <td class="editable" data-column="last_update_date"><?php echo htmlspecialchars($row['last_update_date']); ?></td>
                    <td class="editable" data-column="notes"><?php echo htmlspecialchars($row['notes']); ?></td>
                    <td class="editable" data-column="actual_completion_date"><?php echo htmlspecialchars($row['actual_completion_date']); ?></td>
                    <td class="editable" data-column="email_title"><?php echo htmlspecialchars($row['email_title']); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <form id="upload-form" method="post" enctype="multipart/form-data">
            <input type="file" name="file" required>
            <button type="submit" name="import">Import Excel Data</button>
        </form>
        <button id="export-button">Export to Excel</button>
        <button id="view-logs-button">View Logs</button>
    </div>

    <!-- Modal for Logs -->
    <div id="logs-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Update Logs</h2>
            <table id="logs-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Task ID</th>
                        <th>Column</th>
                        <th>Old Value</th>
                        <th>New Value</th>
                        <th>Updated By</th>
                        <th>Updated At</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Logs will be populated here by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>

    <script>
        document.querySelectorAll('.editable').forEach(cell => {
            cell.addEventListener('dblclick', function() {
                if (this.querySelector('textarea')) return; // Prevent multiple textareas
                
                const originalContent = this.textContent;
                const textarea = document.createElement('textarea');
                textarea.value = originalContent;
                this.innerHTML = '';
                this.appendChild(textarea);
                textarea.focus();

                textarea.addEventListener('blur', function() {
                    const newValue = this.value;
                    const cell = this.parentElement;
                    const id = cell.parentElement.getAttribute('data-id');
                    const column = cell.getAttribute('data-column');

                    if (newValue !== originalContent) {
                        // Update database via AJAX
                        fetch('index.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: new URLSearchParams({
                                update: true,
                                id: id,
                                column: column,
                                value: newValue
                            })
                        }).then(response => {
                            if (response.ok) {
                                cell.textContent = newValue;
                            } else {
                                cell.textContent = originalContent;
                            }
                        }).catch(() => {
                            cell.textContent = originalContent;
                        });
                    } else {
                        cell.textContent = originalContent;
                    }
                });

                textarea.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        textarea.blur();
                    }
                });
            });
        });

        // Handle Export to Excel Button
        document.getElementById('export-button').addEventListener('click', function() {
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    export: true
                })
            }).then(response => response.blob())
              .then(blob => {
                  const link = document.createElement('a');
                  link.href = window.URL.createObjectURL(blob);
                  link.download = 'tasks_export.xlsx';
                  link.click();
              });
        });

        // Handle View Logs Button
        document.getElementById('view-logs-button').addEventListener('click', function() {
            fetch('index.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    fetch_logs: true
                })
            }).then(response => response.json())
              .then(logs => {
                  const logsTableBody = document.querySelector('#logs-table tbody');
                  logsTableBody.innerHTML = '';
                  logs.forEach(log => {
                      const row = document.createElement('tr');
                      row.innerHTML = `
                          <td>${log.id}</td>
                          <td>${log.task_id}</td>
                          <td>${log.column_name}</td>
                          <td>${log.old_value}</td>
                          <td>${log.new_value}</td>
                          <td>${log.updated_by}</td>
                          <td>${log.updated_at}</td>
                      `;
                      logsTableBody.appendChild(row);
                  });
                  document.getElementById('logs-modal').style.display = 'block';
              });
        });

        // Handle Modal Close
        document.querySelector('.modal .close').addEventListener('click', function() {
            document.getElementById('logs-modal').style.display = 'none';
        });

        window.addEventListener('click', function(event) {
            if (event.target === document.getElementById('logs-modal')) {
                document.getElementById('logs-modal').style.display = 'none';
            }
        });
    </script>
</body>
</html>
