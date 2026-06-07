<?php
require_once '../db.php';
$search = $_POST['query'] ?? '';
$sql = "SELECT * FROM users WHERE username LIKE '%$search%' OR email LIKE '%$search%' ORDER BY id DESC";
$result = $conn->query($sql);

while($row = $result->fetch_assoc()) {
    echo "<tr>
            <td>{$row['username']}<br><small>{$row['email']}</small></td>
            <td><span class='role-badge badge-{$row['role']}'>{$row['role']}</span></td>
            <td>" . date('d/m/Y', strtotime($row['created_at'])) . "</td>
            <td class='text-end'>
                <button onclick='editUser(".json_encode($row).")' class='btn btn-sm btn-light border'><i class='fas fa-edit'></i></button>
            </td>
          </tr>";
}
?>